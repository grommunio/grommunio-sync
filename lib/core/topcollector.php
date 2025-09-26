<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2023 grommunio GmbH
 *
 * Available everywhere to collect data which could be displayed in
 * grommunio-sync-top the 'persistent' flag should be used with care, so
 * there is not too much information
 */

class TopCollector extends InterProcessData {
	public const ENABLEDAT = "grommunio-sync:topenabledat";
	public const TOPDATA = "grommunio-sync:topdata";
	public const ENABLED_CACHETIME = 5; // how often in seconds to check the ipc provider if it has data for the TopCollector

	protected $preserved;
	protected $latest;
	private $disabled;
	private $checkEnabledTime;
	private $enabled;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// initialize super parameters
		$this->allocate = 2097152; // 2 MB
		$this->type = 20;
		parent::__construct();

		// initialize params
		$this->initializeParams();

		$this->preserved = [];
		// static vars come from the parent class
		$this->latest = ["pid" => self::$pid,
			"ip" => Request::GetRemoteAddr(),
			"user" => self::$user,
			"start" => self::$start,
			"devtype" => Request::GetDeviceType(),
			"devid" => self::$devid,
			"devagent" => Request::GetUserAgent(),
			"command" => Request::GetCommandCode(),
			"ended" => 0,
			"push" => false,
			"asversion" => Request::GetProtocolVersion(),
		];
		$this->disabled = (bool) (defined('TOPCOLLECTOR_DISABLED') && constant('TOPCOLLECTOR_DISABLED') === true);
		$this->checkEnabledTime = time() - self::ENABLED_CACHETIME - 1;
		$this->AnnounceInformation("initializing");
	}

	/**
	 * Destructor
	 * indicates that the process is shutting down.
	 */
	public function __destruct() {
		$this->AnnounceInformation("OK", false, true);
	}

	/**
	 * Advices all other processes that they should start/stop
	 * collecting data. The data saved is a timestamp. It has to be
	 * reactivated every couple of seconds.
	 *
	 * @param bool $stop (opt) default false (do collect)
	 *
	 * @return bool indicating if it was set to collect before
	 */
	public function CollectData($stop = false) {
		$wasEnabled = ($this->hasData(self::ENABLEDAT)) ? $this->getData(self::ENABLEDAT) : false;

		$time = time();
		if ($stop === true) {
			$time = 0;
		}

		if (!$this->setData($time, self::ENABLEDAT)) {
			return false;
		}

		return $wasEnabled;
	}

	/**
	 * Announces a string to the TopCollector.
	 *
	 * @param bool  $preserve    info should be displayed when process terminates
	 * @param bool  $terminating indicates if the process is terminating
	 * @param mixed $addinfo
	 *
	 * @return bool
	 */
	public function AnnounceInformation($addinfo, $preserve = false, $terminating = false) {
		if ($this->disabled) {
			return true;
		}

		$this->latest["addinfo"] = $addinfo;
		$this->latest["update"] = time();

		if ($terminating) {
			$this->latest["ended"] = time();
			foreach ($this->preserved as $p) {
				$this->latest["addinfo"] .= " : " . $p;
			}
		}

		if ($preserve) {
			$this->preserved[] = $addinfo;
		}

		if ($this->isEnabled()) {
			// use the pid as subkey
			$ok = $this->setDeviceUserData(self::TOPDATA, $this->latest, self::$devid, self::$user, self::$pid);
			if (!$ok) {
				SLog::Write(LOGLEVEL_WARN, "TopCollector::AnnounceInformation(): could not write to redis. grommunio-sync top will not display this data.");

				return false;
			}
		}

		return true;
	}

	/**
	 * Returns all available top data.
	 *
	 * @return array
	 */
	public function ReadLatest() {
		return $this->getAllDeviceUserData(self::TOPDATA);
	}

	/**
	 * Cleans up data collected so far.
	 *
	 * @param bool $all (optional) if set all data independently from the age is removed
	 *
	 * @return bool status
	 */
	public function ClearLatest($all = false) {
		// it's ok when doing this every 10 sec
		if ($all === false && time() % 10 != 0) {
			return true;
		}

		if ($all === true) {
			$this->getRedis()->delKey(self::TOPDATA);
		}
		else {
			foreach ($this->getRawDeviceUserData(self::TOPDATA) as $compKey => $rawline) {
				$line = json_decode((string) $rawline, true);
				// remove everything which terminated for 20 secs or is not updated for more than 120 secs
				if (($line["ended"] != 0 && time() - $line["ended"] > 20) ||
					time() - $line["update"] > 120) {
					$this->getRedis()->get()->hDel(self::TOPDATA, $compKey);
				}
			}
		}

		return true;
	}

	/**
	 * Sets a different UserAgent for this connection.
	 *
	 * @param string $agent
	 *
	 * @return bool
	 */
	public function SetUserAgent($agent) {
		$this->latest["devagent"] = $agent;

		return true;
	}

	/**
	 * Marks this process as push connection.
	 *
	 * @return bool
	 */
	public function SetAsPushConnection() {
		$this->latest["push"] = true;

		return true;
	}

	/**
	 * Reinitializes the IPC data.
	 *
	 * @return bool
	 */
	#[Override]
	public function ReInitIPC() {
		return parent::ReInitIPC();
		if (!status) {
			$this->getRedis()->delKey(self::TOPDATA);
		}

		return $status;
	}

	/**
	 * Indicates if top data should be saved or not
	 * Returns true for 10 seconds after the latest CollectData()
	 * SHOULD only be called with locked mutex!
	 *
	 * @return bool
	 */
	private function isEnabled() {
		$time = time();
		if ($this->checkEnabledTime + self::ENABLED_CACHETIME < $time) {
			$isEnabled = ($this->hasData(self::ENABLEDAT)) ? $this->getData(self::ENABLEDAT) : false;
			$this->enabled = ($isEnabled !== false && ($isEnabled + 300) > $time);
			$this->checkEnabledTime = $time;
		}

		return $this->enabled;
	}
}
