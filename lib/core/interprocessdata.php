<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Class takes care of interprocess communication for different purposes
 * using a backend implementing IIpcBackend
 */

abstract class InterProcessData {
	public const CLEANUPTIME = 1;

	protected static $devid;
	protected static $pid;
	protected static $user;
	protected static $start;
	protected $type;
	protected $allocate;

	/**
	 * @var IIpcProvider
	 */
	private $ipcProvider;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if (!isset($this->type) || !isset($this->allocate)) {
			throw new FatalNotImplementedException(sprintf("Class InterProcessData can not be initialized. Subclass %s did not initialize type and allocable memory.", get_class($this)));
		}

		try {
			// ZP-987: use an own mutex + storage key for each device on non-shared-memory IPC
			// this method is not suitable for the TopCollector atm
			$type = Request::GetDeviceID();
			$this->ipcProvider = GSync::GetRedis();
		}
		catch (Exception $e) {
			// ipcProvider could not initialise
			SLog::Write(LOGLEVEL_ERROR, sprintf("%s could not initialise IPC Redis provider: %s", get_class($this), $e->getMessage()));
		}
	}

	/**
	 * Initializes internal parameters.
	 *
	 * @return bool
	 */
	protected function initializeParams() {
		if (!isset(self::$devid)) {
			self::$devid = Request::GetDeviceID();
			self::$pid = @getmypid();
			self::$user = Request::GetAuthUserString(); // we want to see everything here
			self::$start = time();
			if (!self::$devid) {
				self::$devid = "none";
			}
		}

		return true;
	}

	/**
	 * Returns the underlying redis connection object.
	 *
	 * @return RedisConnection
	 */
	protected function getRedis() {
		return $this->ipcProvider;
	}

	/**
	 * Reinitializes the IPC data by removing, detaching and re-allocating it.
	 *
	 * @return bool
	 */
	public function ReInitIPC() {
		// TODO: do we need this?
		return false;
	}

	/**
	 * Cleans up the IPC data block.
	 *
	 * @return bool
	 */
	public function Clean() {
		// TODO: do we need this?
		return false;
	}

	/**
	 * Indicates if the IPC is active.
	 *
	 * @return bool
	 */
	public function IsActive() {
		if (!$this->getRedis()) {
			return false;
		}

		return true;
	}

	/**
	 * Blocks the class mutex.
	 * Method blocks until mutex is available!
	 * ATTENTION: make sure that you *always* release a blocked mutex!
	 *
	 * @return bool
	 */
	protected function blockMutex() {
		return true;
	}

	/**
	 * Releases the class mutex.
	 * After the release other processes are able to block the mutex themselves.
	 *
	 * @return bool
	 */
	protected function releaseMutex() {
		return true;
	}

	/**
	 * Indicates if the requested variable is available in IPC data.
	 *
	 * @param int $id int indicating the variable
	 *
	 * @return bool
	 */
	protected function hasData($id = 2) {
		return $this->ipcProvider ? $this->ipcProvider->get()->exists($id) : false;
	}

	/**
	 * Returns the requested variable from IPC data.
	 *
	 * @param int $id int indicating the variable
	 *
	 * @return mixed
	 */
	protected function getData($id = 2) {
		return $this->ipcProvider ? json_decode($this->ipcProvider->getKey($id), true) : null;
	}

	/**
	 * Writes the transmitted variable to IPC data.
	 * Subclasses may never use an id < 2!
	 *
	 * @param mixed $data data which should be saved into IPC data
	 * @param int   $id   int indicating the variable (bigger than 2!)
	 * @param mixed $ttl
	 *
	 * @return bool
	 */
	protected function setData($data, $id = 2, $ttl = -1) {
		return $this->ipcProvider ? $this->ipcProvider->setKey($id, json_encode($data), $ttl) : false;
	}

	protected function setDeviceUserData($key, $data, $devid, $user, $subkey = -1, $doCas = false, $rawdata = false) {
		if (!$this->ipcProvider) {
			return false;
		}
		$compKey = $this->getComposedKey($devid, $user, $subkey);
		$ok = false;

		// overwrite
		if (!$doCas) {
			$ok = ($this->ipcProvider->get()->hset($key, $compKey, json_encode($data)) !== false);
		}
		// merge data and do CAS on the $compKey
		elseif ($doCas == "merge") {
			$okCount = 0;
			// TODO: make this configurable (retrycount)?
			while (!$ok && $okCount < 5) {
				$newData = $data;
				// step 1:  get current data
				$_rawdata = $this->ipcProvider->get()->hget($key, $compKey);
				// step 2:  if data exists, merge it with the new data. Keys within
				//          within the new data overwrite possible existing old data (update).
				if ($_rawdata && $_rawdata !== null) {
					$_old = json_decode($_rawdata, true);
					$newData = array_merge($_old, $data);
				}
				// step 3:  replace old with new data
				$ok = $this->ipcProvider->CASHash($key, $compKey, $_rawdata, json_encode($newData));
				if (!$ok) {
					++$okCount;
					// retry in 0.1s
					// TODO: make this configurable?
					usleep(1000000);
				}
			}
		}
		// replace data and do CAS on the $compKey - fail hard if CAS fails
		elseif ($doCas == "replace") {
			if (!$rawdata) {
				$ok = ($this->ipcProvider->get()->hset($key, $compKey, json_encode($data)) !== false);
			}
			else {
				$ok = $this->ipcProvider->CASHash($key, $compKey, $rawdata, json_encode($data));
			}
		}
		// delete keys of data and do CAS on the $compKey
		elseif ($doCas == "deletekeys") {
			$okCount = 0;
			// TODO: make this configurable (retrycount)?
			while (!$ok && $okCount < 5) {
				// step 1:  get current data
				$_rawdata = $this->ipcProvider->get()->hget($key, $compKey);
				$newData = json_decode($_rawdata, true);
				# no data to delete from, done
				if (!is_array($newData) || count($newData) == 0) {
					break;
				}
				// step 2:  if data exists, delete the keys of $data from it
				foreach ($data as $delKey) {
					unset($newData[$delKey]);
				}
				$rawNewData = json_encode($newData);
				// step 3:  check if the data actually changed (not the correctest way to compare these, but still valid for our data)
				if ($_rawdata == $rawNewData) {
					break;
				}
				// step 4:  replace old with new data
				$ok = $this->ipcProvider->CASHash($key, $compKey, $_rawdata, $rawNewData);
				if (!$ok) {
					++$okCount;
					// TODO: make this configurable?
					// retry in 0.1s
					usleep(100000);
					SLog::Write(LOGLEVEL_DEBUG, "InterProcessData: setDeviceUserData CAS failed, retrying...");
				}
			}
		}

		return $ok;
	}

	protected function getDeviceUserData($key, $devid, $user, $subkey = -1, $returnRaw = false) {
		$compKey = $this->getComposedKey($devid, $user, $subkey);
		$_rawdata = false;
		if ($this->ipcProvider) {
			$_rawdata = $this->ipcProvider->get()->hget($key, $compKey);
		}
		if ($returnRaw) {
			if ($_rawdata) {
				return [json_decode($_rawdata, true), $_rawdata];
			}

			return [[], false];
		}

		if ($_rawdata) {
			return json_decode($_rawdata, true);
		}

		return [];
	}

	protected function delDeviceUserData($key, $devid, $user, $subkey = -1) {
		$compKey = $this->getComposedKey($devid, $user, $subkey);

		return $this->ipcProvider->get()->hdel($key, $compKey);
	}

	protected function getAllDeviceUserData($key) {
		$_data = [];
		$raw = $this->ipcProvider->get()->hGetAll($key);
		foreach ($raw as $compKey => $status) {
			$_linedata = json_decode($status, true);
			list($devid, $user, $subkey) = explode("|-|", $compKey);
			if (!isset($_data[$devid])) {
				$_data[$devid] = [];
			}
			if (!$subkey) {
				$_data[$devid][$user] = $_data;
			}
			else {
				if (!isset($_data[$devid][$user])) {
					$_data[$devid][$user] = [];
				}
				$_data[$devid][$user][$subkey] = $_linedata;
			}
		}

		return $_data;
	}

	protected function getRawDeviceUserData($key) {
		return $this->ipcProvider->get()->hGetAll($key);
	}

	protected function getComposedKey($key1, $key2, $key3 = -1) {
		$_k = $key1;
		if ($key2 > -1) {
			$_k .= "|-|" . $key2;
		}

		if ($key3 > -1) {
			$_k .= "|-|" . $key3;
		}

		return $_k;
	}
}
