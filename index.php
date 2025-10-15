<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2025 grommunio GmbH
 *
 * This is the entry point through which all requests are processed.
 */

ob_start(null, 1048576);

// ignore user abortions because this can lead to weird errors
ignore_user_abort(true);

require_once 'vendor/autoload.php';

if (!defined('GSYNC_CONFIG')) {
	define('GSYNC_CONFIG', 'config.php');
}

include_once GSYNC_CONFIG;

// Attempt to set maximum execution time
ini_set('max_execution_time', SCRIPT_TIMEOUT);
set_time_limit(SCRIPT_TIMEOUT);

try {
	// check config & initialize the basics
	GSync::CheckConfig();
	Request::Initialize();
	SLog::Initialize();

	SLog::Write(LOGLEVEL_DEBUG, "-------- Start");
	SLog::Write(
		LOGLEVEL_DEBUG,
		sprintf(
			"cmd='%s' devType='%s' devId='%s' getUser='%s' from='%s' version='%s' method='%s'",
			Request::GetCommand(),
			Request::GetDeviceType(),
			Request::GetDeviceID(),
			Request::GetGETUser(),
			Request::GetRemoteAddr(),
			@constant('GROMMUNIOSYNC_VERSION'),
			Request::GetMethod()
		)
	);

	// always request the authorization header
	if (!Request::HasAuthenticationInfo() || !Request::GetGETUser()) {
		throw new AuthenticationRequiredException("Access denied. Please send authorisation information");
	}

	GSync::CheckAdvancedConfig();

	// Process request headers and look for AS headers
	Request::ProcessHeaders();

	// Stop here if this is an OPTIONS request
	if (Request::IsMethodOPTIONS()) {
		RequestProcessor::Authenticate();

		throw new NoPostRequestException("Options request", NoPostRequestException::OPTIONS_REQUEST);
	}

	// Check required GET parameters
	if (Request::IsMethodPOST() && (Request::GetCommandCode() === false || !Request::GetDeviceID() || !Request::GetDeviceType())) {
		throw new FatalException("Requested the grommunio-sync URL without the required GET parameters");
	}

	// Load the backend
	$backend = GSync::GetBackend();

	// check the provisioning information
	if (
		PROVISIONING === true &&
		Request::IsMethodPOST() &&
		GSync::CommandNeedsProvisioning(Request::GetCommandCode()) &&
		(
			(Request::WasPolicyKeySent() && Request::GetPolicyKey() == 0) ||
			GSync::GetProvisioningManager()->ProvisioningRequired(Request::GetPolicyKey())
		) && (
			LOOSE_PROVISIONING === false ||
			(LOOSE_PROVISIONING === true && Request::WasPolicyKeySent())
		)) {
		// TODO for AS 14 send a wbxml response
		throw new ProvisioningRequiredException();
	}

	// most commands require an authenticated user
	if (GSync::CommandNeedsAuthentication(Request::GetCommandCode())) {
		RequestProcessor::Authenticate();
	}

	// Do the actual processing of the request
	if (Request::IsMethodGET()) {
		throw new NoPostRequestException("This is the grommunio-sync location and can only be accessed by Microsoft ActiveSync-capable devices", NoPostRequestException::GET_REQUEST);
	}

	// Do the actual request
	header(GSync::GetServerHeader());

	if (RequestProcessor::isUserAuthenticated()) {
		header("X-Grommunio-Sync-Version: " . @constant('GROMMUNIOSYNC_VERSION'));
		GSync::TrackConnection();

		// announce the supported AS versions (if not already sent to device)
		if (GSync::GetDeviceManager()->AnnounceASVersion()) {
			$versions = GSync::GetSupportedProtocolVersions(true);
			SLog::Write(LOGLEVEL_INFO, sprintf("Announcing latest AS version to device: %s", $versions));
			header("X-MS-RP: " . $versions);
		}
	}

	RequestProcessor::Initialize();
	RequestProcessor::HandleRequest();

	// eventually the RequestProcessor wants to send other headers to the mobile
	foreach (RequestProcessor::GetSpecialHeaders() as $header) {
		SLog::Write(LOGLEVEL_DEBUG, sprintf("Special header: %s", $header));
		header($header);
	}

	// stream the data
	$len = ob_get_length();
	$data = ob_get_contents();
	ob_end_clean();

	// log amount of data transferred
	// TODO check $len when streaming more data (e.g. Attachments), as the data will be send chunked
	if (GSync::GetDeviceManager(false)) {
		GSync::GetDeviceManager()->SentData($len);
	}

	// Unfortunately, even though grommunio-sync can stream the data to the client
	// with a chunked encoding, using chunked encoding breaks the progress bar
	// on the PDA. So the data is de-chunk here, written a content-length header and
	// data send as a 'normal' packet. If the output packet exceeds 1MB (see ob_start)
	// then it will be sent as a chunked packet anyway because PHP will have to flush
	// the buffer.
	if (!headers_sent()) {
		header("Content-Length: {$len}");
	}

	// send vnd.ms-sync.wbxml content type header if there is no content
	// otherwise text/html content type is added which might break some devices
	if (!headers_sent() && $len == 0) {
		header("Content-Type: application/vnd.ms-sync.wbxml");
	}

	echo $data;

	// destruct backend after all data is on the stream
	$backend->Logoff();
}
catch (NoPostRequestException $nopostex) {
	if ($nopostex->getCode() == NoPostRequestException::OPTIONS_REQUEST) {
		header(GSync::GetServerHeader());
		header(GSync::GetSupportedProtocolVersions());
		header(GSync::GetSupportedCommands());
		header("X-AspNet-Version: 4.0.30319");
		SLog::Write(LOGLEVEL_INFO, $nopostex->getMessage());
	}
	elseif ($nopostex->getCode() == NoPostRequestException::GET_REQUEST) {
		if (Request::GetUserAgent()) {
			SLog::Write(LOGLEVEL_INFO, sprintf("User-agent: '%s'", Request::GetUserAgent()));
		}
		if (!headers_sent() && $nopostex->showLegalNotice()) {
			GSync::PrintGrommunioSyncLegal('GET not supported', $nopostex->getMessage());
		}
	}
}
catch (Exception $ex) {
	// Extract any previous exception message for logging purpose.
	$exclass = $ex::class;
	$exception_message = $ex->getMessage();
	if ($ex->getPrevious()) {
		do {
			$current_exception = $ex->getPrevious();
			$exception_message .= ' -> ' . $current_exception->getMessage();
		}
		while ($current_exception->getPrevious());
	}

	if (Request::GetUserAgent()) {
		SLog::Write(LOGLEVEL_INFO, sprintf("User-agent: '%s'", Request::GetUserAgent()));
	}

	SLog::Write(LOGLEVEL_FATAL, sprintf('Exception: (%s) - %s', $exclass, $exception_message));

	if (!headers_sent()) {
		if ($ex instanceof GSyncException) {
			header('HTTP/1.1 ' . $ex->getHTTPCodeString());
			foreach ($ex->getHTTPHeaders() as $h) {
				header($h);
			}
		}
		// something really unexpected happened!
		else {
			header('HTTP/1.1 500 Internal Server Error');
		}
	}

	if ($ex instanceof AuthenticationRequiredException) {
		// Only print GSync legal message for GET requests because
		// some devices send unauthorized OPTIONS requests
		// and don't expect anything in the response body
		if (Request::IsMethodGET()) {
			GSync::PrintGrommunioSyncLegal($exclass, sprintf('<pre>%s</pre>', $ex->getMessage()));
		}

		// log the failed login attempt e.g. for fail2ban
		if (defined('LOGAUTHFAIL') && LOGAUTHFAIL !== false) {
			SLog::Write(LOGLEVEL_WARN, sprintf("IP: %s failed to authenticate user '%s'", Request::GetRemoteAddr(), Request::GetAuthUser() ?: Request::GetGETUser()));
		}
	}

	// This could be a WBXML problem.. try to get the complete request
	elseif ($ex instanceof WBXMLException) {
		SLog::Write(LOGLEVEL_FATAL, "Request could not be processed correctly due to a WBXMLException. Please report this including the 'WBXML debug data' logged. Be aware that the debug data could contain confidential information.");
	}

	// Try to output some kind of error information. This is only possible if
	// the output had not started yet. If it has started already, we can't show the user the error, and
	// the device will give its own (useless) error message.
	elseif (!($ex instanceof GSyncException) || $ex->showLegalNotice()) {
		$cmdinfo = (Request::GetCommand()) ? sprintf(" processing command <i>%s</i>", Request::GetCommand()) : "";
		$extrace = $ex->getTrace();
		$trace = (!empty($extrace)) ? "\n\nTrace:\n" . print_r($extrace, 1) : "";
		GSync::PrintGrommunioSyncLegal($exclass . $cmdinfo, sprintf('<pre>%s</pre>', $ex->getMessage() . $trace));
	}

	// Announce exception to process loop detection
	if (GSync::GetDeviceManager(false)) {
		GSync::GetDeviceManager()->AnnounceProcessException($ex);
	}

	// Announce exception if the TopCollector if available
	GSync::GetTopCollector()->AnnounceInformation($ex::class, true);
}

// save device data if the DeviceManager is available
if (GSync::GetDeviceManager(false)) {
	GSync::GetDeviceManager()->Save();
}

// end gracefully
SLog::Write(
	LOGLEVEL_INFO,
	sprintf(
		"cmd='%s' memory='%s/%s' time='%ss' devType='%s' devId='%s' getUser='%s' from='%s' idle='%ss' version='%s' method='%s' httpcode='%s'",
		Request::GetCommand(),
		Utils::FormatBytes(memory_get_peak_usage(false)),
		Utils::FormatBytes(memory_get_peak_usage(true)),
		number_format(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 2),
		Request::GetDeviceType(),
		Request::GetDeviceID(),
		Request::GetGETUser(),
		Request::GetRemoteAddr(),
		RequestProcessor::GetWaitTime(),
		@constant('GROMMUNIOSYNC_VERSION'),
		Request::GetMethod(),
		http_response_code()
	)
);

SLog::Write(LOGLEVEL_DEBUG, "-------- End");
