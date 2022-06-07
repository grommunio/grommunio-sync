<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Core functionalities
 */

class GSync {
	public const UNAUTHENTICATED = 1;
	public const UNPROVISIONED = 2;
	public const NOACTIVESYNCCOMMAND = 3;
	public const WEBSERVICECOMMAND = 4;    // DEPRECATED
	public const HIERARCHYCOMMAND = 5;
	public const PLAININPUT = 6;
	public const REQUESTHANDLER = 7;
	public const CLASS_NAME = 1;
	public const CLASS_REQUIRESPROTOCOLVERSION = 2;
	public const CLASS_DEFAULTTYPE = 3;
	public const CLASS_OTHERTYPES = 4;

	// AS versions
	public const ASV_1 = "1.0";
	public const ASV_2 = "2.0";
	public const ASV_21 = "2.1";
	public const ASV_25 = "2.5";
	public const ASV_12 = "12.0";
	public const ASV_121 = "12.1";
	public const ASV_14 = "14.0";
	public const ASV_141 = "14.1";

	/**
	 * Command codes for base64 encoded requests (AS >= 12.1).
	 */
	public const COMMAND_SYNC = 0;
	public const COMMAND_SENDMAIL = 1;
	public const COMMAND_SMARTFORWARD = 2;
	public const COMMAND_SMARTREPLY = 3;
	public const COMMAND_GETATTACHMENT = 4;
	public const COMMAND_FOLDERSYNC = 9;
	public const COMMAND_FOLDERCREATE = 10;
	public const COMMAND_FOLDERDELETE = 11;
	public const COMMAND_FOLDERUPDATE = 12;
	public const COMMAND_MOVEITEMS = 13;
	public const COMMAND_GETITEMESTIMATE = 14;
	public const COMMAND_MEETINGRESPONSE = 15;
	public const COMMAND_SEARCH = 16;
	public const COMMAND_SETTINGS = 17;
	public const COMMAND_PING = 18;
	public const COMMAND_ITEMOPERATIONS = 19;
	public const COMMAND_PROVISION = 20;
	public const COMMAND_RESOLVERECIPIENTS = 21;
	public const COMMAND_VALIDATECERT = 22;

	// Deprecated commands
	public const COMMAND_GETHIERARCHY = -1;
	public const COMMAND_CREATECOLLECTION = -2;
	public const COMMAND_DELETECOLLECTION = -3;
	public const COMMAND_MOVECOLLECTION = -4;
	public const COMMAND_NOTIFY = -5;

	// Latest supported State version
	public const STATE_VERSION = IStateMachine::STATEVERSION_02;

	// Versions 1.0, 2.0, 2.1 and 2.5 are deprecated (ZP-604)
	private static $supportedASVersions = [
		self::ASV_12,
		self::ASV_121,
		self::ASV_14,
		self::ASV_141,
	];

	private static $supportedCommands = [
		// COMMAND             AS VERSION   REQUESTHANDLER                                  OTHER SETTINGS
		self::COMMAND_SYNC => [self::ASV_1, self::REQUESTHANDLER => "Sync"],
		self::COMMAND_SENDMAIL => [self::ASV_1, self::REQUESTHANDLER => "SendMail"],
		self::COMMAND_SMARTFORWARD => [self::ASV_1, self::REQUESTHANDLER => "SendMail"],
		self::COMMAND_SMARTREPLY => [self::ASV_1, self::REQUESTHANDLER => "SendMail"],
		self::COMMAND_GETATTACHMENT => [self::ASV_1, self::REQUESTHANDLER => "GetAttachment"],
		self::COMMAND_GETHIERARCHY => [self::ASV_1, self::REQUESTHANDLER => "GetHierarchy", self::HIERARCHYCOMMAND], // deprecated but implemented
		self::COMMAND_CREATECOLLECTION => [self::ASV_1], // deprecated & not implemented
		self::COMMAND_DELETECOLLECTION => [self::ASV_1], // deprecated & not implemented
		self::COMMAND_MOVECOLLECTION => [self::ASV_1], // deprecated & not implemented
		self::COMMAND_FOLDERSYNC => [self::ASV_2, self::REQUESTHANDLER => "FolderSync", self::HIERARCHYCOMMAND],
		self::COMMAND_FOLDERCREATE => [self::ASV_2, self::REQUESTHANDLER => "FolderChange", self::HIERARCHYCOMMAND],
		self::COMMAND_FOLDERDELETE => [self::ASV_2, self::REQUESTHANDLER => "FolderChange", self::HIERARCHYCOMMAND],
		self::COMMAND_FOLDERUPDATE => [self::ASV_2, self::REQUESTHANDLER => "FolderChange", self::HIERARCHYCOMMAND],
		self::COMMAND_MOVEITEMS => [self::ASV_1, self::REQUESTHANDLER => "MoveItems"],
		self::COMMAND_GETITEMESTIMATE => [self::ASV_1, self::REQUESTHANDLER => "GetItemEstimate"],
		self::COMMAND_MEETINGRESPONSE => [self::ASV_1, self::REQUESTHANDLER => "MeetingResponse"],
		self::COMMAND_RESOLVERECIPIENTS => [self::ASV_1, self::REQUESTHANDLER => "ResolveRecipients"],
		self::COMMAND_VALIDATECERT => [self::ASV_1, self::REQUESTHANDLER => "ValidateCert"],
		self::COMMAND_PROVISION => [self::ASV_25, self::REQUESTHANDLER => "Provisioning", self::UNAUTHENTICATED, self::UNPROVISIONED],
		self::COMMAND_SEARCH => [self::ASV_1, self::REQUESTHANDLER => "Search"],
		self::COMMAND_PING => [self::ASV_2, self::REQUESTHANDLER => "Ping", self::UNPROVISIONED],
		self::COMMAND_NOTIFY => [self::ASV_1, self::REQUESTHANDLER => "Notify"], // deprecated & not implemented
		self::COMMAND_ITEMOPERATIONS => [self::ASV_12, self::REQUESTHANDLER => "ItemOperations"],
		self::COMMAND_SETTINGS => [self::ASV_12, self::REQUESTHANDLER => "Settings"],
	];

	private static $classes = [
		"Email" => [
			self::CLASS_NAME => "SyncMail",
			self::CLASS_REQUIRESPROTOCOLVERSION => false,
			self::CLASS_DEFAULTTYPE => SYNC_FOLDER_TYPE_INBOX,
			self::CLASS_OTHERTYPES => [
				SYNC_FOLDER_TYPE_OTHER,
				SYNC_FOLDER_TYPE_DRAFTS,
				SYNC_FOLDER_TYPE_WASTEBASKET,
				SYNC_FOLDER_TYPE_SENTMAIL,
				SYNC_FOLDER_TYPE_OUTBOX,
				SYNC_FOLDER_TYPE_USER_MAIL,
				SYNC_FOLDER_TYPE_JOURNAL,
				SYNC_FOLDER_TYPE_USER_JOURNAL,
			],
		],
		"Contacts" => [
			self::CLASS_NAME => "SyncContact",
			self::CLASS_REQUIRESPROTOCOLVERSION => true,
			self::CLASS_DEFAULTTYPE => SYNC_FOLDER_TYPE_CONTACT,
			self::CLASS_OTHERTYPES => [SYNC_FOLDER_TYPE_USER_CONTACT, SYNC_FOLDER_TYPE_UNKNOWN],
		],
		"Calendar" => [
			self::CLASS_NAME => "SyncAppointment",
			self::CLASS_REQUIRESPROTOCOLVERSION => false,
			self::CLASS_DEFAULTTYPE => SYNC_FOLDER_TYPE_APPOINTMENT,
			self::CLASS_OTHERTYPES => [SYNC_FOLDER_TYPE_USER_APPOINTMENT],
		],
		"Tasks" => [
			self::CLASS_NAME => "SyncTask",
			self::CLASS_REQUIRESPROTOCOLVERSION => false,
			self::CLASS_DEFAULTTYPE => SYNC_FOLDER_TYPE_TASK,
			self::CLASS_OTHERTYPES => [SYNC_FOLDER_TYPE_USER_TASK],
		],
		"Notes" => [
			self::CLASS_NAME => "SyncNote",
			self::CLASS_REQUIRESPROTOCOLVERSION => false,
			self::CLASS_DEFAULTTYPE => SYNC_FOLDER_TYPE_NOTE,
			self::CLASS_OTHERTYPES => [SYNC_FOLDER_TYPE_USER_NOTE],
		],
	];

	private static $stateMachine;
	private static $deviceManager;
	private static $provisioningManager;
	private static $topCollector;
	private static $backend;
	private static $addSyncFolders;
	private static $policies;
	private static $redis;

	/**
	 * Verifies configuration.
	 *
	 * @throws FatalMisconfigurationException
	 *
	 * @return bool
	 */
	public static function CheckConfig() {
		// check the php version
		if (version_compare(phpversion(), '5.4.0') < 0) {
			throw new FatalException("The configured PHP version is too old. Please make sure at least PHP 5.4 is used.");
		}

		// some basic checks
		if (!defined('BASE_PATH')) {
			throw new FatalMisconfigurationException("The BASE_PATH is not configured. Check if the config.php file is in place.");
		}

		if (substr(BASE_PATH, -1, 1) != "/") {
			throw new FatalMisconfigurationException("The BASE_PATH should terminate with a '/'");
		}

		if (!file_exists(BASE_PATH)) {
			throw new FatalMisconfigurationException("The configured BASE_PATH does not exist or can not be accessed.");
		}

		if (defined('BASE_PATH_CLI') && file_exists(BASE_PATH_CLI)) {
			define('REAL_BASE_PATH', BASE_PATH_CLI);
		}
		else {
			define('REAL_BASE_PATH', BASE_PATH);
		}

		if (!defined('LOGBACKEND')) {
			define('LOGBACKEND', 'filelog');
		}

		if (strtolower(LOGBACKEND) == 'syslog') {
			define('LOGBACKEND_CLASS', 'Syslog');
			if (!defined('LOG_SYSLOG_FACILITY')) {
				define('LOG_SYSLOG_FACILITY', LOG_LOCAL0);
			}

			if (!defined('LOG_SYSLOG_HOST')) {
				define('LOG_SYSLOG_HOST', false);
			}

			if (!defined('LOG_SYSLOG_PORT')) {
				define('LOG_SYSLOG_PORT', 514);
			}

			if (!defined('LOG_SYSLOG_PROGRAM')) {
				define('LOG_SYSLOG_PROGRAM', 'grommunio-sync');
			}

			if (!is_numeric(LOG_SYSLOG_PORT)) {
				throw new FatalMisconfigurationException("The LOG_SYSLOG_PORT must a be a number.");
			}

			if (LOG_SYSLOG_HOST && LOG_SYSLOG_PORT <= 0) {
				throw new FatalMisconfigurationException("LOG_SYSLOG_HOST is defined but the LOG_SYSLOG_PORT does not seem to be valid.");
			}
		}
		elseif (strtolower(LOGBACKEND) == 'filelog') {
			define('LOGBACKEND_CLASS', 'FileLog');
			if (!defined('LOGFILEDIR')) {
				throw new FatalMisconfigurationException("The LOGFILEDIR is not configured. Check if the config.php file is in place.");
			}

			if (substr(LOGFILEDIR, -1, 1) != "/") {
				throw new FatalMisconfigurationException("The LOGFILEDIR should terminate with a '/'");
			}

			if (!file_exists(LOGFILEDIR)) {
				throw new FatalMisconfigurationException("The configured LOGFILEDIR does not exist or can not be accessed.");
			}

			if ((!file_exists(LOGFILE) && !touch(LOGFILE)) || !is_writable(LOGFILE)) {
				throw new FatalMisconfigurationException("The configured LOGFILE can not be modified.");
			}

			if ((!file_exists(LOGERRORFILE) && !touch(LOGERRORFILE)) || !is_writable(LOGERRORFILE)) {
				throw new FatalMisconfigurationException("The configured LOGERRORFILE can not be modified.");
			}

			// check ownership on the (eventually) just created files
			Utils::FixFileOwner(LOGFILE);
			Utils::FixFileOwner(LOGERRORFILE);
		}
		else {
			define('LOGBACKEND_CLASS', LOGBACKEND);
		}

		// set time zone
		// code contributed by Robert Scheck (rsc)
		if (defined('TIMEZONE') ? constant('TIMEZONE') : false) {
			if (!@date_default_timezone_set(TIMEZONE)) {
				throw new FatalMisconfigurationException(sprintf("The configured TIMEZONE '%s' is not valid. Please check supported timezones at http://www.php.net/manual/en/timezones.php", constant('TIMEZONE')));
			}
		}
		elseif (!ini_get('date.timezone')) {
			date_default_timezone_set('Europe/Vienna');
		}

		if (defined('USE_X_FORWARDED_FOR_HEADER')) {
			SLog::Write(LOGLEVEL_INFO, "The configuration parameter 'USE_X_FORWARDED_FOR_HEADER' was deprecated in favor of 'USE_CUSTOM_REMOTE_IP_HEADER'. Please update your configuration.");
		}

		// check redis configuration - set defaults
		if (!defined('REDIS_HOST')) {
			define('REDIS_HOST', 'localhost');
		}
		if (!defined('REDIS_PORT')) {
			define('REDIS_PORT', 6379);
		}
		if (!defined('REDIS_AUTH')) {
			define('REDIS_AUTH', '');
		}

		return true;
	}

	/**
	 * Verifies Timezone, StateMachine and Backend configuration.
	 *
	 * @return bool
	 * @trows FatalMisconfigurationException
	 */
	public static function CheckAdvancedConfig() {
		global $specialLogUsers, $additionalFolders;

		if (!is_array($specialLogUsers)) {
			throw new FatalMisconfigurationException("The WBXML log users is not an array.");
		}

		if (!defined('SYNC_CONTACTS_MAXPICTURESIZE')) {
			define('SYNC_CONTACTS_MAXPICTURESIZE', 49152);
		}
		elseif ((!is_int(SYNC_CONTACTS_MAXPICTURESIZE) || SYNC_CONTACTS_MAXPICTURESIZE < 1)) {
			throw new FatalMisconfigurationException("The SYNC_CONTACTS_MAXPICTURESIZE value must be a number higher than 0.");
		}

		if (!defined('USE_PARTIAL_FOLDERSYNC')) {
			define('USE_PARTIAL_FOLDERSYNC', false);
		}

		if (!defined('PING_LOWER_BOUND_LIFETIME')) {
			define('PING_LOWER_BOUND_LIFETIME', false);
		}
		elseif (PING_LOWER_BOUND_LIFETIME !== false && (!is_int(PING_LOWER_BOUND_LIFETIME) || PING_LOWER_BOUND_LIFETIME < 1 || PING_LOWER_BOUND_LIFETIME > 3540)) {
			throw new FatalMisconfigurationException("The PING_LOWER_BOUND_LIFETIME value must be 'false' or a number between 1 and 3540 inclusively.");
		}
		if (!defined('PING_HIGHER_BOUND_LIFETIME')) {
			define('PING_HIGHER_BOUND_LIFETIME', false);
		}
		elseif (PING_HIGHER_BOUND_LIFETIME !== false && (!is_int(PING_HIGHER_BOUND_LIFETIME) || PING_HIGHER_BOUND_LIFETIME < 1 || PING_HIGHER_BOUND_LIFETIME > 3540)) {
			throw new FatalMisconfigurationException("The PING_HIGHER_BOUND_LIFETIME value must be 'false' or a number between 1 and 3540 inclusively.");
		}
		if (PING_HIGHER_BOUND_LIFETIME !== false && PING_LOWER_BOUND_LIFETIME !== false && PING_HIGHER_BOUND_LIFETIME < PING_LOWER_BOUND_LIFETIME) {
			throw new FatalMisconfigurationException("The PING_HIGHER_BOUND_LIFETIME value must be greater or equal to PING_LOWER_BOUND_LIFETIME.");
		}

		if (!defined('RETRY_AFTER_DELAY')) {
			define('RETRY_AFTER_DELAY', 300);
		}
		elseif (RETRY_AFTER_DELAY !== false && (!is_int(RETRY_AFTER_DELAY) || RETRY_AFTER_DELAY < 1)) {
			throw new FatalMisconfigurationException("The RETRY_AFTER_DELAY value must be 'false' or a number greater than 0.");
		}

		// set Grommunio backend defaults if not set
		if (!defined('MAPI_SERVER')) {
			define('MAPI_SERVER', 'default:');
		}
		if (!defined('STORE_STATE_FOLDER')) {
			define('STORE_STATE_FOLDER', 'GS-SyncState');
		}

		// the check on additional folders will not throw hard errors, as this is probably changed on live systems
		if (isset($additionalFolders) && !is_array($additionalFolders)) {
			SLog::Write(LOGLEVEL_ERROR, "GSync::CheckConfig(): The additional folders synchronization not available as array.");
		}
		else {
			// check configured data
			foreach ($additionalFolders as $af) {
				if (!is_array($af) || !isset($af['store']) || !isset($af['folderid']) || !isset($af['name']) || !isset($af['type'])) {
					SLog::Write(LOGLEVEL_ERROR, "GSync::CheckConfig(): the additional folder synchronization is not configured correctly. Missing parameters. Entry will be ignored.");

					continue;
				}

				if ($af['store'] == "" || $af['folderid'] == "" || $af['name'] == "" || $af['type'] == "") {
					SLog::Write(LOGLEVEL_WARN, "GSync::CheckConfig(): the additional folder synchronization is not configured correctly. Empty parameters. Entry will be ignored.");

					continue;
				}

				if (!in_array($af['type'], [SYNC_FOLDER_TYPE_USER_NOTE, SYNC_FOLDER_TYPE_USER_CONTACT, SYNC_FOLDER_TYPE_USER_APPOINTMENT, SYNC_FOLDER_TYPE_USER_TASK, SYNC_FOLDER_TYPE_USER_MAIL])) {
					SLog::Write(LOGLEVEL_ERROR, sprintf("GSync::CheckConfig(): the type of the additional synchronization folder '%s is not permitted.", $af['name']));

					continue;
				}
				// the data will be initialized when used via self::getAddFolders()
			}
		}

		SLog::Write(LOGLEVEL_DEBUG, sprintf("Used timezone '%s'", date_default_timezone_get()));

		// get the statemachine, which will also try to load the backend.. This could throw errors
		self::GetStateMachine();

		return true;
	}

	/**
	 * Returns the StateMachine object
	 * which has to be an IStateMachine implementation.
	 *
	 * @throws FatalNotImplementedException
	 * @throws HTTPReturnCodeException
	 *
	 * @return object implementation of IStateMachine
	 */
	public static function GetStateMachine() {
		if (!isset(GSync::$stateMachine)) {
			// the backend could also return an own IStateMachine implementation
			GSync::$stateMachine = self::GetBackend()->GetStateMachine();

			if (GSync::$stateMachine->GetStateVersion() !== GSync::GetLatestStateVersion()) {
				if (class_exists("TopCollector")) {
					self::GetTopCollector()->AnnounceInformation("Run migration script!", true);
				}

				throw new ServiceUnavailableException(sprintf("The state version available to the %s is not the latest version - please run the state upgrade script. See release notes for more information.", get_class(GSync::$stateMachine)));
			}
		}

		return GSync::$stateMachine;
	}

	/**
	 * Returns the Redis object.
	 *
	 * @return object Redis
	 */
	public static function GetRedis() {
		if (!isset(GSync::$redis)) {
			GSync::$redis = new RedisConnection();
		}

		return GSync::$redis;
	}

	/**
	 * Returns the latest version of supported states.
	 *
	 * @return int
	 */
	public static function GetLatestStateVersion() {
		return self::STATE_VERSION;
	}

	/**
	 * Returns the ProvisioningManager object.
	 *
	 * @return object ProvisioningManager
	 */
	public static function GetProvisioningManager() {
		if (!isset(self::$provisioningManager)) {
			self::$provisioningManager = new ProvisioningManager();
		}

		return self::$provisioningManager;
	}

	/**
	 * Returns the DeviceManager object.
	 *
	 * @param bool $initialize (opt) default true: initializes the DeviceManager if not already done
	 *
	 * @return object DeviceManager
	 */
	public static function GetDeviceManager($initialize = true) {
		if (!isset(GSync::$deviceManager) && $initialize) {
			GSync::$deviceManager = new DeviceManager();
		}

		return GSync::$deviceManager;
	}

	/**
	 * Returns the Top data collector object.
	 *
	 * @return object TopCollector
	 */
	public static function GetTopCollector() {
		if (!isset(GSync::$topCollector)) {
			GSync::$topCollector = new TopCollector();
		}

		return GSync::$topCollector;
	}

	/**
	 * Loads a backend file.
	 *
	 * @param string $backendname
	 *
	 * @throws FatalNotImplementedException
	 *
	 * @return bool
	 */
	public static function IncludeBackend($backendname) {
		if ($backendname == false) {
			return false;
		}

		$backendname = strtolower($backendname);
		if (substr($backendname, 0, 7) !== 'backend') {
			throw new FatalNotImplementedException(sprintf("Backend '%s' is not allowed", $backendname));
		}

		$rbn = substr($backendname, 7);

		$subdirbackend = REAL_BASE_PATH . "backend/" . $rbn . "/" . $rbn . ".php";
		$stdbackend = REAL_BASE_PATH . "backend/" . $rbn . ".php";

		if (is_file($subdirbackend)) {
			$toLoad = $subdirbackend;
		}
		elseif (is_file($stdbackend)) {
			$toLoad = $stdbackend;
		}
		else {
			return false;
		}

		SLog::Write(LOGLEVEL_DEBUG, sprintf("Including backend file: '%s'", $toLoad));

		return include_once $toLoad;
	}

	/**
	 * Returns the Backend for this request
	 * the backend has to be an IBackend implementation.
	 *
	 * @return object IBackend implementation
	 */
	public static function GetBackend() {
		// if the backend is not yet loaded, load backend drivers and instantiate it
		if (!isset(GSync::$backend)) {
			// Initialize Grommunio
			GSync::$backend = new Grommunio();
		}

		return GSync::$backend;
	}

	/**
	 * Returns additional folder objects which should be synchronized to the device.
	 *
	 * @param bool $backendIdsAsKeys if true the keys are backendids else folderids, default: true
	 *
	 * @return array
	 */
	public static function GetAdditionalSyncFolders($backendIdsAsKeys = true) {
		// get user based folders which should be synchronized
		$userFolder = self::GetDeviceManager()->GetAdditionalUserSyncFolders();
		$addfolders = self::getAddSyncFolders() + $userFolder;
		// if requested, we rewrite the backendids to folderids here
		if ($backendIdsAsKeys === false && !empty($addfolders)) {
			SLog::Write(LOGLEVEL_DEBUG, "GSync::GetAdditionalSyncFolders(): Requested AS folderids as keys for additional folders array, converting");
			$faddfolders = [];
			foreach ($addfolders as $backendId => $addFolder) {
				$fid = self::GetDeviceManager()->GetFolderIdForBackendId($backendId);
				$faddfolders[$fid] = $addFolder;
			}
			$addfolders = $faddfolders;
		}

		return $addfolders;
	}

	/**
	 * Returns additional folder objects which should be synchronized to the device.
	 *
	 * @param string $backendid
	 * @param bool   $noDebug   (opt) by default, debug message is shown
	 *
	 * @return string
	 */
	public static function GetAdditionalSyncFolderStore($backendid, $noDebug = false) {
		if (isset(self::getAddSyncFolders()[$backendid]->Store)) {
			$val = self::getAddSyncFolders()[$backendid]->Store;
		}
		else {
			$val = self::GetDeviceManager()->GetAdditionalUserSyncFolder($backendid);
			if (isset($val['store'])) {
				$val = $val['store'];
			}
		}

		if (!$noDebug) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("GSync::GetAdditionalSyncFolderStore('%s'): '%s'", $backendid, Utils::PrintAsString($val)));
		}

		return $val;
	}

	/**
	 * Returns a SyncObject class name for a folder class.
	 *
	 * @param string $folderclass
	 *
	 * @throws FatalNotImplementedException
	 *
	 * @return string
	 */
	public static function getSyncObjectFromFolderClass($folderclass) {
		if (!isset(self::$classes[$folderclass])) {
			throw new FatalNotImplementedException("Class '{$folderclass}' is not supported");
		}

		$class = self::$classes[$folderclass][self::CLASS_NAME];
		if (self::$classes[$folderclass][self::CLASS_REQUIRESPROTOCOLVERSION]) {
			return new $class(Request::GetProtocolVersion());
		}

		return new $class();
	}

	/**
	 * Initializes the SyncObjects for additional folders on demand.
	 * Uses DeviceManager->BuildSyncFolderObject() to do patching required for ZP-907.
	 *
	 * @return array
	 */
	private static function getAddSyncFolders() {
		global $additionalFolders;
		if (!isset(self::$addSyncFolders)) {
			self::$addSyncFolders = [];

			if (isset($additionalFolders) && !is_array($additionalFolders)) {
				SLog::Write(LOGLEVEL_ERROR, "GSync::getAddSyncFolders() : The additional folders synchronization not available as array.");
			}
			else {
				foreach ($additionalFolders as $af) {
					if (!is_array($af) || !isset($af['store']) || !isset($af['folderid']) || !isset($af['name']) || !isset($af['type'])) {
						SLog::Write(LOGLEVEL_ERROR, "GSync::getAddSyncFolders() : the additional folder synchronization is not configured correctly. Missing parameters. Entry will be ignored.");

						continue;
					}

					if ($af['store'] == "" || $af['folderid'] == "" || $af['name'] == "" || $af['type'] == "") {
						SLog::Write(LOGLEVEL_WARN, "GSync::getAddSyncFolders() : the additional folder synchronization is not configured correctly. Empty parameters. Entry will be ignored.");

						continue;
					}

					if (!in_array($af['type'], [SYNC_FOLDER_TYPE_USER_NOTE, SYNC_FOLDER_TYPE_USER_CONTACT, SYNC_FOLDER_TYPE_USER_APPOINTMENT, SYNC_FOLDER_TYPE_USER_TASK, SYNC_FOLDER_TYPE_USER_MAIL])) {
						SLog::Write(LOGLEVEL_ERROR, sprintf("GSync::getAddSyncFolders() : the type of the additional synchronization folder '%s is not permitted.", $af['name']));

						continue;
					}

					// don't fail hard if no flags are set, but we at least warn about it
					if (!isset($af['flags'])) {
						SLog::Write(LOGLEVEL_WARN, sprintf("GSync::getAddSyncFolders() : the additional folder '%s' is not configured completely. Missing 'flags' parameter, defaulting to DeviceManager::FLD_FLAGS_NONE.", $af['name']));
						$af['flags'] = DeviceManager::FLD_FLAGS_NONE;
					}

					$folder = self::GetDeviceManager()->BuildSyncFolderObject($af['store'], $af['folderid'], '0', $af['name'], $af['type'], $af['flags'], DeviceManager::FLD_ORIGIN_CONFIG);
					self::$addSyncFolders[$folder->BackendId] = $folder;
				}
			}
		}

		return self::$addSyncFolders;
	}

	/**
	 * Returns the default foldertype for a folder class.
	 *
	 * @param string $folderclass folderclass sent by the mobile
	 *
	 * @return string
	 */
	public static function getDefaultFolderTypeFromFolderClass($folderclass) {
		SLog::Write(LOGLEVEL_DEBUG, sprintf("GSync::getDefaultFolderTypeFromFolderClass('%s'): '%d'", $folderclass, self::$classes[$folderclass][self::CLASS_DEFAULTTYPE]));

		return self::$classes[$folderclass][self::CLASS_DEFAULTTYPE];
	}

	/**
	 * Returns the folder class for a foldertype.
	 *
	 * @param string $foldertype
	 *
	 * @return false|string false if no class for this type is available
	 */
	public static function GetFolderClassFromFolderType($foldertype) {
		$class = false;
		foreach (self::$classes as $aClass => $cprops) {
			if ($cprops[self::CLASS_DEFAULTTYPE] == $foldertype || in_array($foldertype, $cprops[self::CLASS_OTHERTYPES])) {
				$class = $aClass;

				break;
			}
		}
		SLog::Write(LOGLEVEL_DEBUG, sprintf("GSync::GetFolderClassFromFolderType('%s'): %s", $foldertype, Utils::PrintAsString($class)));

		return $class;
	}

	/**
	 * Prints the grommunio-sync legal header to STDOUT
	 * Using this breaks ActiveSync synchronization if wbxml is expected.
	 *
	 * @param string $message           (opt) message to be displayed
	 * @param string $additionalMessage (opt) additional message to be displayed
	 *
	 * @return
	 */
	public static function PrintGrommunioSyncLegal($message = "", $additionalMessage = "") {
		SLog::Write(LOGLEVEL_DEBUG, "GSync::PrintGrommunioSyncLegal()");

		if ($message) {
			$message = "<h3>" . $message . "</h3>";
		}
		if ($additionalMessage) {
			$additionalMessage .= "<br>";
		}

		header("Content-type: text/html");
		echo <<<END
        <html>
        <header>
        <title>grommunio-sync ActiveSync</title>
        </header>
        <body>
        <font face="verdana">
        <h2>grommunio-sync - Open Source ActiveSync</h2>
        {$message} {$additionalMessage}
        <br><br>
        More information about grommunio can be found
        <a href="https://grommunio.com/">at the grommunio homepage</a><br>
        </font>
        </body>
        </html>
END;
	}

	/**
	 * Indicates the latest AS version supported by grommunio-sync.
	 *
	 * @return string
	 */
	public static function GetLatestSupportedASVersion() {
		return end(self::$supportedASVersions);
	}

	/**
	 * Indicates which is the highest AS version supported by the backend.
	 *
	 * @throws FatalNotImplementedException if the backend returns an invalid version
	 *
	 * @return string
	 */
	public static function GetSupportedASVersion() {
		$version = self::GetBackend()->GetSupportedASVersion();
		if (!in_array($version, self::$supportedASVersions)) {
			throw new FatalNotImplementedException(sprintf("AS version '%s' reported by the backend is not supported", $version));
		}

		return $version;
	}

	/**
	 * Returns AS server header.
	 *
	 * @return string
	 */
	public static function GetServerHeader() {
		if (self::GetSupportedASVersion() == self::ASV_25) {
			return "MS-Server-ActiveSync: 6.5.7638.1";
		}

		return "MS-Server-ActiveSync: " . self::GetSupportedASVersion();
	}

	/**
	 * Returns AS protocol versions which are supported.
	 *
	 * @param bool $valueOnly (opt) default: false (also returns the header name)
	 *
	 * @return string
	 */
	public static function GetSupportedProtocolVersions($valueOnly = false) {
		$versions = implode(',', array_slice(self::$supportedASVersions, 0, (array_search(self::GetSupportedASVersion(), self::$supportedASVersions) + 1)));
		SLog::Write(LOGLEVEL_DEBUG, "GSync::GetSupportedProtocolVersions(): " . $versions);

		if ($valueOnly === true) {
			return $versions;
		}

		return "MS-ASProtocolVersions: " . $versions;
	}

	/**
	 * Returns AS commands which are supported.
	 *
	 * @return string
	 */
	public static function GetSupportedCommands() {
		$asCommands = [];
		// filter all non-activesync commands
		foreach (self::$supportedCommands as $c => $v) {
			if (!self::checkCommandOptions($c, self::NOACTIVESYNCCOMMAND) &&
					self::checkCommandOptions($c, self::GetSupportedASVersion())) {
				$asCommands[] = Utils::GetCommandFromCode($c);
			}
		}

		$commands = implode(',', $asCommands);
		SLog::Write(LOGLEVEL_DEBUG, "GSync::GetSupportedCommands(): " . $commands);

		return "MS-ASProtocolCommands: " . $commands;
	}

	/**
	 * Loads and instantiates a request processor for a command.
	 *
	 * @param int $commandCode
	 *
	 * @return RequestProcessor sub-class
	 */
	public static function GetRequestHandlerForCommand($commandCode) {
		if (!array_key_exists($commandCode, self::$supportedCommands) ||
				!array_key_exists(self::REQUESTHANDLER, self::$supportedCommands[$commandCode])) {
			throw new FatalNotImplementedException(sprintf("Command '%s' has no request handler or class", Utils::GetCommandFromCode($commandCode)));
		}

		$class = self::$supportedCommands[$commandCode][self::REQUESTHANDLER];
		$handlerclass = REAL_BASE_PATH . "lib/request/" . strtolower($class) . ".php";

		if (is_file($handlerclass)) {
			include $handlerclass;
		}

		if (class_exists($class)) {
			return new $class();
		}

		throw new FatalNotImplementedException(sprintf("Request handler '%s' can not be loaded", $class));
	}

	/**
	 * Indicates if a commands requires authentication or not.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public static function CommandNeedsAuthentication($commandCode) {
		$stat = !self::checkCommandOptions($commandCode, self::UNAUTHENTICATED);
		SLog::Write(LOGLEVEL_DEBUG, sprintf("GSync::CommandNeedsAuthentication(%d): %s", $commandCode, Utils::PrintAsString($stat)));

		return $stat;
	}

	/**
	 * Indicates if the Provisioning check has to be forced on these commands.
	 *
	 * @param string $commandCode
	 *
	 * @return bool
	 */
	public static function CommandNeedsProvisioning($commandCode) {
		$stat = !self::checkCommandOptions($commandCode, self::UNPROVISIONED);
		SLog::Write(LOGLEVEL_DEBUG, sprintf("GSync::CommandNeedsProvisioning(%s): %s", $commandCode, Utils::PrintAsString($stat)));

		return $stat;
	}

	/**
	 * Indicates if these commands expect plain text input instead of wbxml.
	 *
	 * @param string $commandCode
	 *
	 * @return bool
	 */
	public static function CommandNeedsPlainInput($commandCode) {
		$stat = self::checkCommandOptions($commandCode, self::PLAININPUT);
		SLog::Write(LOGLEVEL_DEBUG, sprintf("GSync::CommandNeedsPlainInput(%d): %s", $commandCode, Utils::PrintAsString($stat)));

		return $stat;
	}

	/**
	 * Indicates if the command to be executed operates on the hierarchy.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public static function HierarchyCommand($commandCode) {
		$stat = self::checkCommandOptions($commandCode, self::HIERARCHYCOMMAND);
		SLog::Write(LOGLEVEL_DEBUG, sprintf("GSync::HierarchyCommand(%d): %s", $commandCode, Utils::PrintAsString($stat)));

		return $stat;
	}

	/**
	 * Checks access types of a command.
	 *
	 * @param string $commandCode a commandCode
	 * @param string $option      e.g. self::UNAUTHENTICATED
	 *
	 * @throws FatalNotImplementedException
	 *
	 * @return object StateMachine
	 */
	private static function checkCommandOptions($commandCode, $option) {
		if ($commandCode === false) {
			return false;
		}

		if (!array_key_exists($commandCode, self::$supportedCommands)) {
			throw new FatalNotImplementedException(sprintf("Command '%s' is not supported", Utils::GetCommandFromCode($commandCode)));
		}

		$capa = self::$supportedCommands[$commandCode];
		$defcapa = in_array($option, $capa, true);

		// if not looking for a default capability, check if the command is supported since a previous AS version
		if (!$defcapa) {
			$verkey = array_search($option, self::$supportedASVersions, true);
			if ($verkey !== false && ($verkey >= array_search($capa[0], self::$supportedASVersions))) {
				$defcapa = true;
			}
		}

		return $defcapa;
	}
}
