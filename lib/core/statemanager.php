<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * The StateManager uses a IStateMachine implementation to save data. SyncKey's
 * are of the form {UUID}N, in which UUID is allocated during the first sync,
 * and N is incremented for each request to 'GetNewSyncKey()'. A sync state is
 * simple an opaque string value that can differ for each backend used -
 * normally a list of items as the backend has sent them to the PIM. The
 * backend can then use this backend information to compute the increments with
 * current data.
 *
 * See IStateMachine for additional information.
 */

class StateManager {
	public const FIXEDHIERARCHYCOUNTER = 99999;

	// backend storage types
	public const BACKENDSTORAGE_PERMANENT = 1;
	public const BACKENDSTORAGE_STATE = 2;

	private $statemachine;
	private $device;
	private $hierarchyOperation = false;
	private $deleteOldStates = false;

	private $foldertype;
	private $uuid;
	private $oldStateCounter;
	private $newStateCounter;
	private $synchedFolders;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->statemachine = GSync::GetStateMachine();
		$this->hierarchyOperation = GSync::HierarchyCommand(Request::GetCommandCode());
		$this->deleteOldStates = (Request::GetCommandCode() === GSync::COMMAND_SYNC || $this->hierarchyOperation);
		$this->synchedFolders = [];
	}

	/**
	 * Prevents the StateMachine from removing old states.
	 */
	public function DoNotDeleteOldStates() {
		$this->deleteOldStates = false;
	}

	/**
	 * Sets an ASDevice for the Statemanager to work with.
	 *
	 * @param ASDevice $device
	 *
	 * @return bool
	 */
	public function SetDevice(&$device) {
		$this->device = $device;

		return true;
	}

	/**
	 * Returns an array will all synchronized folderids.
	 *
	 * @return array
	 */
	public function GetSynchedFolders() {
		$synched = [];
		foreach ($this->device->GetAllFolderIds() as $folderid) {
			$uuid = $this->device->GetFolderUUID($folderid);
			if ($uuid) {
				$synched[] = $folderid;
			}
		}

		return $synched;
	}

	/**
	 * Returns a folder state (SyncParameters) for a folder id.
	 *
	 * @param string $folderid
	 * @param bool   $fromCacheIfAvailable if set to false, the folderdata is always reloaded, default: true
	 *
	 * @return SyncParameters
	 */
	public function GetSynchedFolderState($folderid, $fromCacheIfAvailable = true) {
		// new SyncParameters are cached
		if ($fromCacheIfAvailable && isset($this->synchedFolders[$folderid])) {
			return $this->synchedFolders[$folderid];
		}

		$uuid = $this->device->GetFolderUUID($folderid);
		if ($uuid) {
			try {
				$data = $this->statemachine->GetState($this->device->GetDeviceId(), IStateMachine::FOLDERDATA, $uuid);
				if ($data !== false) {
					$this->synchedFolders[$folderid] = $data;
				}
			}
			catch (StateNotFoundException $ex) {
			}
		}

		if (!isset($this->synchedFolders[$folderid])) {
			$this->synchedFolders[$folderid] = new SyncParameters();
		}

		return $this->synchedFolders[$folderid];
	}

	/**
	 * Saves a folder state - SyncParameters object.
	 *
	 * @param SyncParameters $spa
	 *
	 * @return bool
	 */
	public function SetSynchedFolderState($spa) {
		// make sure the current uuid is linked on the device for the folder.
		// if not, old states will be automatically removed and the new ones linked
		self::LinkState($this->device, $spa->GetUuid(), $spa->GetFolderId());

		$spa->SetReferencePolicyKey($this->device->GetPolicyKey());

		return $this->statemachine->SetState($spa, $this->device->GetDeviceId(), IStateMachine::FOLDERDATA, $spa->GetUuid());
	}

	/**
	 * Gets the new sync key for a specified sync key. The new sync state must be
	 * associated to this sync key when calling SetSyncState().
	 *
	 * @param string $synckey
	 *
	 * @return string
	 */
	public function GetNewSyncKey($synckey) {
		if (!isset($synckey) || $synckey == "0" || $synckey == false) {
			$this->uuid = $this->getNewUuid();
			$this->newStateCounter = 1;
		}
		else {
			list($uuid, $counter) = self::ParseStateKey($synckey);
			$this->uuid = $uuid;
			$this->newStateCounter = $counter + 1;
		}

		return self::BuildStateKey($this->uuid, $this->newStateCounter);
	}

	/**
	 * Returns a counter zero SyncKey.
	 *
	 * @return string
	 */
	public function GetZeroSyncKey() {
		return self::BuildStateKey($this->getNewUuid(), 0);
	}

	/**
	 * Gets the state for a specified synckey (uuid + counter).
	 *
	 * @param string $synckey
	 * @param bool   $forceHierarchyLoading, default: false
	 *
	 * @throws StateInvalidException, StateNotFoundException
	 *
	 * @return string
	 */
	public function GetSyncState($synckey, $forceHierarchyLoading = false) {
		// No sync state for sync key '0'
		if ($synckey == "0") {
			$this->oldStateCounter = 0;

			return "";
		}

		// Check if synckey is allowed and set uuid and counter
		list($this->uuid, $this->oldStateCounter) = self::ParseStateKey($synckey);

		// make sure the hierarchy cache is in place
		if ($this->hierarchyOperation || $forceHierarchyLoading) {
			$this->loadHierarchyCache($forceHierarchyLoading);
		}

		// the state machine will discard any sync states before this one, as they are no longer required
		return $this->statemachine->GetState($this->device->GetDeviceId(), IStateMachine::DEFTYPE, $this->uuid, $this->oldStateCounter, $this->deleteOldStates);
	}

	/**
	 * Writes the sync state to a new synckey.
	 *
	 * @param string $synckey
	 * @param string $syncstate
	 * @param string $folderid  (opt) the synckey is associated with the folder - should always be set when performing CONTENT operations
	 *
	 * @throws StateInvalidException
	 *
	 * @return bool
	 */
	public function SetSyncState($synckey, $syncstate, $folderid = false) {
		$internalkey = self::BuildStateKey($this->uuid, $this->newStateCounter);
		if ($this->oldStateCounter != 0 && $synckey != $internalkey) {
			throw new StateInvalidException(sprintf("Unexpected synckey value oldcounter: '%s' synckey: '%s' internal key: '%s'", $this->oldStateCounter, $synckey, $internalkey));
		}

		// make sure the hierarchy cache is also saved
		if ($this->hierarchyOperation) {
			$this->saveHierarchyCache();
		}

		// announce this uuid to the device, while old uuid/states should be deleted
		self::LinkState($this->device, $this->uuid, $folderid);

		return $this->statemachine->SetState($syncstate, $this->device->GetDeviceId(), IStateMachine::DEFTYPE, $this->uuid, $this->newStateCounter);
	}

	/**
	 * Gets the failsave sync state for the current synckey.
	 *
	 * @return array|bool false if not available
	 */
	public function GetSyncFailState() {
		if (!$this->uuid) {
			return false;
		}

		try {
			return $this->statemachine->GetState($this->device->GetDeviceId(), IStateMachine::FAILSAVE, $this->uuid, $this->oldStateCounter, $this->deleteOldStates);
		}
		catch (StateNotFoundException $snfex) {
			return false;
		}
	}

	/**
	 * Writes the failsave sync state for the current (old) synckey.
	 *
	 * @param mixed $syncstate
	 *
	 * @return bool
	 */
	public function SetSyncFailState($syncstate) {
		if ($this->oldStateCounter == 0) {
			return false;
		}

		return $this->statemachine->SetState($syncstate, $this->device->GetDeviceId(), IStateMachine::FAILSAVE, $this->uuid, $this->oldStateCounter);
	}

	/**
	 * Gets the backendstorage data.
	 *
	 * @param int $type permanent or state related storage
	 *
	 * @throws StateNotYetAvailableException, StateNotFoundException
	 *
	 * @return mixed
	 */
	public function GetBackendStorage($type = self::BACKENDSTORAGE_PERMANENT) {
		if ($type == self::BACKENDSTORAGE_STATE) {
			if (!$this->uuid) {
				throw new StateNotYetAvailableException();
			}

			return $this->statemachine->GetState($this->device->GetDeviceId(), IStateMachine::BACKENDSTORAGE, $this->uuid, $this->oldStateCounter, $this->deleteOldStates);
		}

		return $this->statemachine->GetState($this->device->GetDeviceId(), IStateMachine::BACKENDSTORAGE, false, $this->device->GetFirstSyncTime(), false);
	}

	/**
	 * Writes the backendstorage data.
	 *
	 * @param mixed $data
	 * @param int   $type permanent or state related storage
	 *
	 * @throws StateNotYetAvailableException, StateNotFoundException
	 *
	 * @return int amount of bytes saved
	 */
	public function SetBackendStorage($data, $type = self::BACKENDSTORAGE_PERMANENT) {
		if ($type == self::BACKENDSTORAGE_STATE) {
			if (!$this->uuid) {
				throw new StateNotYetAvailableException();
			}

			// TODO serialization should be done in the StateMachine
			return $this->statemachine->SetState($data, $this->device->GetDeviceId(), IStateMachine::BACKENDSTORAGE, $this->uuid, $this->newStateCounter);
		}

		return $this->statemachine->SetState($data, $this->device->GetDeviceId(), IStateMachine::BACKENDSTORAGE, false, $this->device->GetFirstSyncTime());
	}

	/**
	 * Initializes the HierarchyCache for legacy syncs
	 * this is for AS 1.0 compatibility:
	 * save folder information synched with GetHierarchy()
	 * handled by StateManager.
	 *
	 * @param string $folders Array with folder information
	 *
	 * @return bool
	 */
	public function InitializeFolderCache($folders) {
		if (!is_array($folders)) {
			return false;
		}

		if (!isset($this->device)) {
			throw new FatalException("ASDevice not initialized");
		}

		// redeclare this operation as hierarchyOperation
		$this->hierarchyOperation = true;

		// as there is no hierarchy uuid, we have to create one
		$this->uuid = $this->getNewUuid();
		$this->newStateCounter = self::FIXEDHIERARCHYCOUNTER;

		// initialize legacy HierarchCache
		$this->device->SetHierarchyCache($folders);

		// force saving the hierarchy cache!
		return $this->saveHierarchyCache(true);
	}

	/*----------------------------------------------------------------------------------------------------------
	 * static StateManager methods
	 */

	/**
	 * Links a folderid to the a UUID
	 * Old states are removed if an folderid is linked to a new UUID
	 * assisting the StateMachine to get rid of old data.
	 *
	 * @param ASDevice $device
	 * @param string   $uuid     the uuid to link to
	 * @param string   $folderid (opt) if not set, hierarchy state is linked
	 * @param mixed    $newUuid
	 *
	 * @return bool
	 */
	public static function LinkState(&$device, $newUuid, $folderid = false) {
		$savedUuid = $device->GetFolderUUID($folderid);
		// delete 'old' states!
		if ($savedUuid != $newUuid) {
			// remove states but no need to notify device
			self::UnLinkState($device, $folderid, false);

			SLog::Write(LOGLEVEL_DEBUG, sprintf("StateManager::linkState(#ASDevice, '%s','%s'): linked to uuid '%s'.", $newUuid, (($folderid === false) ? 'HierarchyCache' : $folderid), $newUuid));

			return $device->SetFolderUUID($newUuid, $folderid);
		}

		return true;
	}

	/**
	 * UnLinks all states from a folder id
	 * Old states are removed assisting the StateMachine to get rid of old data.
	 * The UUID is then removed from the device.
	 *
	 * @param ASDevice $device
	 * @param string   $folderid
	 * @param bool     $removeFromDevice       indicates if the device should be
	 *                                         notified that the state was removed
	 * @param bool     $retrieveUUIDFromDevice indicates if the UUID should be retrieved from
	 *                                         device. If not true this parameter will be used as UUID.
	 *
	 * @return bool
	 */
	public static function UnLinkState(&$device, $folderid, $removeFromDevice = true, $retrieveUUIDFromDevice = true) {
		if ($retrieveUUIDFromDevice === true) {
			$savedUuid = $device->GetFolderUUID($folderid);
		}
		else {
			$savedUuid = $retrieveUUIDFromDevice;
		}

		if ($savedUuid) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("StateManager::UnLinkState('%s'): saved state '%s' will be deleted.", $folderid, $savedUuid));
			GSync::GetStateMachine()->CleanStates($device->GetDeviceId(), IStateMachine::DEFTYPE, $savedUuid, self::FIXEDHIERARCHYCOUNTER * 2);
			GSync::GetStateMachine()->CleanStates($device->GetDeviceId(), IStateMachine::FOLDERDATA, $savedUuid, false, true); // CPO
			GSync::GetStateMachine()->CleanStates($device->GetDeviceId(), IStateMachine::FAILSAVE, $savedUuid, self::FIXEDHIERARCHYCOUNTER * 2);
			GSync::GetStateMachine()->CleanStates($device->GetDeviceId(), IStateMachine::BACKENDSTORAGE, $savedUuid, self::FIXEDHIERARCHYCOUNTER * 2);

			// remove all messages which could not be synched before
			$device->RemoveIgnoredMessage($folderid, false);

			if ($folderid === false && $savedUuid !== false) {
				GSync::GetStateMachine()->CleanStates($device->GetDeviceId(), IStateMachine::HIERARCHY, $savedUuid, self::FIXEDHIERARCHYCOUNTER * 2);
			}
		}
		// delete this id from the uuid cache
		if ($removeFromDevice) {
			return $device->SetFolderUUID(false, $folderid);
		}

		return true;
	}

	/**
	 * Parses a SyncKey and returns UUID and counter.
	 *
	 * @param string $synckey
	 *
	 * @throws StateInvalidException
	 *
	 * @return array uuid, counter
	 */
	public static function ParseStateKey($synckey) {
		$matches = [];
		if (!preg_match('/^\{([0-9A-Za-z-]+)\}([0-9]+)$/', $synckey, $matches)) {
			throw new StateInvalidException(sprintf("SyncKey '%s' is invalid", $synckey));
		}

		return [$matches[1], (int) $matches[2]];
	}

	/**
	 * Builds a SyncKey from a UUID and counter.
	 *
	 * @param string $uuid
	 * @param int    $counter
	 *
	 * @throws StateInvalidException
	 *
	 * @return string syncKey
	 */
	public static function BuildStateKey($uuid, $counter) {
		if (!preg_match('/^([0-9A-Za-z-]+)$/', $uuid, $matches)) {
			throw new StateInvalidException(sprintf("UUID '%s' is invalid", $uuid));
		}

		return "{" . $uuid . "}" . $counter;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * private StateManager methods
	 */

	/**
	 * Loads the HierarchyCacheState and initializes the HierarchyChache
	 * if this is an hierarchy operation.
	 *
	 * @param bool $forceLoading, default: false
	 *
	 * @throws StateNotFoundException
	 *
	 * @return bool
	 */
	private function loadHierarchyCache($forceLoading = false) {
		if (!$this->hierarchyOperation && $forceLoading == false) {
			return false;
		}

		SLog::Write(LOGLEVEL_DEBUG, sprintf("StateManager->loadHierarchyCache(): '%s-%s-%s-%d'", $this->device->GetDeviceId(), $this->uuid, IStateMachine::HIERARCHY, $this->oldStateCounter));

		// check if a full hierarchy sync might be necessary
		if ($this->device->GetFolderUUID(false) === false) {
			self::UnLinkState($this->device, false, false, $this->uuid);

			throw new StateNotFoundException("No hierarchy UUID linked to device. Requesting folder resync.");
		}

		$hierarchydata = $this->statemachine->GetState($this->device->GetDeviceId(), IStateMachine::HIERARCHY, $this->uuid, $this->oldStateCounter, $this->deleteOldStates);
		$this->device->SetHierarchyCache($hierarchydata);

		return true;
	}

	/**
	 * Saves the HierarchyCacheState of the HierarchyChache
	 * if this is an hierarchy operation.
	 *
	 * @param bool  $forceLoad   indicates if the cache should be saved also if not a hierary operation
	 * @param mixed $forceSaving
	 *
	 * @throws StateInvalidException
	 *
	 * @return bool
	 */
	private function saveHierarchyCache($forceSaving = false) {
		if (!$this->hierarchyOperation && !$forceSaving) {
			return false;
		}

		// link the hierarchy cache again, if the UUID does not match the UUID saved in the devicedata
		if (($this->uuid != $this->device->GetFolderUUID() || $forceSaving)) {
			self::LinkState($this->device, $this->uuid);
		}

		// check all folders and deleted folders to update data of ASDevice and delete old states
		$hc = $this->device->getHierarchyCache();
		foreach ($hc->GetDeletedFolders() as $delfolder) {
			self::UnLinkState($this->device, $delfolder->serverid);
		}

		foreach ($hc->ExportFolders() as $folder) {
			$this->device->SetFolderType($folder->serverid, $folder->type);
			$this->device->SetFolderBackendId($folder->serverid, $folder->BackendId);
		}

		return $this->statemachine->SetState($this->device->GetHierarchyCacheData(), $this->device->GetDeviceId(), IStateMachine::HIERARCHY, $this->uuid, $this->newStateCounter);
	}

	/**
	 * Generates a new UUID.
	 *
	 * @return string
	 */
	private function getNewUuid() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0, 0xFFFF),
			mt_rand(0, 0xFFFF),
			mt_rand(0, 0xFFFF),
			mt_rand(0, 0x0FFF) | 0x4000,
			mt_rand(0, 0x3FFF) | 0x8000,
			mt_rand(0, 0xFFFF),
			mt_rand(0, 0xFFFF),
			mt_rand(0, 0xFFFF)
		);
	}
}
