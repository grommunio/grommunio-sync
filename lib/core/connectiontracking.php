<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2020-2025 grommunio GmbH
 */

class ConnectionTracking extends InterProcessData {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// initialize super parameters
		$this->allocate = 0;
		$this->type = "grommunio-sync:connections";
		parent::__construct();

		// initialize params
		$this->initializeParams();
	}

	/**
	 * Initialize the current request.
	 *
	 * @return bool
	 */
	public function TrackConnection() {
		// use the email address here
		self::$user = Request::GetUserIdentifier();

		$connectiontracking = ["starttime" => self::$start, "pid" => self::$pid, "command" => Request::GetCommand()];

		return $this->setDeviceUserData($this->type, $connectiontracking, self::$devid, self::$user, $subkey = -1, $doCas = "replace");
	}
}
