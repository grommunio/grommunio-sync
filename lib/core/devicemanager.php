<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Manages device relevant data, loop detection and device states.
 * The DeviceManager uses a IStateMachine implementation with
 * IStateMachine::DEVICEDATA to save device relevant data.
 *
 * In order to update device information in redis, DeviceManager
 * implements InterProcessData.
 */

class DeviceManager extends InterProcessData {
	// broken message indicators
	public const MSG_BROKEN_UNKNOWN = 1;
	public const MSG_BROKEN_CAUSINGLOOP = 2;
	public const MSG_BROKEN_SEMANTICERR = 4;

	public const FLD_SYNC_INITIALIZED = 1;
	public const FLD_SYNC_INPROGRESS = 2;
	public const FLD_SYNC_COMPLETED = 4;

	// new types need to be added to Request::HEX_EXTENDED2 filter
	public const FLD_ORIGIN_USER = "U";
	public const FLD_ORIGIN_CONFIG = "C";
	public const FLD_ORIGIN_SHARED = "S";
	public const FLD_ORIGIN_GAB = "G";
	public const FLD_ORIGIN_IMPERSONATED = "I";

	public const FLD_FLAGS_NONE = 0;
	public const FLD_FLAGS_SENDASOWNER = 1;
	public const FLD_FLAGS_TRACKSHARENAME = 2;
	public const FLD_FLAGS_CALENDARREMINDERS = 4;

	private $device;
	private $deviceHash;
	private $saveDevice;
	private $statemachine;
	private $stateManager;
	private $incomingData = 0;
	private $outgoingData = 0;

	private $windowSize;
	private $latestFolder;

	private $loopdetection;
	private $hierarchySyncRequired;
	private $additionalFoldersHash;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->statemachine = GSync::GetStateMachine();
		$this->deviceHash = false;
		$this->saveDevice = true;
		$this->windowSize = [];
		$this->latestFolder = false;
		$this->hierarchySyncRequired = false;

		// initialize InterProcess parameters
		$this->allocate = 0;
		$this->type = "grommunio-sync:devicesuser";
		parent::__construct();
		parent::initializeParams();
		$this->stateManager = new StateManager();

		// only continue if deviceid is set
		if (self::$devid) {
			$this->device = new ASDevice();
			$this->device->Initialize(self::$devid, Request::GetDeviceType(), Request::GetGETUser(), Request::GetUserAgent());
			$this->loadDeviceData();

			GSync::GetTopCollector()->SetUserAgent($this->device->GetDeviceUserAgent());
		}
		else {
			throw new FatalNotImplementedException("Can not proceed without a device id.");
		}

		$this->loopdetection = new LoopDetection();
		$this->loopdetection->ProcessLoopDetectionInit();
		$this->loopdetection->ProcessLoopDetectionPreviousConnectionFailed();

		$this->additionalFoldersHash = $this->getAdditionalFoldersHash();
	}

	/**
	 * Load another different device.
	 *
	 * @param ASDevice $asDevice
	 */
	public function SetDevice($asDevice) {
		// TODO: this is broken and callers should be removed/updated. ASDevice is now always overwritten.
		$this->device = $asDevice;
		$this->loadDeviceData();
		// $this->stateManager->SetDevice($this->device);
	}

	/**
	 * Returns the StateManager for the current device.
	 *
	 * @return StateManager
	 */
	public function GetStateManager() {
		return $this->stateManager;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * Device operations
	 */

	/**
	 * Announces amount of transmitted data to the DeviceManager.
	 *
	 * @param int $datacounter
	 *
	 * @return bool
	 */
	public function SentData($datacounter) {
		// TODO save this somewhere
		$this->incomingData = Request::GetContentLength();
		$this->outgoingData = $datacounter;

		return true;
	}

	/**
	 * Called at the end of the request
	 * Statistics about received/sent data is saved here.
	 *
	 * @return bool
	 */
	public function Save() {
		// TODO save other stuff

		// check if previousily ignored messages were synchronized for the current folder
		// on multifolder operations of AS14 this is done by setLatestFolder()
		if ($this->latestFolder !== false) {
			$this->checkBrokenMessages($this->latestFolder);
		}

		// update the user agent and AS version on the device
		$this->device->SetUserAgent(Request::GetUserAgent());
		$this->device->SetASVersion(Request::GetProtocolVersion());

		// data to be saved
		if ($this->device->IsDataChanged() && Request::IsValidDeviceID() && $this->saveDevice) {
			SLog::Write(LOGLEVEL_DEBUG, "DeviceManager->Save(): Device data changed");

			try {
				// check if this is the first time the device data is saved and it is authenticated. If so, link the user to the device id
				if ($this->device->IsNewDevice() && RequestProcessor::isUserAuthenticated()) {
					SLog::Write(LOGLEVEL_INFO, sprintf("Linking device ID '%s' to user '%s'", self::$devid, $this->device->GetDeviceUser()));
					$this->statemachine->LinkUserDevice($this->device->GetDeviceUser(), self::$devid);
				}

				if (RequestProcessor::isUserAuthenticated() || $this->device->GetForceSave()) {
					$this->device->lastupdatetime = time();
					$this->device->StripData();
					$this->statemachine->SetState($this->device, self::$devid, IStateMachine::DEVICEDATA);

					// update deviceuser stat in redis as well
					$this->setDeviceUserData($this->type, [self::$user => $this->device], self::$devid, -1, $doCas = "merge");
					SLog::Write(LOGLEVEL_DEBUG, "DeviceManager->Save(): Device data saved");
				}
			}
			catch (StateNotFoundException $snfex) {
				SLog::Write(LOGLEVEL_ERROR, "DeviceManager->Save(): Exception: " . $snfex->getMessage());
			}
		}

		// remove old search data
		$oldpid = $this->loopdetection->ProcessLoopDetectionGetOutdatedSearchPID();
		if ($oldpid) {
			GSync::GetBackend()->GetSearchProvider()->TerminateSearch($oldpid);
		}

		// we terminated this process
		if ($this->loopdetection) {
			$this->loopdetection->ProcessLoopDetectionTerminate();
		}

		return true;
	}

	/**
	 * Sets if the AS Device should automatically be saved when terminating the request.
	 *
	 * @param bool $doSave
	 */
	public function DoAutomaticASDeviceSaving($doSave) {
		SLog::Write(LOGLEVEL_DEBUG, "DeviceManager->DoAutomaticASDeviceSaving(): save automatically: " . Utils::PrintAsString($doSave));
		$this->saveDevice = $doSave;
	}

	/**
	 * Newer mobiles send extensive device information with the Settings command
	 * These information are saved in the ASDevice.
	 *
	 * @param SyncDeviceInformation $deviceinformation
	 *
	 * @return bool
	 */
	public function SaveDeviceInformation($deviceinformation) {
		SLog::Write(LOGLEVEL_DEBUG, "Saving submitted device information");

		// set the user agent
		if (isset($deviceinformation->useragent)) {
			$this->device->SetUserAgent($deviceinformation->useragent);
		}

		// save other information
		foreach (["model", "imei", "friendlyname", "os", "oslanguage", "phonenumber", "mobileoperator", "enableoutboundsms"] as $info) {
			if (isset($deviceinformation->{$info}) && $deviceinformation->{$info} != "") {
				$this->device->__set("device" . $info, $deviceinformation->{$info});
			}
		}

		return true;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * LEGACY AS 1.0 and WRAPPER operations
	 */

	/**
	 * Returns a wrapped Importer & Exporter to use the
	 * HierarchyChache.
	 *
	 * @see ChangesMemoryWrapper
	 *
	 * @return object HierarchyCache
	 */
	public function GetHierarchyChangesWrapper() {
		return $this->device->GetHierarchyCache();
	}

	/**
	 * Initializes the HierarchyCache for legacy syncs
	 * this is for AS 1.0 compatibility:
	 *      save folder information synched with GetHierarchy().
	 *
	 * @param string $folders Array with folder information
	 *
	 * @return bool
	 */
	public function InitializeFolderCache($folders) {
		$this->stateManager->SetDevice($this->device);

		return $this->stateManager->InitializeFolderCache($folders);
	}

	/**
	 * Returns the ActiveSync folder type for a FolderID.
	 *
	 * @param string $folderid
	 *
	 * @return bool|int boolean if no type is found
	 */
	public function GetFolderTypeFromCacheById($folderid) {
		return $this->device->GetFolderType($folderid);
	}

	/**
	 * Returns a FolderID of default classes
	 * this is for AS 1.0 compatibility:
	 *      this information was made available during GetHierarchy().
	 *
	 * @param string $class The class requested
	 *
	 * @throws NoHierarchyCacheAvailableException
	 *
	 * @return string
	 */
	public function GetFolderIdFromCacheByClass($class) {
		$folderidforClass = false;
		// look at the default foldertype for this class
		$type = GSync::getDefaultFolderTypeFromFolderClass($class);

		if ($type && $type > SYNC_FOLDER_TYPE_OTHER && $type < SYNC_FOLDER_TYPE_USER_MAIL) {
			$folderids = $this->device->GetAllFolderIds();
			foreach ($folderids as $folderid) {
				if ($type == $this->device->GetFolderType($folderid)) {
					$folderidforClass = $folderid;

					break;
				}
			}

			// Old Palm Treos always do initial sync for calendar and contacts, even if they are not made available by the backend.
			// We need to fake these folderids, allowing a fake sync/ping, even if they are not supported by the backend
			// if the folderid would be available, they would already be returned in the above statement
			if ($folderidforClass == false && ($type == SYNC_FOLDER_TYPE_APPOINTMENT || $type == SYNC_FOLDER_TYPE_CONTACT)) {
				$folderidforClass = SYNC_FOLDER_TYPE_DUMMY;
			}
		}

		SLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->GetFolderIdFromCacheByClass('%s'): '%s' => '%s'", $class, $type, $folderidforClass));

		return $folderidforClass;
	}

	/**
	 * Returns a FolderClass for a FolderID which is known to the mobile.
	 *
	 * @param string $folderid
	 *
	 * @throws NoHierarchyCacheAvailableException, NotImplementedException
	 *
	 * @return int
	 */
	public function GetFolderClassFromCacheByID($folderid) {
		// TODO check if the parent folder exists and is also being synchronized
		$typeFromCache = $this->device->GetFolderType($folderid);
		if ($typeFromCache === false) {
			throw new NoHierarchyCacheAvailableException(sprintf("Folderid '%s' is not fully synchronized on the device", $folderid));
		}

		$class = GSync::GetFolderClassFromFolderType($typeFromCache);
		if ($class === false) {
			throw new NotImplementedException(sprintf("Folderid '%s' is saved to be of type '%d' but this type is not implemented", $folderid, $typeFromCache));
		}

		return $class;
	}

	/**
	 * Returns the additional folders as SyncFolder objects.
	 *
	 * @return array of SyncFolder with backendids as keys
	 */
	public function GetAdditionalUserSyncFolders() {
		$folders = [];

		// In impersonated stores, no additional folders will be synchronized
		if (Request::GetImpersonatedUser()) {
			return $folders;
		}

		foreach ($this->device->GetAdditionalFolders() as $df) {
			if (!isset($df['flags'])) {
				$df['flags'] = 0;
				SLog::Write(LOGLEVEL_WARN, sprintf("DeviceManager->GetAdditionalUserSyncFolders(): Additional folder '%s' has no flags.", $df['name']));
			}
			if (!isset($df['parentid'])) {
				$df['parentid'] = '0';
				SLog::Write(LOGLEVEL_WARN, sprintf("DeviceManager->GetAdditionalUserSyncFolders(): Additional folder '%s' has no parentid.", $df['name']));
			}

			$folder = $this->BuildSyncFolderObject($df['store'], $df['folderid'], $df['parentid'], $df['name'], $df['type'], $df['flags'], DeviceManager::FLD_ORIGIN_SHARED);
			$folders[$folder->BackendId] = $folder;
		}

		return $folders;
	}

	/**
	 * Get the store of an additional folder.
	 *
	 * @param string $folderid
	 *
	 * @return bool|string
	 */
	public function GetAdditionalUserSyncFolder($folderid) {
		$f = $this->device->GetAdditionalFolder($folderid);
		if ($f) {
			return $f;
		}

		return false;
	}

	/**
	 * Checks if the message should be streamed to a mobile
	 * Should always be called before a message is sent to the mobile
	 * Returns true if there is something wrong and the content could break the
	 * synchronization.
	 *
	 * @param string     $id       message id
	 * @param SyncObject &$message the method could edit the message to change the flags
	 *
	 * @return bool returns true if the message should NOT be send!
	 */
	public function DoNotStreamMessage($id, &$message) {
		$folderid = $this->getLatestFolder();

		if (isset($message->parentid)) {
			$folder = $message->parentid;
		}

		// message was identified to be causing a loop
		if ($this->loopdetection->IgnoreNextMessage(true, $id, $folderid)) {
			$this->AnnounceIgnoredMessage($folderid, $id, $message, self::MSG_BROKEN_CAUSINGLOOP);

			return true;
		}

		// message is semantically incorrect
		if (!$message->Check(true)) {
			$this->AnnounceIgnoredMessage($folderid, $id, $message, self::MSG_BROKEN_SEMANTICERR);

			return true;
		}

		// check if this message is broken
		if ($this->device->HasIgnoredMessage($folderid, $id)) {
			// reset the flags so the message is always streamed with <Add>
			$message->flags = false;

			// track the broken message in the loop detection
			$this->loopdetection->SetBrokenMessage($folderid, $id);
		}

		return false;
	}

	/**
	 * Removes device information about a broken message as it is been removed from the mobile.
	 *
	 * @param string $id message id
	 *
	 * @return bool
	 */
	public function RemoveBrokenMessage($id) {
		$folderid = $this->getLatestFolder();
		if ($this->device->RemoveIgnoredMessage($folderid, $id)) {
			SLog::Write(LOGLEVEL_INFO, sprintf("DeviceManager->RemoveBrokenMessage('%s', '%s'): cleared data about previously ignored message", $folderid, $id));

			return true;
		}

		return false;
	}

	/**
	 * Amount of items to me synchronized.
	 *
	 * @param string $folderid
	 * @param string $type
	 * @param int    $queuedmessages;
	 * @param mixed  $uuid
	 * @param mixed  $statecounter
	 *
	 * @return int
	 */
	public function GetWindowSize($folderid, $uuid, $statecounter, $queuedmessages) {
		if (isset($this->windowSize[$folderid])) {
			$items = $this->windowSize[$folderid];
		}
		else {
			$items = WINDOW_SIZE_MAX;
		} // 512 by default

		$this->setLatestFolder($folderid);

		// detect if this is a loop condition
		$loop = $this->loopdetection->Detect($folderid, $uuid, $statecounter, $items, $queuedmessages);
		if ($loop !== false) {
			if ($loop === true) {
				$items = ($items == 0) ? 0 : 1 + ($this->loopdetection->IgnoreNextMessage(false) ? 1 : 0);
			}
			else {
				// we got a new suggested window size
				$items = $loop;
				SLog::Write(LOGLEVEL_DEBUG, sprintf("Mobile loop pre stage detected! Forcing smaller window size of %d before entering loop detection mode", $items));
			}
		}

		if ($items >= 0 && $items <= 2) {
			SLog::Write(LOGLEVEL_WARN, sprintf("Mobile loop detected! Messages sent to the mobile will be restricted to %d items in order to identify the conflict", $items));
		}

		return $items;
	}

	/**
	 * Sets the amount of items the device is requesting.
	 *
	 * @param string $folderid
	 * @param int    $maxItems
	 *
	 * @return bool
	 */
	public function SetWindowSize($folderid, $maxItems) {
		$this->windowSize[$folderid] = $maxItems;

		return true;
	}

	/**
	 * Sets the supported fields transmitted by the device for a certain folder.
	 *
	 * @param string $folderid
	 * @param array  $fieldlist supported fields
	 *
	 * @return bool
	 */
	public function SetSupportedFields($folderid, $fieldlist) {
		return $this->device->SetSupportedFields($folderid, $fieldlist);
	}

	/**
	 * Gets the supported fields transmitted previously by the device
	 * for a certain folder.
	 *
	 * @param string $folderid
	 *
	 * @return array@boolean
	 */
	public function GetSupportedFields($folderid) {
		return $this->device->GetSupportedFields($folderid);
	}

	/**
	 * Returns the maximum filter type for a folder.
	 * This might be limited globally, per device or per folder.
	 *
	 * @param string $folderid
	 * @param mixed  $backendFolderId
	 *
	 * @return int
	 */
	public function GetFilterType($folderid, $backendFolderId) {
		global $specialSyncFilter;
		// either globally configured SYNC_FILTERTIME_MAX or ALL (no limit)
		$maxAllowed = (defined('SYNC_FILTERTIME_MAX') && SYNC_FILTERTIME_MAX > SYNC_FILTERTYPE_ALL) ? SYNC_FILTERTIME_MAX : SYNC_FILTERTYPE_ALL;

		// TODO we could/should check for a specific value for the folder, if it's available
		$maxDevice = $this->device->GetSyncFilterType();

		// ALL has a value of 0, all limitations have higher integer values, see SYNC_FILTERTYPE_ALL definition
		if ($maxDevice !== false && $maxDevice > SYNC_FILTERTYPE_ALL && ($maxAllowed == SYNC_FILTERTYPE_ALL || $maxDevice < $maxAllowed)) {
			$maxAllowed = $maxDevice;
		}

		if (is_array($specialSyncFilter)) {
			$store = GSync::GetAdditionalSyncFolderStore($backendFolderId);
			// the store is only available when this is a shared folder (but might also be statically configured)
			if ($store) {
				$origin = Utils::GetFolderOriginFromId($folderid);
				// do not limit when the owner or impersonated user is syncing!
				if ($origin == DeviceManager::FLD_ORIGIN_USER || $origin == DeviceManager::FLD_ORIGIN_IMPERSONATED) {
					SLog::Write(LOGLEVEL_DEBUG, "Not checking for specific sync limit as this is the owner/impersonated user.");
				}
				else {
					$spKey = false;
					$spFilter = false;
					// 1. step: check if there is a general limitation for the store
					if (array_key_exists($store, $specialSyncFilter)) {
						$spFilter = $specialSyncFilter[$store];
						SLog::Write(LOGLEVEL_DEBUG, sprintf("Limit sync due to configured limitation on the store: '%s': %s", $store, $spFilter));
					}

					// 2. step: check if there is a limitation for the hashed ID (for shared/configured stores)
					$spKey = $store . '/' . $folderid;
					if (array_key_exists($spKey, $specialSyncFilter)) {
						$spFilter = $specialSyncFilter[$spKey];
						SLog::Write(LOGLEVEL_DEBUG, sprintf("Limit sync due to configured limitation on the folder: '%s': %s", $spKey, $spFilter));
					}

					// 3. step: check if there is a limitation for the backendId
					$spKey = $store . '/' . $backendFolderId;
					if (array_key_exists($spKey, $specialSyncFilter)) {
						$spFilter = $specialSyncFilter[$spKey];
						SLog::Write(LOGLEVEL_DEBUG, sprintf("Limit sync due to configured limitation on the folder: '%s': %s", $spKey, $spFilter));
					}
					if ($spFilter) {
						$maxAllowed = $spFilter;
					}
				}
			}
		}

		return $maxAllowed;
	}

	/**
	 * Removes all linked states of a specific folder.
	 * During next request the folder is resynchronized.
	 *
	 * @param string $folderid
	 *
	 * @return bool
	 */
	public function ForceFolderResync($folderid) {
		SLog::Write(LOGLEVEL_INFO, sprintf("DeviceManager->ForceFolderResync('%s'): folder resync", $folderid));

		// delete folder states
		StateManager::UnLinkState($this->device, $folderid);

		return true;
	}

	/**
	 * Removes all linked states from a device.
	 * During next requests a full resync is triggered.
	 *
	 * @return bool
	 */
	public function ForceFullResync() {
		SLog::Write(LOGLEVEL_INFO, "Full device resync requested");

		// delete all other uuids
		foreach ($this->device->GetAllFolderIds() as $folderid) {
			$uuid = StateManager::UnLinkState($this->device, $folderid);
		}

		// delete hierarchy states
		StateManager::UnLinkState($this->device, false);

		return true;
	}

	/**
	 * Indicates if the hierarchy should be resynchronized based on the general folder state and
	 * if additional folders changed.
	 *
	 * @return bool
	 */
	public function IsHierarchySyncRequired() {
		$this->loadDeviceData();

		if ($this->loopdetection->ProcessLoopDetectionIsHierarchySyncAdvised()) {
			return true;
		}

		// if the hash of the additional folders changed, we have to sync the hierarchy
		if ($this->additionalFoldersHash != $this->getAdditionalFoldersHash()) {
			$this->hierarchySyncRequired = true;
		}

		// check if a hierarchy sync might be necessary
		if ($this->device->GetFolderUUID(false) === false) {
			$this->hierarchySyncRequired = true;
		}

		return $this->hierarchySyncRequired;
	}

	private function getAdditionalFoldersHash() {
		return md5(serialize($this->device->GetAdditionalFolders()));
	}

	/**
	 * Indicates if a full hierarchy resync should be triggered due to loops.
	 *
	 * @return bool
	 */
	public function IsHierarchyFullResyncRequired() {
		// do not check for loop detection, if the foldersync is not yet complete
		if ($this->GetFolderSyncComplete() === false) {
			SLog::Write(LOGLEVEL_INFO, "DeviceManager->IsHierarchyFullResyncRequired(): aborted, as exporting of folders has not yet completed");

			return false;
		}
		// check for potential process loops like described in ZP-5
		return $this->loopdetection->ProcessLoopDetectionIsHierarchyResyncRequired();
	}

	/**
	 * Adds an Exceptions to the process tracking.
	 *
	 * @param Exception $exception
	 *
	 * @return bool
	 */
	public function AnnounceProcessException($exception) {
		return $this->loopdetection->ProcessLoopDetectionAddException($exception);
	}

	/**
	 * Adds a non-ok status for a folderid to the process tracking.
	 * On 'false' a hierarchy status is assumed.
	 *
	 * @param mixed $folderid
	 * @param mixed $status
	 *
	 * @return bool
	 */
	public function AnnounceProcessStatus($folderid, $status) {
		return $this->loopdetection->ProcessLoopDetectionAddStatus($folderid, $status);
	}

	/**
	 * Announces that the current process is a push connection to the process loop
	 * detection and to the Top collector.
	 *
	 * @return bool
	 */
	public function AnnounceProcessAsPush() {
		SLog::Write(LOGLEVEL_DEBUG, "Announce process as PUSH connection");

		return $this->loopdetection->ProcessLoopDetectionSetAsPush() && GSync::GetTopCollector()->SetAsPushConnection();
	}

	/**
	 * Checks if the given counter for a certain uuid+folderid was already exported or modified.
	 * This is called when a heartbeat request found changes to make sure that the same
	 * changes are not exported twice, as during the heartbeat there could have been a normal
	 * sync request.
	 *
	 * @param string $folderid folder id
	 * @param string $uuid     synkkey
	 * @param string $counter  synckey counter
	 *
	 * @return bool indicating if an uuid+counter were exported (with changes) before
	 */
	public function CheckHearbeatStateIntegrity($folderid, $uuid, $counter) {
		return $this->loopdetection->IsSyncStateObsolete($folderid, $uuid, $counter);
	}

	/**
	 * Marks a syncstate as obsolete for Heartbeat, as e.g. an import was started using it.
	 *
	 * @param string $folderid folder id
	 * @param string $uuid     synkkey
	 * @param string $counter  synckey counter
	 *
	 * @return
	 */
	public function SetHeartbeatStateIntegrity($folderid, $uuid, $counter) {
		return $this->loopdetection->SetSyncStateUsage($folderid, $uuid, $counter);
	}

	/**
	 * Checks the data integrity of the data in the hierarchy cache and the data of the content data (synchronized folders).
	 * If a folder is deleted, the sync states could still be on the server (and being loaded by PING) while
	 * the folder is not being synchronized anymore. See also https://jira.z-hub.io/browse/ZP-1077.
	 *
	 * @return bool
	 */
	public function CheckFolderData() {
		SLog::Write(LOGLEVEL_DEBUG, "DeviceManager->CheckFolderData() checking integrity of hierarchy cache with synchronized folders");

		$hc = $this->device->GetHierarchyCache();
		$notInCache = [];
		foreach ($this->device->GetAllFolderIds() as $folderid) {
			$uuid = $this->device->GetFolderUUID($folderid);
			if ($uuid) {
				// has a UUID but is not in the cache?! This is deleted, remove the states.
				if (!$hc->GetFolder($folderid)) {
					SLog::Write(LOGLEVEL_WARN, sprintf("DeviceManager->CheckFolderData(): Folder '%s' has sync states but is not in the hierarchy cache. Removing states.", $folderid));
					StateManager::UnLinkState($this->device, $folderid);
				}
			}
		}

		return true;
	}

	/**
	 * Sets the current status of the folder.
	 *
	 * @param string $folderid   folder id
	 * @param int    $statusflag current status: DeviceManager::FLD_SYNC_INITIALIZED, DeviceManager::FLD_SYNC_INPROGRESS, DeviceManager::FLD_SYNC_COMPLETED
	 *
	 * @return
	 */
	public function SetFolderSyncStatus($folderid, $statusflag) {
		$currentStatus = $this->device->GetFolderSyncStatus($folderid);

		// status available or just initialized
		if (isset($currentStatus->{ASDevice::FOLDERSYNCSTATUS}) || $statusflag == self::FLD_SYNC_INITIALIZED) {
			// only update if there is a change
			if ((!$currentStatus || (isset($currentStatus->{ASDevice::FOLDERSYNCSTATUS}) && $statusflag !== $currentStatus->{ASDevice::FOLDERSYNCSTATUS})) &&
					$statusflag != self::FLD_SYNC_COMPLETED) {
				$this->device->SetFolderSyncStatus($folderid, $statusflag);
				SLog::Write(LOGLEVEL_DEBUG, sprintf("SetFolderSyncStatus(): set %s for %s", $statusflag, $folderid));
			}
			// if completed, remove the status
			elseif ($statusflag == self::FLD_SYNC_COMPLETED) {
				$this->device->SetFolderSyncStatus($folderid, false);
				SLog::Write(LOGLEVEL_DEBUG, sprintf("SetFolderSyncStatus(): completed for %s", $folderid));
			}
		}

		return true;
	}

	/**
	 * Indicates if a folder is synchronizing by the saved status.
	 *
	 * @param string $folderid folder id
	 *
	 * @return bool
	 */
	public function HasFolderSyncStatus($folderid) {
		$currentStatus = $this->device->GetFolderSyncStatus($folderid);

		// status available ?
		$hasStatus = isset($currentStatus->{ASDevice::FOLDERSYNCSTATUS});
		if ($hasStatus) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("HasFolderSyncStatus(): saved folder status for %s: %s", $folderid, $currentStatus->{ASDevice::FOLDERSYNCSTATUS}));
		}

		return $hasStatus;
	}

	/**
	 * Returns the indicator if the FolderSync was completed successfully  (all folders synchronized).
	 *
	 * @return bool
	 */
	public function GetFolderSyncComplete() {
		return $this->device->GetFolderSyncComplete();
	}

	/**
	 * Sets if the FolderSync was completed successfully (all folders synchronized).
	 *
	 * @param bool  $complete indicating if all folders were sent
	 * @param mixed $user
	 * @param mixed $devid
	 *
	 * @return bool
	 */
	public function SetFolderSyncComplete($complete, $user = false, $devid = false) {
		$this->device->SetFolderSyncComplete($complete);

		return true;
	}

	/**
	 * Removes the Loop detection data for a user & device.
	 *
	 * @param string $user
	 * @param string $devid
	 *
	 * @return bool
	 */
	public function ClearLoopDetectionData($user, $devid) {
		if ($user == false || $devid == false) {
			return false;
		}
		SLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->ClearLoopDetectionData(): clearing data for user '%s' and device '%s'", $user, $devid));

		return $this->loopdetection->ClearData($user, $devid);
	}

	/**
	 * Indicates if the device needs an AS version update.
	 *
	 * @return bool
	 */
	public function AnnounceASVersion() {
		$latest = GSync::GetSupportedASVersion();
		$announced = $this->device->GetAnnouncedASversion();
		$this->device->SetAnnouncedASversion($latest);

		return $announced != $latest;
	}

	/**
	 * Returns the User Agent. This data is consolidated with data from Request::GetUserAgent()
	 * and the data saved in the ASDevice.
	 *
	 * @return string
	 */
	public function GetUserAgent() {
		return $this->device->GetDeviceUserAgent();
	}

	/**
	 * Returns the backend folder id from the AS folderid known to the mobile.
	 * If the id is not known, it's returned as is.
	 *
	 * @param mixed $folderid
	 *
	 * @return bool|int returns false if the type is not set
	 */
	public function GetBackendIdForFolderId($folderid) {
		$backendId = $this->device->GetFolderBackendId($folderid);
		if (!$backendId) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->GetBackendIdForFolderId(): no backend-folderid available for '%s', returning as is.", $folderid));

			return $folderid;
		}
		SLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->GetBackendIdForFolderId(): folderid %s => %s", $folderid, $backendId));

		return $backendId;
	}

	/**
	 * Gets the AS folderid for a backendFolderId.
	 * If there is no known AS folderId a new one is being created.
	 *
	 * @param string $backendid          Backend folder id
	 * @param bool   $generateNewIdIfNew generates a new AS folderid for the case the backend folder is not known yet, default: false
	 * @param string $folderOrigin       Folder type is one of   'U' (user)
	 *                                   'C' (configured)
	 *                                   'S' (shared)
	 *                                   'G' (global address book)
	 *                                   'I' (impersonated)
	 * @param string $folderName         Folder name of the backend folder
	 *
	 * @return bool|string returns false if there is folderid known for this backendid and $generateNewIdIfNew is not set or false
	 */
	public function GetFolderIdForBackendId($backendid, $generateNewIdIfNew = false, $folderOrigin = self::FLD_ORIGIN_USER, $folderName = null) {
		if (!in_array($folderOrigin, [DeviceManager::FLD_ORIGIN_CONFIG, DeviceManager::FLD_ORIGIN_GAB, DeviceManager::FLD_ORIGIN_SHARED, DeviceManager::FLD_ORIGIN_USER, DeviceManager::FLD_ORIGIN_IMPERSONATED])) {
			SLog::Write(LOGLEVEL_WARN, sprintf("ASDevice->GetFolderIdForBackendId(): folder type '%s' is unknown in DeviceManager", $folderOrigin));
		}

		return $this->device->GetFolderIdForBackendId($backendid, $generateNewIdIfNew, $folderOrigin, $folderName);
	}

	/*----------------------------------------------------------------------------------------------------------
	 * private DeviceManager methods
	 */

	/**
	 * Loads devicedata from the StateMachine and loads it into the device.
	 *
	 * @return bool
	 */
	private function loadDeviceData() {
		if (!Request::IsValidDeviceID()) {
			return false;
		}

		try {
			$deviceHash = $this->statemachine->GetStateHash(self::$devid, IStateMachine::DEVICEDATA);
			if ($deviceHash != $this->deviceHash) {
				if ($this->deviceHash) {
					SLog::Write(LOGLEVEL_DEBUG, "DeviceManager->loadDeviceData(): Device data was changed, reloading");
				}
				$device = $this->statemachine->GetState(self::$devid, IStateMachine::DEVICEDATA);
				// TODO: case should be removed when removing ASDevice backwards compatibility
				// fallback for old grosync like devicedata
				if (($device instanceof StateObject) && isset($device->devices) && is_array($device->devices)) {
					SLog::Write(LOGLEVEL_INFO, "Found old style device, converting...");
					list($_deviceuser, $_domain) = Utils::SplitDomainUser(Request::GetGETUser());
					if (!isset($device->data->devices[$_deviceuser])) {
						SLog::Write(LOGLEVEL_INFO, "Using old style device for this request and updating when concluding");
						$device = $device->devices[$_deviceuser];
						$device->lastupdatetime = time();
					}
					else {
						SLog::Write(LOGLEVEL_WARN, sprintf("Could not find '%s' in device state. Dropping previous device state!", $_deviceuser));
					}
				}
				if (method_exists($device, 'LoadedDevice')) {
					$this->device = $device;
					$this->device->LoadedDevice();
					$this->deviceHash = $deviceHash;
				}
				else {
					SLog::Write(LOGLEVEL_WARN, "Loaded device is not a device object. Dropping new loaded state and keeping initialized object!");
				}
				$this->stateManager->SetDevice($this->device);
			}
		}
		catch (StateNotFoundException $snfex) {
			$this->hierarchySyncRequired = true;
		}
		catch (UnavailableException $uaex) {
			// This is temporary and can be ignored e.g. in PING - see https://jira.z-hub.io/browse/ZP-1054
			// If the hash was not available before we treat it like a StateNotFoundException.
			if ($this->deviceHash === false) {
				$this->hierarchySyncRequired = true;
			}
		}

		return true;
	}

	/**
	 * Called when a SyncObject is not being streamed to the mobile.
	 * The user can be informed so he knows about this issue.
	 *
	 * @param string     $folderid id of the parent folder (may be false if unknown)
	 * @param string     $id       message id
	 * @param SyncObject $message  the broken message
	 * @param string     $reason   (self::MSG_BROKEN_UNKNOWN, self::MSG_BROKEN_CAUSINGLOOP, self::MSG_BROKEN_SEMANTICERR)
	 *
	 * @return bool
	 */
	public function AnnounceIgnoredMessage($folderid, $id, SyncObject $message, $reason = self::MSG_BROKEN_UNKNOWN) {
		if ($folderid === false) {
			$folderid = $this->getLatestFolder();
		}

		$class = get_class($message);

		$brokenMessage = new StateObject();
		$brokenMessage->id = $id;
		$brokenMessage->folderid = $folderid;
		$brokenMessage->ASClass = $class;
		$brokenMessage->folderid = $folderid;
		$brokenMessage->reasonCode = $reason;
		$brokenMessage->reasonString = 'unknown cause';
		$brokenMessage->timestamp = time();
		$info = "";
		if (isset($message->subject)) {
			$info .= sprintf("Subject: '%s'", $message->subject);
		}
		if (isset($message->fileas)) {
			$info .= sprintf("FileAs: '%s'", $message->fileas);
		}
		if (isset($message->from)) {
			$info .= sprintf(" - From: '%s'", $message->from);
		}
		if (isset($message->starttime)) {
			$info .= sprintf(" - On: '%s'", strftime("%Y-%m-%d %H:%M", $message->starttime));
		}
		$brokenMessage->info = $info;
		$brokenMessage->reasonString = SLog::GetLastMessage(LOGLEVEL_WARN);

		$this->device->AddIgnoredMessage($brokenMessage);

		SLog::Write(LOGLEVEL_ERROR, sprintf("Ignored broken message (%s). Reason: '%s' Folderid: '%s' message id '%s'", $class, $reason, $folderid, $id));

		return true;
	}

	/**
	 * Called when a SyncObject was streamed to the mobile.
	 * If the message could not be sent before this data is obsolete.
	 *
	 * @param string $folderid id of the parent folder
	 * @param string $id       message id
	 *
	 * @return bool returns true if the message was ignored before
	 */
	private function announceAcceptedMessage($folderid, $id) {
		if ($this->device->RemoveIgnoredMessage($folderid, $id)) {
			SLog::Write(LOGLEVEL_INFO, sprintf("DeviceManager->announceAcceptedMessage('%s', '%s'): cleared previously ignored message as message is successfully streamed", $folderid, $id));

			return true;
		}

		return false;
	}

	/**
	 * Checks if there were broken messages streamed to the mobile.
	 * If the sync completes/continues without further errors they are marked as accepted.
	 *
	 * @param string $folderid folderid which is to be checked
	 *
	 * @return bool
	 */
	private function checkBrokenMessages($folderid) {
		// check for correctly synchronized messages of the folder
		foreach ($this->loopdetection->GetSyncedButBeforeIgnoredMessages($folderid) as $okID) {
			$this->announceAcceptedMessage($folderid, $okID);
		}

		return true;
	}

	/**
	 * Setter for the latest folder id
	 * on multi-folder operations of AS 14 this is used to set the new current folder id.
	 *
	 * @param string $folderid the current folder
	 *
	 * @return bool
	 */
	private function setLatestFolder($folderid) {
		// this is a multi folder operation
		// check on ignoredmessages before discaring the folderid
		if ($this->latestFolder !== false) {
			$this->checkBrokenMessages($this->latestFolder);
		}

		$this->latestFolder = $folderid;

		return true;
	}

	/**
	 * Getter for the latest folder id.
	 *
	 * @return string $folderid       the current folder
	 */
	private function getLatestFolder() {
		return $this->latestFolder;
	}

	/**
	 * Generates and SyncFolder object and returns it.
	 *
	 * @param string $store
	 * @param string $folderid
	 * @param string $name
	 * @param int    $type
	 * @param int    $flags
	 * @param string $folderOrigin
	 * @param mixed  $parentid
	 *
	 * @returns SyncFolder
	 */
	public function BuildSyncFolderObject($store, $folderid, $parentid, $name, $type, $flags, $folderOrigin) {
		$folder = new SyncFolder();
		$folder->BackendId = $folderid;
		$folder->serverid = $this->GetFolderIdForBackendId($folder->BackendId, true, $folderOrigin, $name);
		$folder->parentid = $this->GetFolderIdForBackendId($parentid);
		$folder->displayname = $name;
		$folder->type = $type;
		// save store as custom property which is not streamed directly to the device
		$folder->NoBackendFolder = true;
		$folder->Store = $store;
		$folder->Flags = $flags;

		return $folder;
	}

	/**
	 * Returns the device id.
	 *
	 * @return string
	 */
	public function GetDevid() {
		return self::$devid;
	}
}
