<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Logging functionalities
 */

abstract class Log {
	/**
	 * @var string
	 */
	protected $user = '';

	/**
	 * @var string
	 */
	protected $authUser = '';

	/**
	 * @var string
	 */
	protected $devid = '';

	/**
	 * @var string
	 */
	protected $pid = '';

	/**
	 * @var array
	 */
	protected $specialLogUsers = [];

	/**
	 * Only used as a cache value for IsUserInSpecialLogUsers.
	 *
	 * @var array
	 */
	private $isUserInSpecialLogUsers = [];

	/**
	 * Only used as a cache value for IsAuthUserInSpecialLogUsers function.
	 *
	 * @var bool
	 */
	private $isAuthUserInSpecialLogUsers = false;

	/**
	 * @var array
	 */
	private $unauthMessageCache = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Returns the current user.
	 *
	 * @return string
	 */
	public function GetUser() {
		return $this->user;
	}

	/**
	 * Sets the current user.
	 *
	 * @param string $value
	 */
	public function SetUser($value) {
		$this->user = $value;
	}

	/**
	 * Returns the current authenticated user.
	 *
	 * @return string
	 */
	public function GetAuthUser() {
		return $this->authUser;
	}

	/**
	 * Sets the current authenticated user.
	 *
	 * @param string $value
	 */
	public function SetAuthUser($value) {
		$this->isAuthUserInSpecialLogUsers = false;
		$this->authUser = $value;
	}

	/**
	 * Check that the current authUser ($this->GetAuthUser) is in the special log user array.
	 * This call is equivalent to `$this->IsUserInSpecialLogUsers($this->GetAuthUser())` at the exception that this
	 * call uses cache so there won't be more than one check to the specialLogUser for the AuthUser.
	 *
	 * @return bool
	 */
	public function IsAuthUserInSpecialLogUsers() {
		if ($this->isAuthUserInSpecialLogUsers) {
			return true;
		}
		if ($this->IsUserInSpecialLogUsers($this->GetAuthUser())) {
			$this->isAuthUserInSpecialLogUsers = true;

			return true;
		}

		return false;
	}

	/**
	 * Returns the current device id.
	 *
	 * @return string
	 */
	public function GetDevid() {
		return $this->devid;
	}

	/**
	 * Sets the current device id.
	 *
	 * @param string $value
	 */
	public function SetDevid($value) {
		$this->devid = $value;
	}

	/**
	 * Returns the current PID (as string).
	 *
	 * @return string
	 */
	public function GetPid() {
		return $this->pid;
	}

	/**
	 * Sets the current PID.
	 *
	 * @param string $value
	 */
	public function SetPid($value) {
		$this->pid = $value;
	}

	/**
	 * Indicates if special log users are known.
	 *
	 * @return bool True if we do have to log some specific user. False otherwise.
	 */
	public function HasSpecialLogUsers() {
		return !empty($this->specialLogUsers) || $this->isAuthUserInSpecialLogUsers;
	}

	/**
	 * Indicates if the user is in the special log users.
	 *
	 * @param string $user
	 *
	 * @return bool
	 */
	public function IsUserInSpecialLogUsers($user) {
		if (isset($this->isUserInSpecialLogUsers[$user])) {
			return true;
		}
		if ($this->HasSpecialLogUsers() && in_array($user, $this->GetSpecialLogUsers())) {
			$this->isUserInSpecialLogUsers[$user] = true;

			return true;
		}

		return false;
	}

	/**
	 * Returns the current special log users array.
	 *
	 * @return array
	 */
	public function GetSpecialLogUsers() {
		return $this->specialLogUsers;
	}

	/**
	 * Sets the current special log users array.
	 */
	public function SetSpecialLogUsers(array $value) {
		$this->isUserInSpecialLogUsers = []; // reset cache
		$this->specialLogUsers = $value;
	}

	/**
	 * If called, the current user should get an extra log-file.
	 *
	 * If called until the user is authenticated (e.g. at the end of IBackend->Logon()) all
	 * messages logged until then will also be logged in the user file.
	 */
	public function SpecialLogUser() {
		$this->isAuthUserInSpecialLogUsers = true;
	}

	/**
	 * Logs a message with a given log level.
	 *
	 * @param int    $loglevel
	 * @param string $message
	 */
	public function Log($loglevel, $message) {
		if ($loglevel <= LOGLEVEL) {
			$this->Write($loglevel, $message);
		}
		if ($loglevel <= LOGUSERLEVEL) {
			// cache log messages for unauthenticated users
			if (!RequestProcessor::isUserAuthenticated()) {
				$this->unauthMessageCache[] = [$loglevel, $message];
			}
			// user is authenticated now
			elseif ($this->IsAuthUserInSpecialLogUsers()) {
				// something was logged before the user was authenticated and cached write it to the log
				if (!empty($this->unauthMessageCache)) {
					foreach ($this->unauthMessageCache as $authcache) {
						$this->WriteForUser($authcache[0], $authcache[1]);
					}
					$this->unauthMessageCache = [];
				}
				$this->WriteForUser($loglevel, $message);
			}
			else {
				$this->unauthMessageCache[] = [$loglevel, $message];
			}
		}

		$this->afterLog($loglevel, $message);
	}

	/**
	 * This function is used as an event for log implementer.
	 * It happens when the SLog static class is finished with the initialization of this instance.
	 */
	public function AfterInitialize() {
	}

	/**
	 * This function is used as an event for log implementer.
	 * It happens when the a call to the Log function is finished.
	 *
	 * @param mixed $loglevel
	 * @param mixed $message
	 */
	protected function afterLog($loglevel, $message) {
	}

	/**
	 * Returns the string representation of the given $loglevel.
	 * String can be padded.
	 *
	 * @param int  $loglevel one of the LOGLEVELs
	 * @param bool $pad
	 *
	 * @return string
	 */
	protected function GetLogLevelString($loglevel, $pad = false) {
		if ($pad) {
			$s = " ";
		}
		else {
			$s = "";
		}

		switch ($loglevel) {
			case LOGLEVEL_OFF:
				return "";

			case LOGLEVEL_FATAL:
				return "[FATAL]";

			case LOGLEVEL_ERROR:
				return "[ERROR]";

			case LOGLEVEL_WARN:
				return "[" . $s . "WARN]";

			case LOGLEVEL_INFO:
				return "[" . $s . "INFO]";

			case LOGLEVEL_DEBUG:
				return "[DEBUG]";

			case LOGLEVEL_WBXML:
				return "[WBXML]";

			case LOGLEVEL_DEVICEID:
				return "[DEVICEID]";

			case LOGLEVEL_WBXMLSTACK:
				return "[WBXMLSTACK]";
		}

		return "";
	}

	/**
	 * Writes a log message to the general log.
	 *
	 * @param int    $loglevel
	 * @param string $message
	 */
	abstract protected function Write($loglevel, $message);

	/**
	 * Writes a log message to the user specific log.
	 *
	 * @param int    $loglevel
	 * @param string $message
	 */
	abstract public function WriteForUser($loglevel, $message);
}
