<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2026 grommunio GmbH
 *
 * Logging functionalities
 */

class Syslog extends Log {
	protected $program_name = '';
	protected $host;
	protected $port;

	/**
	 * @return string
	 */
	public function GetProgramName() {
		return $this->program_name;
	}

	/**
	 * @param string $value
	 */
	public function SetProgramName($value) {
		$this->program_name = $value;
	}

	/**
	 * @return string
	 */
	public function GetHost() {
		return $this->host;
	}

	/**
	 * @param string $value
	 */
	public function SetHost($value) {
		$this->host = $value;
	}

	/**
	 * @return int
	 */
	public function GetPort() {
		return $this->port;
	}

	/**
	 * @param int $value
	 */
	public function SetPort($value) {
		if (is_numeric($value)) {
			$this->port = (int) $value;
		}
	}

	/**
	 * Constructor.
	 * Sets configured values if no parameters are given.
	 *
	 * @param string $program_name
	 * @param string $host
	 * @param string $port
	 */
	public function __construct($program_name = null, $host = null, $port = null) {
		parent::__construct();

		if (is_null($program_name)) {
			$program_name = LOG_SYSLOG_PROGRAM;
		}
		if (is_null($host)) {
			$host = LOG_SYSLOG_HOST;
		}
		if (is_null($port)) {
			$port = LOG_SYSLOG_PORT;
		}

		$this->SetProgramName($program_name);
		$this->SetHost($host);
		$this->SetPort($port);
	}

	/**
	 * Return the full program name for syslog.
	 * The backends are part of the core, so this is always grommunio-sync/core.
	 *
	 * @return string
	 */
	protected function GenerateProgramName() {
		return $this->GetProgramName() . '/core';
	}

	/**
	 * Maps the grommunio-sync loglevel with those of syslog.
	 *
	 * @param int $loglevel
	 *
	 * @return int one of many LOG_* syslog level
	 */
	protected function GetGsyncLogLevelToSyslogLogLevel($loglevel) {
		return match ($loglevel) {
			LOGLEVEL_FATAL => LOG_ALERT,
			LOGLEVEL_ERROR => LOG_ERR,
			LOGLEVEL_WARN => LOG_WARNING,
			LOGLEVEL_INFO => LOG_INFO,
			LOGLEVEL_DEBUG => LOG_DEBUG,
			LOGLEVEL_WBXML => LOG_DEBUG,
			LOGLEVEL_DEVICEID => LOG_DEBUG,
			LOGLEVEL_WBXMLSTACK => LOG_DEBUG,
			default => null,
		};
	}

	/**
	 * Build the log string for syslog.
	 *
	 * @param int    $loglevel
	 * @param string $message
	 * @param bool   $includeUserDevice puts username and device in the string, default: true
	 *
	 * @return string
	 */
	public function BuildLogString($loglevel, $message, $includeUserDevice = true) {
		$log = $this->GetLogLevelString($loglevel); // Never pad syslog log because syslog log are usually read with a software.
		// when the users differ, we need to log both
		if (strcasecmp($this->GetAuthUser(), $this->GetUser()) == 0) {
			$log .= ' [' . $this->GetUser() . ']';
		}
		else {
			$log .= ' [' . $this->GetUser() . Request::IMPERSONATE_DELIM . $this->GetAuthUser() . ']';
		}
		if ($loglevel >= LOGLEVEL_DEVICEID) {
			$log .= '[' . $this->GetDevid() . ']';
		}
		$log .= ' ' . $message;

		return $log;
	}

	//
	// Implementation of Log
	//

	/**
	 * Writes a log message to the general log.
	 *
	 * @param int    $loglevel
	 * @param string $message
	 */
	protected function Write($loglevel, $message) {
		if ($this->GetHost() && $this->GetPort()) {
			$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
			$facility = 1; // user level
			$pri = ($facility * 8) + $loglevel; // multiplying the Facility number by 8 + adding the level
			$data = $this->BuildLogString($loglevel, $message);
			if (strlen(trim($data)) > 0) {
				$syslog_message = "<{$pri}>" . date('M d H:i:s ') . '[' . $this->GetProgramName() . ']: ' . $data;
				socket_sendto($sock, $syslog_message, strlen($syslog_message), 0, $this->GetHost(), $this->GetPort());
			}
			socket_close($sock);
		}
		else {
			openlog($this->GenerateProgramName(), LOG_PID, LOG_SYSLOG_FACILITY);
			syslog(
				$this->GetGsyncLogLevelToSyslogLogLevel($loglevel),
				$this->BuildLogString($loglevel, $message)
			);
		}
	}

	/**
	 * This function is used as an event for log implementer.
	 * It happens when the a call to the Log function is finished.
	 *
	 * @param mixed $loglevel
	 * @param mixed $message
	 */
	public function WriteForUser($loglevel, $message) {
		$this->Write(LOGLEVEL_DEBUG, $message); // Always pass the logleveldebug so it uses syslog level LOG_DEBUG
	}
}
