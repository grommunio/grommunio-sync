<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * This class checks and processes all incoming data of the request.
 */

class Request {
	public const MAXMEMORYUSAGE = 0.9;     // use max. 90% of allowed memory when syncing
	public const UNKNOWN = "unknown";
	public const IMPERSONATE_DELIM = '#';

	/**
	 * self::filterEvilInput() options.
	 */
	public const LETTERS_ONLY = 1;
	public const HEX_ONLY = 2;
	public const WORDCHAR_ONLY = 3;
	public const NUMBERS_ONLY = 4;
	public const NUMBERSDOT_ONLY = 5;
	public const HEX_EXTENDED = 6;
	public const ISO8601 = 7;
	public const HEX_EXTENDED2 = 8;

	/**
	 * Command parameters for base64 encoded requests (AS >= 12.1).
	 */
	public const COMMANDPARAM_ATTACHMENTNAME = 0;
	public const COMMANDPARAM_COLLECTIONID = 1; // deprecated
	public const COMMANDPARAM_COLLECTIONNAME = 2; // deprecated
	public const COMMANDPARAM_ITEMID = 3;
	public const COMMANDPARAM_LONGID = 4;
	public const COMMANDPARAM_PARENTID = 5; // deprecated
	public const COMMANDPARAM_OCCURRENCE = 6;
	public const COMMANDPARAM_OPTIONS = 7; // used by SmartReply, SmartForward, SendMail, ItemOperations
	public const COMMANDPARAM_USER = 8; // used by any command
	// possible bitflags for COMMANDPARAM_OPTIONS
	public const COMMANDPARAM_OPTIONS_SAVEINSENT = 0x01;
	public const COMMANDPARAM_OPTIONS_ACCEPTMULTIPART = 0x02;

	private static $input;
	private static $output;
	private static $headers;
	private static $command;
	private static $method;
	private static $remoteAddr;
	private static $getUser;
	private static $devid;
	private static $devtype;
	private static $authUserString;
	private static $authUser;
	private static $authDomain;
	private static $authPassword;
	private static $impersonatedUser;
	private static $asProtocolVersion;
	private static $policykey;
	private static $useragent;
	private static $attachmentName;
	private static $collectionId;
	private static $itemId;
	private static $longId; // TODO
	private static $occurrence; // TODO
	private static $saveInSent;
	private static $acceptMultipart;
	private static $base64QueryDecoded;
	private static $expectedConnectionTimeout;
	private static $memoryLimit;

	/**
	 * Initializes request data.
	 *
	 * @return
	 */
	public static function Initialize() {
		// try to open stdin & stdout
		self::$input = fopen("php://input", "r");
		self::$output = fopen("php://output", "w+");

		// Parse the standard GET parameters
		if (isset($_GET["Cmd"])) {
			self::$command = self::filterEvilInput($_GET["Cmd"], self::LETTERS_ONLY);
		}

		// getUser is unfiltered, as everything is allowed.. even "/", "\" or ".."
		if (isset($_GET["User"])) {
			self::$getUser = strtolower($_GET["User"]);
			if (defined('USE_FULLEMAIL_FOR_LOGIN') && !USE_FULLEMAIL_FOR_LOGIN) {
				self::$getUser = Utils::GetLocalPartFromEmail(self::$getUser);
			}
		}
		if (isset($_GET["DeviceId"])) {
			self::$devid = strtolower(self::filterEvilInput($_GET["DeviceId"], self::WORDCHAR_ONLY));
		}
		if (isset($_GET["DeviceType"])) {
			self::$devtype = self::filterEvilInput($_GET["DeviceType"], self::LETTERS_ONLY);
		}
		if (isset($_GET["AttachmentName"])) {
			self::$attachmentName = self::filterEvilInput($_GET["AttachmentName"], self::HEX_EXTENDED2);
		}
		if (isset($_GET["CollectionId"])) {
			self::$collectionId = self::filterEvilInput($_GET["CollectionId"], self::HEX_EXTENDED2);
		}
		if (isset($_GET["ItemId"])) {
			self::$itemId = self::filterEvilInput($_GET["ItemId"], self::HEX_EXTENDED2);
		}
		if (isset($_GET["SaveInSent"]) && $_GET["SaveInSent"] == "T") {
			self::$saveInSent = true;
		}

		if (isset($_SERVER["REQUEST_METHOD"])) {
			self::$method = self::filterEvilInput($_SERVER["REQUEST_METHOD"], self::LETTERS_ONLY);
		}
		// TODO check IPv6 addresses
		if (isset($_SERVER["REMOTE_ADDR"])) {
			self::$remoteAddr = self::filterIP($_SERVER["REMOTE_ADDR"]);
		}

		// in protocol version > 14 mobile send these inputs as encoded query string
		if (!isset(self::$command) && !empty($_SERVER['QUERY_STRING']) && Utils::IsBase64String($_SERVER['QUERY_STRING'])) {
			self::decodeBase64URI();
			if (!isset(self::$command) && isset(self::$base64QueryDecoded['Command'])) {
				self::$command = Utils::GetCommandFromCode(self::$base64QueryDecoded['Command']);
			}

			if (!isset(self::$getUser) && isset(self::$base64QueryDecoded[self::COMMANDPARAM_USER])) {
				self::$getUser = strtolower(self::$base64QueryDecoded[self::COMMANDPARAM_USER]);
				if (defined('USE_FULLEMAIL_FOR_LOGIN') && !USE_FULLEMAIL_FOR_LOGIN) {
					self::$getUser = Utils::GetLocalPartFromEmail(self::$getUser);
				}
			}

			if (!isset(self::$devid) && isset(self::$base64QueryDecoded['DevID'])) {
				self::$devid = strtolower(self::filterEvilInput(self::$base64QueryDecoded['DevID'], self::WORDCHAR_ONLY));
			}

			if (!isset(self::$devtype) && isset(self::$base64QueryDecoded['DevType'])) {
				self::$devtype = self::filterEvilInput(self::$base64QueryDecoded['DevType'], self::LETTERS_ONLY);
			}

			if (isset(self::$base64QueryDecoded['PolKey'])) {
				self::$policykey = (int) self::filterEvilInput(self::$base64QueryDecoded['PolKey'], self::NUMBERS_ONLY);
			}

			if (isset(self::$base64QueryDecoded['ProtVer'])) {
				self::$asProtocolVersion = self::filterEvilInput(self::$base64QueryDecoded['ProtVer'], self::NUMBERS_ONLY) / 10;
			}

			if (isset(self::$base64QueryDecoded[self::COMMANDPARAM_ATTACHMENTNAME])) {
				self::$attachmentName = self::filterEvilInput(self::$base64QueryDecoded[self::COMMANDPARAM_ATTACHMENTNAME], self::HEX_EXTENDED2);
			}

			if (isset(self::$base64QueryDecoded[self::COMMANDPARAM_COLLECTIONID])) {
				self::$collectionId = self::filterEvilInput(self::$base64QueryDecoded[self::COMMANDPARAM_COLLECTIONID], self::HEX_EXTENDED2);
			}

			if (isset(self::$base64QueryDecoded[self::COMMANDPARAM_ITEMID])) {
				self::$itemId = self::filterEvilInput(self::$base64QueryDecoded[self::COMMANDPARAM_ITEMID], self::HEX_EXTENDED2);
			}

			if (isset(self::$base64QueryDecoded[self::COMMANDPARAM_OPTIONS]) && (ord(self::$base64QueryDecoded[self::COMMANDPARAM_OPTIONS]) & self::COMMANDPARAM_OPTIONS_SAVEINSENT)) {
				self::$saveInSent = true;
			}

			if (isset(self::$base64QueryDecoded[self::COMMANDPARAM_OPTIONS]) && (ord(self::$base64QueryDecoded[self::COMMANDPARAM_OPTIONS]) & self::COMMANDPARAM_OPTIONS_ACCEPTMULTIPART)) {
				self::$acceptMultipart = true;
			}
		}

		// in base64 encoded query string user is not necessarily set
		if (!isset(self::$getUser) && isset($_SERVER['PHP_AUTH_USER'])) {
			list(self::$getUser) = Utils::SplitDomainUser(strtolower($_SERVER['PHP_AUTH_USER']));
			if (defined('USE_FULLEMAIL_FOR_LOGIN') && !USE_FULLEMAIL_FOR_LOGIN) {
				self::$getUser = Utils::GetLocalPartFromEmail(self::$getUser);
			}
		}

		// authUser & authPassword are unfiltered!
		// split username & domain if received as one
		if (isset($_SERVER['PHP_AUTH_USER'])) {
			list(self::$authUserString, self::$authDomain) = Utils::SplitDomainUser($_SERVER['PHP_AUTH_USER']);
			self::$authPassword = (isset($_SERVER['PHP_AUTH_PW'])) ? $_SERVER['PHP_AUTH_PW'] : "";
		}

		// process impersonation
		self::$authUser = self::$authUserString;

		if (defined('USE_FULLEMAIL_FOR_LOGIN') && !USE_FULLEMAIL_FOR_LOGIN) {
			self::$authUser = Utils::GetLocalPartFromEmail(self::$authUser);
		}

		// get & convert configured memory limit
		$memoryLimit = ini_get('memory_limit');
		if ($memoryLimit == -1) {
			self::$memoryLimit = false;
		}
		else {
			preg_replace_callback(
				'/(\-?\d+)(.?)/',
				function ($m) {
					self::$memoryLimit = $m[1] * pow(1024, strpos('BKMG', $m[2])) * self::MAXMEMORYUSAGE;
				},
				strtoupper($memoryLimit)
			);
		}
	}

	/**
	 * Reads and processes the request headers.
	 *
	 * @return
	 */
	public static function ProcessHeaders() {
		self::$headers = array_change_key_case(apache_request_headers(), CASE_LOWER);
		self::$useragent = (isset(self::$headers["user-agent"])) ? self::$headers["user-agent"] : self::UNKNOWN;
		if (!isset(self::$asProtocolVersion)) {
			self::$asProtocolVersion = (isset(self::$headers["ms-asprotocolversion"])) ? self::filterEvilInput(self::$headers["ms-asprotocolversion"], self::NUMBERSDOT_ONLY) : GSync::GetLatestSupportedASVersion();
		}

		// if policykey is not yet set, try to set it from the header
		// the policy key might be set in Request::Initialize from the base64 encoded query
		if (!isset(self::$policykey)) {
			if (isset(self::$headers["x-ms-policykey"])) {
				self::$policykey = (int) self::filterEvilInput(self::$headers["x-ms-policykey"], self::NUMBERS_ONLY);
			}
			else {
				self::$policykey = 0;
			}
		}

		if (isset(self::$base64QueryDecoded)) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("Request::ProcessHeaders(): base64 query string: '%s' (decoded: '%s')", $_SERVER['QUERY_STRING'], http_build_query(self::$base64QueryDecoded, '', ',')));
			if (isset(self::$policykey)) {
				self::$headers["x-ms-policykey"] = self::$policykey;
			}

			if (isset(self::$asProtocolVersion)) {
				self::$headers["ms-asprotocolversion"] = self::$asProtocolVersion;
			}
		}

		if (!isset(self::$acceptMultipart) && isset(self::$headers["ms-asacceptmultipart"]) && strtoupper(self::$headers["ms-asacceptmultipart"]) == "T") {
			self::$acceptMultipart = true;
		}

		SLog::Write(LOGLEVEL_DEBUG, sprintf("Request::ProcessHeaders() ASVersion: %s", self::$asProtocolVersion));

		if (defined('USE_CUSTOM_REMOTE_IP_HEADER') && USE_CUSTOM_REMOTE_IP_HEADER !== false) {
			// make custom header compatible with Apache modphp (see ZP-1332)
			$header = $apacheHeader = strtolower(USE_CUSTOM_REMOTE_IP_HEADER);
			if (substr($apacheHeader, 0, 5) === 'http_') {
				$apacheHeader = substr($apacheHeader, 5);
			}
			$apacheHeader = str_replace("_", "-", $apacheHeader);
			if (isset(self::$headers[$header]) || isset(self::$headers[$apacheHeader])) {
				$remoteIP = isset(self::$headers[$header]) ? self::$headers[$header] : self::$headers[$apacheHeader];
				// X-Forwarded-For may contain multiple IPs separated by comma: client, proxy1, proxy2.
				// In such case we will only check the client IP. See https://jira.z-hub.io/browse/ZP-1434.
				if (strpos($remoteIP, ',') !== false) {
					$remoteIP = trim(explode(',', $remoteIP)[0]);
				}
				$remoteIP = self::filterIP($remoteIP);
				if ($remoteIP) {
					SLog::Write(LOGLEVEL_DEBUG, sprintf("Using custom header '%s' to determine remote IP: %s - connect is coming from IP: %s", USE_CUSTOM_REMOTE_IP_HEADER, $remoteIP, self::$remoteAddr));
					self::$remoteAddr = $remoteIP;
				}
			}
		}
	}

	/**
	 * @return bool data sent or not
	 */
	public static function HasAuthenticationInfo() {
		return self::$authUser != "" && self::$authPassword != "";
	}

	/*----------------------------------------------------------------------------------------------------------
	 * Getter & Checker
	 */

	/**
	 * Returns the input stream.
	 *
	 * @return bool|handle false if not available
	 */
	public static function GetInputStream() {
		if (isset(self::$input)) {
			return self::$input;
		}

		return false;
	}

	/**
	 * Returns the output stream.
	 *
	 * @return bool|handle false if not available
	 */
	public static function GetOutputStream() {
		if (isset(self::$output)) {
			return self::$output;
		}

		return false;
	}

	/**
	 * Returns the request method.
	 *
	 * @return string
	 */
	public static function GetMethod() {
		if (isset(self::$method)) {
			return self::$method;
		}

		return self::UNKNOWN;
	}

	/**
	 * Returns the value of the user parameter of the querystring.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetGETUser() {
		if (isset(self::$getUser)) {
			return self::$getUser;
		}

		return self::UNKNOWN;
	}

	/**
	 * Returns the value of the ItemId parameter of the querystring.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetGETItemId() {
		if (isset(self::$itemId)) {
			return self::$itemId;
		}

		return false;
	}

	/**
	 * Returns the value of the CollectionId parameter of the querystring.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetGETCollectionId() {
		if (isset(self::$collectionId)) {
			return self::$collectionId;
		}

		return false;
	}

	/**
	 * Returns if the SaveInSent parameter of the querystring is set.
	 *
	 * @return bool
	 */
	public static function GetGETSaveInSent() {
		if (isset(self::$saveInSent)) {
			return self::$saveInSent;
		}

		return true;
	}

	/**
	 * Returns if the AcceptMultipart parameter of the querystring is set.
	 *
	 * @return bool
	 */
	public static function GetGETAcceptMultipart() {
		if (isset(self::$acceptMultipart)) {
			return self::$acceptMultipart;
		}

		return false;
	}

	/**
	 * Returns the value of the AttachmentName parameter of the querystring.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetGETAttachmentName() {
		if (isset(self::$attachmentName)) {
			return self::$attachmentName;
		}

		return false;
	}

	/**
	 * Returns user that is synchronizing data.
	 * If impersonation is active it returns the impersonated user,
	 * else the auth user.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetUser() {
		if (self::GetImpersonatedUser()) {
			return self::GetImpersonatedUser();
		}

		return self::GetAuthUser();
	}

	/**
	 * Returns the AuthUser string send by the client.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetAuthUserString() {
		if (isset(self::$authUserString)) {
			return self::$authUserString;
		}

		return false;
	}

	/**
	 * Returns the impersonated user. If not available, returns false.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetImpersonatedUser() {
		if (isset(self::$impersonatedUser)) {
			return self::$impersonatedUser;
		}

		return false;
	}

	/**
	 * Returns the authenticated user.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetAuthUser() {
		if (isset(self::$authUser)) {
			return self::$authUser;
		}

		return false;
	}

	/**
	 * Returns the authenticated domain for the user.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetAuthDomain() {
		if (isset(self::$authDomain)) {
			return self::$authDomain;
		}

		return false;
	}

	/**
	 * Returns the transmitted password.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetAuthPassword() {
		if (isset(self::$authPassword)) {
			return self::$authPassword;
		}

		return false;
	}

	/**
	 * Returns the RemoteAddress.
	 *
	 * @return string
	 */
	public static function GetRemoteAddr() {
		if (isset(self::$remoteAddr)) {
			return self::$remoteAddr;
		}

		return "UNKNOWN";
	}

	/**
	 * Returns the command to be executed.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetCommand() {
		if (isset(self::$command)) {
			return self::$command;
		}

		return false;
	}

	/**
	 * Returns the command code which is being executed.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetCommandCode() {
		if (isset(self::$command)) {
			return Utils::GetCodeFromCommand(self::$command);
		}

		return false;
	}

	/**
	 * Returns the device id transmitted.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetDeviceID() {
		if (isset(self::$devid)) {
			return self::$devid;
		}

		return false;
	}

	/**
	 * Returns the device type if transmitted.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetDeviceType() {
		if (isset(self::$devtype)) {
			return self::$devtype;
		}

		return false;
	}

	/**
	 * Returns the value of supported AS protocol from the headers.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetProtocolVersion() {
		if (isset(self::$asProtocolVersion)) {
			return self::$asProtocolVersion;
		}

		return false;
	}

	/**
	 * Returns the user agent sent in the headers.
	 *
	 * @return bool|string false if not available
	 */
	public static function GetUserAgent() {
		if (isset(self::$useragent)) {
			return self::$useragent;
		}

		return self::UNKNOWN;
	}

	/**
	 * Returns policy key sent by the device.
	 *
	 * @return bool|int false if not available
	 */
	public static function GetPolicyKey() {
		if (isset(self::$policykey)) {
			return self::$policykey;
		}

		return false;
	}

	/**
	 * Indicates if a policy key was sent by the device.
	 *
	 * @return bool
	 */
	public static function WasPolicyKeySent() {
		return isset(self::$headers["x-ms-policykey"]);
	}

	/**
	 * Indicates if grommunio-sync was called with a POST request.
	 *
	 * @return bool
	 */
	public static function IsMethodPOST() {
		return self::$method == "POST";
	}

	/**
	 * Indicates if grommunio-sync was called with a GET request.
	 *
	 * @return bool
	 */
	public static function IsMethodGET() {
		return self::$method == "GET";
	}

	/**
	 * Indicates if grommunio-sync was called with a OPTIONS request.
	 *
	 * @return bool
	 */
	public static function IsMethodOPTIONS() {
		return self::$method == "OPTIONS";
	}

	/**
	 * Sometimes strange device ids are submitted
	 * No device information should be saved when this happens.
	 *
	 * @return bool false if invalid
	 */
	public static function IsValidDeviceID() {
		if (self::GetDeviceID() === "validate") {
			return false;
		}

		return true;
	}

	/**
	 * Returns the amount of data sent in this request (from the headers).
	 *
	 * @return int
	 */
	public static function GetContentLength() {
		return (isset(self::$headers["content-length"])) ? (int) self::$headers["content-length"] : 0;
	}

	/**
	 * Returns the amount of seconds this request is able to be kept open without the client
	 * closing it. This depends on the vendor.
	 *
	 * @return bool
	 */
	public static function GetExpectedConnectionTimeout() {
		// Different vendors implement different connection timeouts.
		// In order to optimize processing, we return a specific time for the major
		// classes currently known (feedback welcome).
		// The amount of time returned is somehow lower than the max timeout so we have
		// time for processing.

		if (!isset(self::$expectedConnectionTimeout)) {
			// Apple and Windows Phone have higher timeouts (4min = 240sec)
			if (stripos(SYNC_TIMEOUT_LONG_DEVICETYPES, self::GetDeviceType()) !== false) {
				self::$expectedConnectionTimeout = 210;
			}
			// Samsung devices have a intermediate timeout (90sec)
			elseif (stripos(SYNC_TIMEOUT_MEDIUM_DEVICETYPES, self::GetDeviceType()) !== false) {
				self::$expectedConnectionTimeout = 85;
			}
			else {
				// for all other devices, a timeout of 30 seconds is expected
				self::$expectedConnectionTimeout = 28;
			}
		}

		return self::$expectedConnectionTimeout;
	}

	/**
	 * Indicates if the maximum timeout for the devicetype of this request is
	 * almost reached.
	 *
	 * @return bool
	 */
	public static function IsRequestTimeoutReached() {
		return (time() - $_SERVER["REQUEST_TIME"]) >= self::GetExpectedConnectionTimeout();
	}

	/**
	 * Indicates if the memory usage limit is almost reached.
	 * Processing should stop then to prevent hard out-of-memory issues.
	 * The threshold is hardcoded at 90% in Request::MAXMEMORYUSAGE.
	 *
	 * @return bool
	 */
	public static function IsRequestMemoryLimitReached() {
		if (self::$memoryLimit === false) {
			return false;
		}

		return memory_get_peak_usage(true) >= self::$memoryLimit;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * Private stuff
	 */

	/**
	 * Replaces all not allowed characters in a string.
	 *
	 * @param string $input        the input string
	 * @param int    $filter       one of the predefined filters: LETTERS_ONLY, HEX_ONLY, WORDCHAR_ONLY, NUMBERS_ONLY, NUMBERSDOT_ONLY
	 * @param char   $replacevalue (opt) a character the filtered characters should be replaced with
	 *
	 * @return string
	 */
	private static function filterEvilInput($input, $filter, $replacevalue = '') {
		$re = false;
		if ($filter == self::LETTERS_ONLY) {
			$re = "/[^A-Za-z]/";
		}
		elseif ($filter == self::HEX_ONLY) {
			$re = "/[^A-Fa-f0-9]/";
		}
		elseif ($filter == self::WORDCHAR_ONLY) {
			$re = "/[^A-Za-z0-9]/";
		}
		elseif ($filter == self::NUMBERS_ONLY) {
			$re = "/[^0-9]/";
		}
		elseif ($filter == self::NUMBERSDOT_ONLY) {
			$re = "/[^0-9\\.]/";
		}
		elseif ($filter == self::HEX_EXTENDED) {
			$re = "/[^A-Fa-f0-9\\:\\.]/";
		}
		elseif ($filter == self::HEX_EXTENDED2) {
			$re = "/[^A-Fa-f0-9\\:USGI]/";
		} // Folder origin constants from DeviceManager::FLD_ORIGIN_* (C already hex)
		elseif ($filter == self::ISO8601) {
			$re = "/[^\\d{8}T\\d{6}Z]/";
		}

		return ($re) ? preg_replace($re, $replacevalue, $input) : '';
	}

	/**
	 * If $input is a valid IPv4 or IPv6 address, returns a valid compact IPv4 or IPv6 address string.
	 * Otherwise, it will strip all characters that are neither numerical or '.' and prefix with "bad-ip".
	 *
	 * @param string $input The ipv4/ipv6 address
	 *
	 * @return string
	 */
	private static function filterIP($input) {
		$in_addr = @inet_pton($input);
		if ($in_addr === false) {
			return 'badip-' . self::filterEvilInput($input, self::HEX_EXTENDED);
		}

		return inet_ntop($in_addr);
	}

	/**
	 * Returns base64 encoded "php://input"
	 * With POST request (our case), you can open and read
	 * multiple times "php://input".
	 *
	 * @param int $maxLength max. length to be returned. Default: return all
	 *
	 * @return string - base64 encoded wbxml
	 */
	public static function GetInputAsBase64($maxLength = -1) {
		$input = fopen('php://input', 'r');
		$wbxml = base64_encode(stream_get_contents($input, $maxLength));
		fclose($input);

		return $wbxml;
	}

	/**
	 * Decodes base64 encoded query parameters. Based on dw2412 contribution.
	 */
	private static function decodeBase64URI() {
		/*
		 * The query string has a following structure. Number in () is position:
		 * 1 byte       - protocol version (0)
		 * 1 byte       - command code (1)
		 * 2 bytes      - locale (2)
		 * 1 byte       - device ID length (4)
		 * variable     - device ID (4+device ID length)
		 * 1 byte       - policy key length (5+device ID length)
		 * 0 or 4 bytes - policy key (5+device ID length + policy key length)
		 * 1 byte       - device type length (6+device ID length + policy key length)
		 * variable     - device type (6+device ID length + policy key length + device type length)
		 * variable     - command parameters, array which consists of:
		 *                      1 byte      - tag
		 *                      1 byte      - length
		 *                      variable    - value of the parameter
		 *
		 */
		$decoded = base64_decode($_SERVER['QUERY_STRING']);
		$devIdLength = ord($decoded[4]); // device ID length
		$polKeyLength = ord($decoded[5 + $devIdLength]); // policy key length
		$devTypeLength = ord($decoded[6 + $devIdLength + $polKeyLength]); // device type length
		// unpack the decoded query string values
		self::$base64QueryDecoded = unpack("CProtVer/CCommand/vLocale/CDevIDLen/H" . ($devIdLength * 2) . "DevID/CPolKeyLen" . ($polKeyLength == 4 ? "/VPolKey" : "") . "/CDevTypeLen/A" . ($devTypeLength) . "DevType", $decoded);

		// get the command parameters
		$pos = 7 + $devIdLength + $polKeyLength + $devTypeLength;
		$decoded = substr($decoded, $pos);
		while (strlen($decoded) > 0) {
			$paramLength = ord($decoded[1]);
			$unpackedParam = unpack("CParamTag/CParamLength/A" . $paramLength . "ParamValue", $decoded);
			self::$base64QueryDecoded[ord($decoded[0])] = $unpackedParam['ParamValue'];
			// remove parameter from decoded query string
			$decoded = substr($decoded, 2 + $paramLength);
		}
	}
}
