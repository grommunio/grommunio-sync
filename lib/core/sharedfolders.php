<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2022 grommunio GmbH
 *
 * Cache shared public folder hierarchy information.
 */

class SharedFolders extends InterProcessData {
	public static $instance = false;

	public static function GetSharedFolders() {
		if (!self::$instance) {
			self::$instance = new SharedFolders();
		}

		return self::$instance->GetSharedFoldersRaw();
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		// initialize super parameters
		$this->allocate = 0;
		$this->localpart = "undefined";
		$this->mainDomain = "undefined";
		if (preg_match('/^([*+!.&#$|\'\\%\/0-9a-z^_`{}=?~:-]+)@(([0-9a-z-]+\.)+[0-9a-z]{2,})$/i', nsp_getuserinfo(Request::GetUser())['primary_email'], $matches)) {
			$this->localpart = $matches[1];
			$this->mainDomain = $matches[2];
		}
		$this->type = "grommunio-sync:sharedfolders-" . $this->mainDomain;
		parent::__construct();
		// initialize params
		$this->initializeParams();

		// get cached data from redis
		$shared = $this->getDeviceUserData($this->type, $this->localpart, -1);

		// no shared folder in redis for this user, get them from the public folder
		if (empty($shared)) {
			$shared = GSync::GetBackend()->GetPublicSyncEnabledFolders();
			$this->setDeviceUserData($this->type, $shared, $this->localpart, -1);
		}
	}

	public function GetSharedFoldersRaw() {
		// get cached data from redis
		$shared = $this->getDeviceUserData($this->type, $this->localpart, -1);
		if (!$shared) {
			return [];
		}
		return $shared;
	}
}
