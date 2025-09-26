<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Debug and logging
 */

class SLog {
	private static $wbxmlDebug = '';
	private static $lastLogs = [];

	/**
	 * @var Log
	 */
	private static $logger;

	/**
	 * Initializes the logging.
	 *
	 * @return bool
	 */
	public static function Initialize() {
		// define some constants for the logging
		if (!defined('LOGUSERLEVEL')) {
			define('LOGUSERLEVEL', LOGLEVEL_OFF);
		}

		if (!defined('LOGLEVEL')) {
			define('LOGLEVEL', LOGLEVEL_OFF);
		}

		$logger = self::getLogger();

		return true;
	}

	/**
	 * Check if WBXML logging is enabled in current LOG(USER)LEVEL.
	 *
	 * @return bool
	 */
	public static function IsWbxmlDebugEnabled() {
		return LOGLEVEL >= LOGLEVEL_WBXML || (LOGUSERLEVEL >= LOGLEVEL_WBXML && self::getLogger()->HasSpecialLogUsers());
	}

	/**
	 * Writes a log line.
	 *
	 * @param int    $loglevel one of the defined LOGLEVELS
	 * @param string $message
	 * @param bool   $truncate indicate if the message should be truncated, default true
	 */
	public static function Write($loglevel, $message, $truncate = true) {
		// truncate messages longer than 10 KB
		$messagesize = strlen($message);
		if ($truncate && $messagesize > 10240) {
			$message = substr($message, 0, 10240) . sprintf(" <log message with %d bytes truncated>", $messagesize);
		}

		self::$lastLogs[$loglevel] = $message;

		try {
			self::getLogger()->Log($loglevel, $message);
		}
		catch (Exception) {
			// @TODO How should we handle logging error ?
			// Ignore any error.
		}

		if ($loglevel & LOGLEVEL_WBXMLSTACK) {
			self::$wbxmlDebug .= $message . PHP_EOL;
		}
	}

	/**
	 * Returns logged information about the WBXML stack.
	 *
	 * @return string
	 */
	public static function GetWBXMLDebugInfo() {
		return trim((string) self::$wbxmlDebug);
	}

	/**
	 * Returns the last message logged for a log level.
	 *
	 * @param int $loglevel one of the defined LOGLEVELS
	 *
	 * @return false|string returns false if there was no message logged in that level
	 */
	public static function GetLastMessage($loglevel) {
		return self::$lastLogs[$loglevel] ?? false;
	}

	/**
	 * If called, the authenticated current user gets an extra log-file.
	 *
	 * If called until the user is authenticated (e.g. at the end of IBackend->Logon()) all log
	 * messages that happened until this point will also be logged.
	 */
	public static function SpecialLogUser() {
		self::getLogger()->SpecialLogUser();
	}

	/**
	 * Returns the logger object. If no logger has been initialized, FileLog will be initialized and returned.
	 *
	 * @return Log
	 *
	 * @throws Exception thrown if the logger class cannot be instantiated
	 */
	private static function getLogger() {
		if (!self::$logger) {
			global $specialLogUsers; // This variable comes from the configuration file (config.php)

			$logger = LOGBACKEND_CLASS;
			if (!class_exists($logger)) {
				$errmsg = 'The configured logging class `' . $logger . '` does not exist. Check your configuration.';
				error_log($errmsg);

				throw new Exception($errmsg);
			}

			// if there is an impersonated user it's used instead of the GET user
			if (Request::GetImpersonatedUser()) {
				$user = Request::GetImpersonatedUser();
			}
			else {
				[$user] = Utils::SplitDomainUser(strtolower(Request::GetGETUser()));
			}

			self::$logger = new $logger();
			self::$logger->SetUser($user);
			self::$logger->SetAuthUser(Request::GetAuthUser());
			self::$logger->SetSpecialLogUsers($specialLogUsers);
			self::$logger->SetDevid(Request::GetDeviceID());
			self::$logger->SetPid(@getmypid());
			self::$logger->AfterInitialize();
		}

		return self::$logger;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * private log stuff
	 */
}

/*----------------------------------------------------------------------------------------------------------
 * Legacy debug stuff
 */

// TODO review error handler
function gsync_error_handler($errno, $errstr, $errfile, $errline) {
	if (defined('LOG_ERROR_MASK')) {
		$errno &= LOG_ERROR_MASK;
	}

	switch ($errno) {
		case 0:
			// logging disabled by LOG_ERROR_MASK
			break;

		case E_DEPRECATED:
			// do not handle this message
			break;

		case E_NOTICE:
		case E_WARNING:
			// TODO check if there is a better way to avoid these messages
			if (stripos((string) $errfile, 'interprocessdata') !== false && stripos((string) $errstr, 'shm_get_var()') !== false) {
				break;
			}
			SLog::Write(LOGLEVEL_WARN, "{$errfile}:{$errline} {$errstr} ({$errno})");
			break;

		default:
			$bt = debug_backtrace();
			SLog::Write(LOGLEVEL_ERROR, "trace error: {$errfile}:{$errline} {$errstr} ({$errno}) - backtrace: " . (count($bt) - 1) . " steps");
			for ($i = 1, $bt_length = count($bt); $i < $bt_length; ++$i) {
				$file = $line = "unknown";
				if (isset($bt[$i]['file'])) {
					$file = $bt[$i]['file'];
				}
				if (isset($bt[$i]['line'])) {
					$line = $bt[$i]['line'];
				}
				SLog::Write(LOGLEVEL_ERROR, "trace: {$i}:" . $file . ":" . $line . " - " . ((isset($bt[$i]['class'])) ? $bt[$i]['class'] . $bt[$i]['type'] : "") . $bt[$i]['function'] . "()");
			}
			// throw new Exception("An error occurred.");
			break;
	}
}

error_reporting(E_ALL);
set_error_handler("gsync_error_handler");

function gsync_fatal_handler() {
	$errfile = "unknown file";
	$errstr = "shutdown";
	$errno = E_CORE_ERROR;
	$errline = 0;

	$error = error_get_last();

	if ($error !== null) {
		$errno = $error["type"];
		$errfile = $error["file"];
		$errline = $error["line"];
		$errstr = $error["message"];

		// do NOT log PHP Notice, Warning, Deprecated or Strict as FATAL
		if ($errno & ~(E_NOTICE | E_WARNING | E_DEPRECATED | E_STRICT)) {
			SLog::Write(LOGLEVEL_FATAL, sprintf("Fatal error: %s:%d - %s (%s)", $errfile, $errline, $errstr, $errno));
		}
	}
}
register_shutdown_function("gsync_fatal_handler");
