<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * The ASDevice holds basic data about a device, its users and
 * the linked states
 */

class ASDevice extends StateObject {
	public const UNDEFINED = -1;
	// content data
	public const FOLDERUUID = 1;
	public const FOLDERTYPE = 2;
	public const FOLDERSUPPORTEDFIELDS = 3;
	public const FOLDERSYNCSTATUS = 4;
	public const FOLDERBACKENDID = 5;

	// expected values for not set member variables
	protected $unsetdata = [
		'useragenthistory' => [],
		'hierarchyuuid' => false,
		'contentdata' => [],
		'wipestatus' => SYNC_PROVISION_RWSTATUS_NA,
		'wiperequestedby' => false,
		'wiperequestedon' => false,
		'wipeactionon' => false,
		'lastupdatetime' => 0,
		'conversationmode' => false,
		'policyhash' => false,
		'policykey' => self::UNDEFINED,
		'forcesave' => false,
		'asversion' => false,
		'ignoredmessages' => [],
		'announcedASversion' => false,
		'foldersynccomplete' => true,
		'additionalfolders' => [],
		'syncfiltertype' => false,
	];

	protected $newdevice;
	protected $hierarchyCache;
	protected $ignoredMessageIds;
	protected $backend2folderidCache;

	/**
	 * AS Device constructor.
	 */
	public function __construct() {
		$this->firstsynctime = time();
		$this->newdevice = true;
		$this->ignoredMessageIds = [];
		$this->backend2folderidCache = false;
	}

	public function Initialize($devid, $devicetype, $getuser, $useragent) {
		$this->deviceid = $devid;
		$this->devicetype = $devicetype;
		list($this->deviceuser, $this->domain) = Utils::SplitDomainUser($getuser);
		$this->useragent = $useragent;
	}

	/**
	 * Removes internal data from the object, so this data can not be exposed.
	 *
	 * @param bool $stripHierarchyCache (opt) strips the hierarchy cache - default: true
	 *
	 * @return bool
	 */
	public function StripData($stripHierarchyCache = true) {
		unset(
			$this->changed,
			$this->unsetdata,
			$this->forceSave,
			$this->newdevice,
			$this->ignoredMessageIds
		);

		$this->backend2folderidCache = false;

		if (!$stripHierarchyCache && $this->hierarchyCache !== false && $this->hierarchyCache instanceof ChangesMemoryWrapper) {
			$this->hierarchyCache->StripData();
		}
		else {
			unset($this->hierarchyCache);
		}

		return true;
	}

	/**
	 * Indicates if the object was just created.
	 *
	 * @return bool
	 */
	public function IsNewDevice() {
		return isset($this->newdevice) && $this->newdevice === true;
	}

	/**
	 * Marked as loaded device.
	 *
	 * @return bool
	 */
	public function LoadedDevice() {
		$this->newdevice = false;

		// Gsync Issue #52
		// TODO: Remove fallback code for fix missing properties
		if (!isset($this->data['deviceid']) || !$this->data['deviceid']) {
			$this->deviceid = Request::GetDeviceID();
			$this->devicetype = Request::GetDeviceType();
			$this->deviceuser = Request::GetUser();
			$this->domain = Request::GetAuthDomain();
			$this->useragent = Request::GetUserAgent();
			$this->firstsynctime = time();
			SLog::Write(LOGLEVEL_INFO, "Successfulyy set missing properties (GSync #52). Requesting data to be saved.");
		}

		return true;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * Non-standard Getter and Setter
	 */

	/**
	 * Returns the user agent of this device.
	 *
	 * @return string
	 */
	public function GetDeviceUserAgent() {
		if (!isset($this->useragent) || !$this->useragent) {
			return "unknown";
		}

		return $this->useragent;
	}

	/**
	 * Returns the user agent history of this device.
	 *
	 * @return string
	 */
	public function GetDeviceUserAgentHistory() {
		return $this->useragentHistory;
	}

	/**
	 * Sets the useragent of the current request
	 * If this value is already available, no update is done.
	 *
	 * @param string $useragent
	 *
	 * @return bool
	 */
	public function SetUserAgent($useragent) {
		if ($useragent == $this->useragent || $useragent === false || $useragent === Request::UNKNOWN) {
			return true;
		}

		// save the old user agent, if available
		if ($this->useragent != "") {
			// [] = changedate, previous user agent
			$a = $this->useragentHistory;

			// only add if this agent was not seen before
			if (!in_array([true, $this->useragent], $a)) {
				$a[] = [time(), $this->useragent];
				$this->useragentHistory = $a;
				$this->changed = true;
			}
		}
		$this->useragent = $useragent;

		return true;
	}

	/**
	 * Adds a messages which was ignored to the device data.
	 *
	 * @param StateObject $ignoredMessage
	 *
	 * @return bool
	 */
	public function AddIgnoredMessage($ignoredMessage) {
		// we should have all previously ignored messages in an id array
		if (count($this->ignoredMessages) != count($this->ignoredMessageIds)) {
			foreach ($this->ignoredMessages as $oldMessage) {
				if (!isset($this->ignoredMessageIds[$oldMessage->folderid])) {
					$this->ignoredMessageIds[$oldMessage->folderid] = [];
				}
				$this->ignoredMessageIds[$oldMessage->folderid][] = $oldMessage->id;
			}
		}

		// try not to add the same message several times
		if (isset($ignoredMessage->folderid, $ignoredMessage->id)) {
			if (!isset($this->ignoredMessageIds[$ignoredMessage->folderid])) {
				$this->ignoredMessageIds[$ignoredMessage->folderid] = [];
			}

			if (in_array($ignoredMessage->id, $this->ignoredMessageIds[$ignoredMessage->folderid])) {
				$this->RemoveIgnoredMessage($ignoredMessage->folderid, $ignoredMessage->id);
			}

			$this->ignoredMessageIds[$ignoredMessage->folderid][] = $ignoredMessage->id;
			$msges = $this->ignoredMessages;
			$msges[] = $ignoredMessage;
			$this->ignoredMessages = $msges;
			$this->changed = true;

			return true;
		}

		$msges = $this->ignoredMessages;
		$msges[] = $ignoredMessage;
		$this->ignoredMessages = $msges;
		$this->changed = true;
		SLog::Write(LOGLEVEL_WARN, "ASDevice->AddIgnoredMessage(): added message has no folder/id");

		return true;
	}

	/**
	 * Removes message in the list of ignored messages.
	 *
	 * @param string $folderid parent folder id of the message
	 * @param string $id       message id
	 *
	 * @return bool
	 */
	public function RemoveIgnoredMessage($folderid, $id) {
		// we should have all previously ignored messages in an id array
		if (count($this->ignoredMessages) != count($this->ignoredMessageIds)) {
			foreach ($this->ignoredMessages as $oldMessage) {
				if (!isset($this->ignoredMessageIds[$oldMessage->folderid])) {
					$this->ignoredMessageIds[$oldMessage->folderid] = [];
				}
				$this->ignoredMessageIds[$oldMessage->folderid][] = $oldMessage->id;
			}
		}

		$foundMessage = false;
		// there are ignored messages in that folder
		if (isset($this->ignoredMessageIds[$folderid])) {
			// resync of a folder. we should remove all previously ignored messages
			if ($id === false || in_array($id, $this->ignoredMessageIds[$folderid], true)) {
				$ignored = $this->ignoredMessages;
				$newMessages = [];
				foreach ($ignored as $im) {
					if ($im->folderid == $folderid) {
						if ($id === false || $im->id === $id) {
							$foundMessage = true;
							if (count($this->ignoredMessageIds[$folderid]) == 1) {
								unset($this->ignoredMessageIds[$folderid]);
							}
							else {
								unset($this->ignoredMessageIds[$folderid][array_search($id, $this->ignoredMessageIds[$folderid])]);
							}

							continue;
						}

						$newMessages[] = $im;
					}
				}
				$this->ignoredMessages = $newMessages;
				$this->changed = true;
			}
		}

		return $foundMessage;
	}

	/**
	 * Indicates if a message is in the list of ignored messages.
	 *
	 * @param string $folderid parent folder id of the message
	 * @param string $id       message id
	 *
	 * @return bool
	 */
	public function HasIgnoredMessage($folderid, $id) {
		// we should have all previously ignored messages in an id array
		if (count($this->ignoredMessages) != count($this->ignoredMessageIds)) {
			foreach ($this->ignoredMessages as $oldMessage) {
				if (!isset($this->ignoredMessageIds[$oldMessage->folderid])) {
					$this->ignoredMessageIds[$oldMessage->folderid] = [];
				}
				$this->ignoredMessageIds[$oldMessage->folderid][] = $oldMessage->id;
			}
		}

		$foundMessage = false;
		// there are ignored messages in that folder
		if (isset($this->ignoredMessageIds[$folderid])) {
			// resync of a folder. we should remove all previously ignored messages
			if ($id === false || in_array($id, $this->ignoredMessageIds[$folderid], true)) {
				$foundMessage = true;
			}
		}

		return $foundMessage;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * HierarchyCache and ContentData operations
	 */

	/**
	 * Sets the HierarchyCache
	 * The hierarchydata, can be:
	 *  - false     a new HierarchyCache is initialized
	 *  - array()   new HierarchyCache is initialized and data from GetHierarchy is loaded
	 *  - string    previously serialized data is loaded.
	 *
	 * @param string $hierarchydata (opt)
	 *
	 * @return bool
	 */
	public function SetHierarchyCache($hierarchydata = false) {
		if ($hierarchydata !== false && $hierarchydata instanceof ChangesMemoryWrapper) {
			$this->hierarchyCache = $hierarchydata;
			$this->hierarchyCache->CopyOldState();
		}
		else {
			$this->hierarchyCache = new ChangesMemoryWrapper();
		}

		if (is_array($hierarchydata)) {
			return $this->hierarchyCache->ImportFolders($hierarchydata);
		}

		return true;
	}

	/**
	 * Returns serialized data of the HierarchyCache.
	 *
	 * @return string
	 */
	public function GetHierarchyCacheData() {
		if (isset($this->hierarchyCache)) {
			return $this->hierarchyCache;
		}

		SLog::Write(LOGLEVEL_WARN, "ASDevice->GetHierarchyCacheData() has no data! HierarchyCache probably never initialized.");

		return false;
	}

	/**
	 * Returns the HierarchyCache Object.
	 *
	 * @return object HierarchyCache
	 */
	public function GetHierarchyCache() {
		if (!isset($this->hierarchyCache)) {
			$this->SetHierarchyCache();
		}

		SLog::Write(LOGLEVEL_DEBUG, "ASDevice->GetHierarchyCache(): " . $this->hierarchyCache->GetStat());

		return $this->hierarchyCache;
	}

	/**
	 * Returns all known folderids.
	 *
	 * @return array
	 */
	public function GetAllFolderIds() {
		if (isset($this->contentData) && is_array($this->contentData)) {
			return array_keys($this->contentData);
		}

		return [];
	}

	/**
	 * Returns a linked UUID for a folder id.
	 *
	 * @param string $folderid (opt) if not set, Hierarchy UUID is returned
	 *
	 * @return string
	 */
	public function GetFolderUUID($folderid = false) {
		if ($folderid === false) {
			return (isset($this->hierarchyUuid) && $this->hierarchyUuid !== self::UNDEFINED) ? $this->hierarchyUuid : false;
		}
		if (isset($this->contentData[$folderid]->{self::FOLDERUUID})) {
			return $this->contentData[$folderid]->{self::FOLDERUUID};
		}

		return false;
	}

	/**
	 * Link a UUID to a folder id
	 * If a boolean false UUID is sent, the relation is removed.
	 *
	 * @param string $uuid
	 * @param string $folderid (opt) if not set Hierarchy UUID is linked
	 *
	 * @return bool
	 */
	public function SetFolderUUID($uuid, $folderid = false) {
		if ($folderid === false) {
			$this->hierarchyUuid = $uuid;
			// when unsetting the hierarchycache, also remove saved contentdata and ignoredmessages
			if ($folderid === false && $uuid === false) {
				$this->contentData = [];
				$this->ignoredMessageIds = [];
				$this->ignoredMessages = [];
				$this->backend2folderidCache = false;
			}
			$this->changed = true;
		}
		else {
			$contentData = $this->contentData;

			if (!isset($contentData[$folderid])) {
				$contentData[$folderid] = new stdClass();
			}

			// check if the foldertype is set. This has to be available at this point, as generated during the first HierarchySync
			if (!isset($contentData[$folderid]->{self::FOLDERTYPE})) {
				return false;
			}

			if ($uuid) {
				$contentData[$folderid]->{self::FOLDERUUID} = $uuid;
			}
			else {
				$contentData[$folderid]->{self::FOLDERUUID} = false;
			}

			$this->contentData = $contentData;
			$this->changed = true;
		}

		return true;
	}

	/**
	 * Returns a foldertype for a folder already known to the mobile.
	 *
	 * @param string $folderid
	 *
	 * @return bool|int returns false if the type is not set
	 */
	public function GetFolderType($folderid) {
		if (isset($this->contentData[$folderid]->{self::FOLDERTYPE})) {
			return $this->contentData[$folderid]->{self::FOLDERTYPE};
		}

		return false;
	}

	/**
	 * Sets the foldertype of a folder id.
	 *
	 * @param string $folderid
	 * @param int    $foldertype ActiveSync folder type (as on the mobile)
	 *
	 * @return bool true if the type was set or updated
	 */
	public function SetFolderType($folderid, $foldertype) {
		$contentData = $this->contentData;

		if (!isset($contentData[$folderid])) {
			$contentData[$folderid] = new stdClass();
		}
		if (!isset($contentData[$folderid]->{self::FOLDERTYPE}) || $contentData[$folderid]->{self::FOLDERTYPE} != $foldertype) {
			$contentData[$folderid]->{self::FOLDERTYPE} = $foldertype;
			$this->contentData = $contentData;
			$this->changed = true;

			return true;
		}

		return false;
	}

	/**
	 * Returns the backend folder id from the AS folderid known to the mobile.
	 *
	 * @param int $folderid
	 *
	 * @return bool|int returns false if the type is not set
	 */
	public function GetFolderBackendId($folderid) {
		if (isset($this->contentData[$folderid]->{self::FOLDERBACKENDID})) {
			return $this->contentData[$folderid]->{self::FOLDERBACKENDID};
		}

		return false;
	}

	/**
	 * Sets the backend folder id of an AS folderid.
	 *
	 * @param string $folderid        the AS folder id
	 * @param string $backendfolderid the backend folder id
	 *
	 * @return bool true if the type was set or updated
	 */
	public function SetFolderBackendId($folderid, $backendfolderid) {
		if ($folderid === $backendfolderid || $folderid === false || $backendfolderid === false) {
			return false;
		}

		$contentData = $this->contentData;
		if (!isset($contentData[$folderid])) {
			$contentData[$folderid] = new stdClass();
		}
		if (!isset($contentData[$folderid]->{self::FOLDERBACKENDID}) || $contentData[$folderid]->{self::FOLDERBACKENDID} != $backendfolderid) {
			$contentData[$folderid]->{self::FOLDERBACKENDID} = $backendfolderid;
			$this->contentData = $contentData;
			$this->changed = true;

			// update the reverse cache as well
			if (is_array($this->backend2folderidCache)) {
				$this->backend2folderidCache[$backendfolderid] = $folderid;
			}

			return true;
		}

		return false;
	}

	/**
	 * Gets the AS folderid for a backendFolderId.
	 * If there is no known AS folderId a new one is being created.
	 *
	 * @param string $backendid          Backend folder id
	 * @param bool   $generateNewIdIfNew generates a new AS folderid for the case the backend folder is not known yet
	 * @param string $folderOrigin       Folder type is one of   'U' (user)
	 *                                   'C' (configured)
	 *                                   'S' (shared)
	 *                                   'G' (global address book)
	 *                                   'I' (impersonated)
	 * @param string $folderName         Folder name of the backend folder
	 *
	 * @return string
	 */
	public function GetFolderIdForBackendId($backendid, $generateNewIdIfNew, $folderOrigin, $folderName) {
		// build the backend-to-folderId backwards cache once
		if ($this->backend2folderidCache === false) {
			$this->backend2folderidCache = [];
			foreach ($this->contentData as $folderid => $data) {
				if (isset($data->{self::FOLDERBACKENDID})) {
					$this->backend2folderidCache[$data->{self::FOLDERBACKENDID}] = $folderid;
				}
			}

			// if we couldn't find any backend-folderids but there is data in contentdata, then this is an old profile.
			// do not generate new folderids in this case
			if (empty($this->backend2folderidCache) && !empty($this->contentData)) {
				SLog::Write(LOGLEVEL_DEBUG, "ASDevice->GetFolderIdForBackendId(): this is a profile without backend-folderid mapping. Returning folderids as is.");
				$this->backend2folderidCache = true;
			}
		}
		if (is_array($this->backend2folderidCache) && isset($this->backend2folderidCache[$backendid])) {
			// Use cached version only if the folderOrigin matches
			if (Utils::GetFolderOriginFromId($this->backend2folderidCache[$backendid]) == $folderOrigin) {
				return $this->backend2folderidCache[$backendid];
			}
			// if we have a different origin, we need to actively search for all synchronized folders, as they might be synched with a different origin
			// the short-id is only used if the folder is being synchronized (in contentdata) - else any cached (temporarily) ids are NOT used

			foreach ($this->contentData as $folderid => $data) {
				if (isset($data->{self::FOLDERBACKENDID}) && $data->{self::FOLDERBACKENDID} == $backendid) {
					SLog::Write(LOGLEVEL_DEBUG, sprintf("ASDevice->GetFolderIdForBackendId(): found backendid in contentdata but with different folder type. Lookup '%s' - synchronized id '%s'", $folderOrigin, $folderid));

					return $folderid;
				}
			}
		}

		// nothing found? Then it's a new one, get and add it
		if (is_array($this->backend2folderidCache) && $generateNewIdIfNew) {
			if ($folderName == null) {
				SLog::Write(LOGLEVEL_INFO, "ASDevice->GetFolderIdForBackendId(): generating a new folder id for the folder without a name");
			}
			$newHash = $this->generateFolderHash($backendid, $folderOrigin, $folderName);
			SLog::Write(LOGLEVEL_DEBUG, sprintf("ASDevice->GetFolderIdForBackendId(): generated new folderid '%s' for backend-folderid '%s'", $newHash, $backendid));
			// temporarily save the new hash also in the cache (new folders will only be saved at the end of request and could be requested before that
			$this->backend2folderidCache[$backendid] = $newHash;

			return $newHash;
		}
		SLog::Write(LOGLEVEL_DEBUG, sprintf("ASDevice->GetFolderIdForBackendId(): no valid condition found for determining folderid for backendid '%s'. Returning as is!", Utils::PrintAsString($backendid)));

		return $backendid;
	}

	/**
	 * Indicates if the device has a folderid mapping (short ids).
	 *
	 * @return bool
	 */
	public function HasFolderIdMapping() {
		if (is_array($this->backend2folderidCache)) {
			return true;
		}
		if (!is_array($this->contentData)) {
			return false;
		}
		foreach ($this->contentData as $folderid => $data) {
			if (isset($data->{self::FOLDERBACKENDID})) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gets the supported fields transmitted previously by the device
	 * for a certain folder.
	 *
	 * @param string $folderid
	 *
	 * @return array|bool false means no supportedFields are available
	 */
	public function GetSupportedFields($folderid) {
		if (isset($this->contentData, $this->contentData[$folderid], $this->contentData[$folderid]->{self::FOLDERUUID}) &&
				$this->contentData[$folderid]->{self::FOLDERUUID} !== false &&
				isset($this->contentData[$folderid]->{self::FOLDERSUPPORTEDFIELDS})) {
			return $this->contentData[$folderid]->{self::FOLDERSUPPORTEDFIELDS};
		}

		return false;
	}

	/**
	 * Sets the set of supported fields transmitted by the device for a certain folder.
	 *
	 * @param string $folderid
	 * @param array  $fieldlist supported fields
	 *
	 * @return bool
	 */
	public function SetSupportedFields($folderid, $fieldlist) {
		$contentData = $this->contentData;
		if (!isset($contentData[$folderid])) {
			$contentData[$folderid] = new stdClass();
		}
		$contentData[$folderid]->{self::FOLDERSUPPORTEDFIELDS} = $fieldlist;
		$this->contentData = $contentData;
		$this->changed = true;

		return true;
	}

	/**
	 * Gets the current sync status of a certain folder.
	 *
	 * @param string $folderid
	 *
	 * @return bool|mixed false means the status is not available
	 */
	public function GetFolderSyncStatus($folderid) {
		if (isset($this->contentData[$folderid]->{self::FOLDERUUID}, $this->contentData[$folderid]->{self::FOLDERSYNCSTATUS}) &&
				$this->contentData[$folderid]->{self::FOLDERUUID} !== false) {
			return $this->contentData[$folderid]->{self::FOLDERSYNCSTATUS};
		}

		return false;
	}

	/**
	 * Sets the current sync status of a certain folder.
	 *
	 * @param string $folderid
	 * @param mixed  $status   if set to false the current status is deleted
	 *
	 * @return bool
	 */
	public function SetFolderSyncStatus($folderid, $status) {
		$contentData = $this->contentData;
		if (!isset($contentData[$folderid])) {
			$contentData[$folderid] = new stdClass();
		}
		if ($status !== false) {
			$contentData[$folderid]->{self::FOLDERSYNCSTATUS} = $status;
		}
		elseif (isset($contentData[$folderid]->{self::FOLDERSYNCSTATUS})) {
			unset($contentData[$folderid]->{self::FOLDERSYNCSTATUS});
		}

		$this->contentData = $contentData;
		$this->changed = true;

		return true;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * Additional Folders operations
	  TODO: All these methods are not being used in the code currently and could be removed at a later point if this
	  functionality is not being made available anymore.
	 */

	/**
	 * Returns a list of all additional folders of this device.
	 *
	 * @return array
	 */
	public function GetAdditionalFolders() {
		if (is_array($this->additionalfolders)) {
			return array_values($this->additionalfolders);
		}

		return [];
	}

	/**
	 * Returns an additional folder by folder ID.
	 *
	 * @param string $folderid
	 *
	 * @return array|false Returns a list of properties. Else false if folder id is unknown.
	 */
	// TODO: not used
	public function GetAdditionalFolder($folderid) {
		// check if the $folderid is one of our own - this will in mostly NOT be the case, so we do not log here
		if (!isset($this->additionalfolders[$folderid])) {
			return false;
		}

		return $this->additionalfolders[$folderid];
	}

	/**
	 * Adds an additional folder to this device & user.
	 *
	 * @param string $store     the store where this folder is located, e.g. "SYSTEM" (for public folder) or a username.
	 * @param string $folderid  the folder id of the additional folder
	 * @param string $name      the name of the additional folder (has to be unique for all folders on the device)
	 * @param string $type      AS foldertype of SYNC_FOLDER_TYPE_USER_*
	 * @param int    $flags     Additional flags, like DeviceManager::FLD_FLAGS_SENDASOWNER
	 * @param string $parentid  the parentid of this folder
	 * @param bool   $checkDups indicates if duplicate names and ids should be verified. Default: true
	 *
	 * @return bool
	 */
	// TODO: not used
	public function AddAdditionalFolder($store, $folderid, $name, $type, $flags, $parentid = 0, $checkDups = true) {
		// check if a folderid and name were sent
		if (!$folderid || !$name) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->AddAdditionalFolder(): No valid folderid ('%s') or name ('%s') sent. Aborting. ", $folderid, $name));

			return false;
		}

		// check if type is of a additional user type
		if (!in_array($type, [SYNC_FOLDER_TYPE_USER_CONTACT, SYNC_FOLDER_TYPE_USER_APPOINTMENT, SYNC_FOLDER_TYPE_USER_TASK, SYNC_FOLDER_TYPE_USER_MAIL, SYNC_FOLDER_TYPE_USER_NOTE, SYNC_FOLDER_TYPE_USER_JOURNAL, SYNC_FOLDER_TYPE_OTHER])) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->AddAdditionalFolder(): folder can not be added because the specified type '%s' is not a permitted user type.", $type));

			return false;
		}

		// check if a folder with this ID is already in the list
		if (isset($this->additionalfolders[$folderid])) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->AddAdditionalFolder(): folder can not be added because there is already an additional folder with the same folder id: '%s'", $folderid));

			return false;
		}

		// check if a folder with that Name is already in the list and that its parent exists
		$parentFound = false;
		foreach ($this->additionalfolders as $k => $folder) {
			// This is fixed in fixstates, but we could keep this here a while longer.
			// TODO: remove line at a later point.
			if (!isset($folder['parentid'])) {
				$folder['parentid'] = "0";
			}

			if ($folder['name'] == $name && $folder['parentid'] == $parentid) {
				SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->AddAdditionalFolder(): folder can not be added because there is already an additional folder with the same name in the same folder: '%s'", $name));

				return false;
			}
			if ($folder['folderid'] == $parentid) {
				$parentFound = true;
			}
		}
		if ($parentid != '0' && !$parentFound) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->AddAdditionalFolder(): folder '%s' ('%s') can not be added because the parent folder '%s' can not be found'", $name, $folderid, $parentid));

			return false;
		}

		// check if a folder with this ID or Name is already known on the device (regular folder)
		if ($checkDups) {
			// in order to check for the parent-ids we need a shortid
			$parentShortId = $this->GetFolderIdForBackendId($parentid, false, null, null);
			foreach ($this->GetHierarchyCache()->ExportFolders() as $syncedFolderid => $folder) {
				if ($syncedFolderid === $folderid || $folder->BackendId === $folderid) {
					SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->AddAdditionalFolder(): folder can not be added because there is already a folder with the same folder id synchronized: '%s'", $folderid));

					return false;
				}

				// $folder is a SyncFolder object here
				if ($folder->displayname == $name && ($folder->parentid == $parentid || $folder->parentid == $parentShortId)) {
					SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->AddAdditionalFolder(): folder can not be added because there is already a folder with the same name synchronized in the same parent: '%s'", $name));

					return false;
				}
			}
		}

		// add the folder
		$af = $this->additionalfolders;
		$af[$folderid] = [
			'store' => $store,
			'folderid' => $folderid,
			'parentid' => $parentid,
			'name' => $name,
			'type' => $type,
			'flags' => $flags,
		];
		$this->additionalfolders = $af;
		$this->changed = true;

		// generate an integer folderid for it
		$this->GetFolderIdForBackendId($folderid, true, DeviceManager::FLD_ORIGIN_SHARED, $name);

		return true;
	}

	/**
	 * Edits (sets a new name) for an additional folder. Store, folderid and type can not be edited. Remove and add instead.
	 *
	 * @param string $folderid  the folder id of the additional folder
	 * @param string $name      the name of the additional folder (has to be unique for all folders on the device)
	 * @param int    $flags     Additional flags, like DeviceManager::FLD_FLAGS_SENDASOWNER
	 * @param string $parentid  the parentid of this folder
	 * @param bool   $checkDups indicates if duplicate names and ids should be verified. Default: true
	 *
	 * @return bool
	 */
	// TODO: not used
	public function EditAdditionalFolder($folderid, $name, $flags, $parentid = 0, $checkDups = true) {
		// check if a folderid and name were sent
		if (!$folderid || !$name) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->EditAdditionalFolder(): No valid folderid ('%s') or name ('%s') sent. Aborting. ", $folderid, $name));

			return false;
		}

		// check if a folder with this ID is known
		if (!isset($this->additionalfolders[$folderid])) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->EditAdditionalFolder(): folder can not be edited because there is no folder known with this folder id: '%s'. Add the folder first.", $folderid));

			return false;
		}

		// check if a folder with the new name is already in the list
		foreach ($this->additionalfolders as $existingFolderid => $folder) {
			if ($folder['name'] == $name && $folderid !== $existingFolderid) {
				SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->EditAdditionalFolder(): folder can not be edited because there is already an additional folder with the same name: '%s'", $name));

				return false;
			}
		}

		// check if a folder with the new name is already known on the device (regular folder)
		if ($checkDups) {
			// in order to check for the parent-ids we need a shortid
			$parentShortId = $this->GetFolderIdForBackendId($parentid, false, null, null);
			foreach ($this->GetHierarchyCache()->ExportFolders() as $syncedFolderid => $folder) {
				// $folder is a SyncFolder object here
				if ($folder->displayname == $name && $folderid !== $folder->BackendId && $folderid !== $syncedFolderid && ($folder->parentid == $parentid || $folder->parentid == $parentShortId)) {
					SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->EditAdditionalFolder(): folder can not be edited because there is already a folder with the same name synchronized: '%s'", $folderid));

					return false;
				}
			}
		}

		// update the name
		$af = $this->additionalfolders;
		$af[$folderid]['name'] = $name;
		$af[$folderid]['flags'] = $flags;
		$af[$folderid]['parentid'] = $parentid;
		$this->additionalfolders = $af;
		$this->changed = true;

		return true;
	}

	/**
	 * Removes an additional folder from this device & user.
	 *
	 * @param mixed $folderid
	 *
	 * @return bool
	 */
	// TODO: not used
	public function RemoveAdditionalFolder($folderid) {
		// check if a folderid were sent
		if (!$folderid) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->RemoveAdditionalFolder(): No valid folderid ('%s') sent. Aborting. ", $folderid));

			return false;
		}
		// check if a folder with this ID is known
		if (!isset($this->additionalfolders[$folderid])) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->RemoveAdditionalFolder(): folder can not be removed because there is no folder known with this folder id: '%s'", $folderid));

			return false;
		}

		// remove the folder
		$af = $this->additionalfolders;
		unset($af[$folderid]);
		$this->additionalfolders = $af;
		$this->changed = true;

		return true;
	}

	/**
	 * Sets a list of additional folders of one store to the device.
	 * If there are additional folders for the set_store, that are not in the list they will be removed.
	 *
	 * @param string $store   the store where this folder is located, e.g. "SYSTEM" (for public folder) or an username/email address.
	 * @param array  $folders a list of folders to be set for this user. Other existing additional folders (that are not in this list)
	 *                        will be removed. The list is an array containing folders, where each folder is an array with the following keys:
	 *                        'folderid'  (string) the folder id of the additional folder.
	 *                        'parentid'  (string) the folderid of the parent folder. If no parent folder is set or the parent folder is not defined, '0' (main folder) is used.
	 *                        'name'      (string) the name of the additional folder (has to be unique for all folders on the device).
	 *                        'type'      (string) AS foldertype of SYNC_FOLDER_TYPE_USER_*
	 *                        'flags'     (int)    Additional flags, like DeviceManager::FLD_FLAGS_SENDASOWNER
	 *
	 * @return bool
	 */
	// TODO: not used
	public function SetAdditionalFolderList($store, $folders) {
		// remove all folders already shared for this store
		$newAF = [];
		$noDupsCheck = [];
		foreach ($this->additionalfolders as $keepFolder) {
			if ($keepFolder['store'] !== $store) {
				$newAF[$keepFolder['folderid']] = $keepFolder;
			}
			else {
				$noDupsCheck[$keepFolder['folderid']] = true;
			}
		}
		SLog::Write(LOGLEVEL_DEBUG, sprintf("ASDevice->SetAdditionalFolderList(): cleared additional folder lists of store '%s', total %d folders, kept %d and removed %d", $store, count($this->additionalfolders), count($newAF), count($noDupsCheck)));
		// set remaining additional folders
		$this->additionalfolders = $newAF;
		$this->changed = true;

		// transform our array in a key/value array where folderids are keys and do some basic checks
		$toOrder = [];
		$ordered = [];
		$validTypes = [SYNC_FOLDER_TYPE_USER_CONTACT, SYNC_FOLDER_TYPE_USER_APPOINTMENT, SYNC_FOLDER_TYPE_USER_TASK, SYNC_FOLDER_TYPE_USER_MAIL, SYNC_FOLDER_TYPE_USER_NOTE, SYNC_FOLDER_TYPE_USER_JOURNAL, SYNC_FOLDER_TYPE_OTHER];
		foreach ($folders as $f) {
			// fail early
			if (!$f['folderid'] || !$f['name']) {
				SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->SetAdditionalFolderList(): No valid folderid ('%s') or name ('%s') sent. Aborting. ", $f['folderid'], $f['name']));

				return false;
			}

			// check if type is of a additional user type
			if (!in_array($f['type'], $validTypes)) {
				SLog::Write(LOGLEVEL_ERROR, sprintf("ASDevice->SetAdditionalFolderList(): folder (id: '%s' - name: '%s') can not be added because the specified type '%s' is not a permitted user type.", $f['folderid'], $f['name'], $f['type']));

				return false;
			}
			$toOrder[$f['folderid']] = $f;
		}

		// order the array, so folders with leafs come first
		$this->orderAdditionalFoldersHierarchically($toOrder, $ordered);

		// if there are folders that are not be positioned in the tree, we can't add them!
		if (!empty($toOrder)) {
			$s = "";
			foreach ($toOrder as $f) {
				$s .= sprintf("'%s'('%s') ", $f['name'], $f['folderid']);
			}
			SLog::Write(LOGLEVEL_ERROR, "ASDevice->SetAdditionalFolderList(): cannot proceed as these folders have invalid parentids (not found): " . $s);

			return false;
		}

		foreach ($ordered as $f) {
			$status = $this->AddAdditionalFolder($store, $f['folderid'], $f['name'], $f['type'], $f['flags'], $f['parentid'], !isset($noDupsCheck[$f['folderid']]));
			SLog::Write(LOGLEVEL_DEBUG, sprintf("ASDevice->SetAdditionalFolderList(): set folder '%s' in additional folders list with status: %s", $f['name'], Utils::PrintAsString($status)));
			// break if a folder can not be added
			if (!$status) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Orders a list of folders so the parents are first in the array, all leaves come afterwards.
	 *
	 * @param array  $toOrderFolders an array of folders, where the folderids are keys. This array should be empty at the end.
	 * @param array  $orderedFolders the ordered array
	 * @param string $parentid       the parentid to start with, if not set '0' (main folders) is used
	 */
	// TODO: not used
	private function orderAdditionalFoldersHierarchically(&$toOrderFolders, &$orderedFolders, $parentid = '0') {
		$stepInto = [];
		// loop through the remaining folders that need to be ordered
		foreach ($toOrderFolders as $folder) {
			// move folders with the matching parentid to the ordered array
			if ($folder['parentid'] == $parentid) {
				$fid = $folder['folderid'];
				$orderedFolders[$fid] = $folder;
				unset($toOrderFolders[$fid]);
				$stepInto[] = $fid;
			}
		}
		// call recursively to move/order the leaves as well
		foreach ($stepInto as $fid) {
			$this->orderAdditionalFoldersHierarchically($toOrderFolders, $orderedFolders, $fid);
		}
	}

	/*----------------------------------------------------------------------------------------------------------
	 * Additional Folders operations - END
	 */

	/**
	 * Generates the AS folder hash from the backend folder id, type and name.
	 *
	 * @param string $backendid    Backend folder id
	 * @param string $folderOrigin Folder type is one of   'U' (user)
	 *                             'C' (configured)
	 *                             'S' (shared)
	 *                             'G' (global address book)
	 *                             'I' (impersonated)
	 * @param string $folderName   Folder name of the backend folder
	 *
	 * @return string
	 */
	private function generateFolderHash($backendid, $folderOrigin, $folderName) {
		// Hash backendid with crc32 and get the hex representation of it.
		// 5 chars of hash + $folderOrigin should still be enough to avoid collisions.
		$folderId = substr($folderOrigin . dechex(crc32($backendid)), 0, 6);
		$cnt = 0;
		// Collision avoiding. Append an increasing number to the string to hash
		// until there aren't any collisions. Probably a smaller number is also sufficient.
		while ((isset($this->contentData[$folderId]) || (is_array($this->backend2folderidCache) && in_array($folderId, $this->backend2folderidCache, true))) && $cnt < 10000) {
			$folderId = substr($folderOrigin . dechex(crc32($backendid . $folderName . $cnt++)), 0, 6);
			SLog::Write(LOGLEVEL_WARN, sprintf("ASDevice->generateFolderHash(): collision avoiding nr %05d. Generated hash: '%s'", $cnt, $folderId));
		}
		if ($cnt >= 10000) {
			throw new FatalException("ASDevice->generateFolderHash(): too many colissions while generating folder hash.");
		}

		return $folderId;
	}
}
