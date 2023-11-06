<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2022 grommunio GmbH
 *
 * Cache shared public folder hierarchy information.
 */

class SharedFolders extends InterProcessData {
	private static $instance = false;
	private $shared;
	private $updateTime;
	private $localpart;
	private $mainDomain;

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
		$this->shared = [];
		$this->updateTime = 0;
	}

	/**
	 * Returns cached shared folder data from this instance or from redis.
	 * As this is potentially called for each folder in a sync we only need to update it
	 * from redis every ping intervals to push new folders.
	 *
	 * If no data is available it's retrieved from the store via MAPI.
	 *
	 * @return array
	 */
	public function GetSharedFoldersRaw() {
		// update instance data from redis once every 29s (to catch changes between the ping intervals)
		if ($this->updateTime + 29 < time()) {
			// get cached data from redis
			list($shared, $sharedRaw) = $this->getDeviceUserData($this->type, $this->localpart, -1, -1, true);

			// no shared folder data in redis for this user, get them from the public folder and put it in redis
			if (!$sharedRaw) {
				$shared = GSync::GetBackend()->GetPublicSyncEnabledFolders();
				$this->setDeviceUserData($this->type, $shared, $this->localpart, -1);
			}
			$this->shared = $shared;
			$this->updateTime = time();
		}

		return $this->shared;
	}
}
