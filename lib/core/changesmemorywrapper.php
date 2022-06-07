<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Class that collect changes in memory
 */

class ChangesMemoryWrapper extends HierarchyCache implements IImportChanges, IExportChanges {
	public const CHANGE = 1;
	public const DELETION = 2;
	public const SOFTDELETION = 3;
	public const SYNCHRONIZING = 4;

	private $changes;
	private $step;
	private $destinationImporter;
	private $exportImporter;
	private $impersonating;
	private $foldersWithoutPermissions;

	/**
	 * Constructor.
	 *
	 * @return
	 */
	public function __construct() {
		$this->changes = [];
		$this->step = 0;
		$this->impersonating = null;
		$this->foldersWithoutPermissions = [];
		parent::__construct();
	}

	/**
	 * Only used to load additional folder sync information for hierarchy changes.
	 *
	 * @param array $state current state of additional hierarchy folders
	 * @param mixed $flags
	 *
	 * @return bool
	 */
	public function Config($state, $flags = 0) {
		if ($this->impersonating == null) {
			$this->impersonating = (Request::GetImpersonatedUser()) ? strtolower(Request::GetImpersonatedUser()) : false;
		}

		// we should never forward this changes to a backend
		if (!isset($this->destinationImporter)) {
			foreach ($state as $addKey => $addFolder) {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("ChangesMemoryWrapper->Config(AdditionalFolders) : process folder '%s'", $addFolder->displayname));
				if (isset($addFolder->NoBackendFolder) && $addFolder->NoBackendFolder == true) {
					// check rights for readonly access only
					$hasRights = GSync::GetBackend()->Setup($addFolder->Store, true, $addFolder->BackendId, true);
					// delete the folder on the device
					if (!$hasRights) {
						// delete the folder only if it was an additional folder before, else ignore it
						$synchedfolder = $this->GetFolder($addFolder->serverid);
						if (isset($synchedfolder->NoBackendFolder) && $synchedfolder->NoBackendFolder == true) {
							$this->ImportFolderDeletion($addFolder);
						}

						continue;
					}
				}
				// make sure, if the folder is already in cache, to set the TypeReal flag (if available)
				$cacheFolder = $this->GetFolder($addFolder->serverid);
				if (isset($cacheFolder->TypeReal)) {
					$addFolder->TypeReal = $cacheFolder->TypeReal;
					SLog::Write(LOGLEVEL_DEBUG, sprintf("ChangesMemoryWrapper->Config(): Set REAL foldertype for folder '%s' from cache: '%s'", $addFolder->displayname, $addFolder->TypeReal));
				}

				// add folder to the device - if folder is already on the device, nothing will happen
				$this->ImportFolderChange($addFolder);
			}

			// look for folders which are currently on the device if there are now not to be synched anymore
			$alreadyDeleted = $this->GetDeletedFolders();
			$folderIdsOnClient = [];
			foreach ($this->ExportFolders(true) as $sid => $folder) {
				// we are only looking at additional folders
				if (isset($folder->NoBackendFolder)) {
					// look if this folder is still in the list of additional folders and was not already deleted (e.g. missing permissions)
					if (!array_key_exists($sid, $state) && !array_key_exists($sid, $alreadyDeleted)) {
						SLog::Write(LOGLEVEL_INFO, sprintf("ChangesMemoryWrapper->Config(AdditionalFolders) : previously synchronized folder '%s' is not to be synched anymore. Sending delete to mobile.", $folder->displayname));
						$this->ImportFolderDeletion($folder);
					}
				}
				else {
					$folderIdsOnClient[] = $sid;
				}
			}

			// check permissions on impersonated folders
			if ($this->impersonating) {
				SLog::Write(LOGLEVEL_DEBUG, "ChangesMemoryWrapper->Config(): check permissions of folders of impersonated account");
				$hierarchy = GSync::GetBackend()->GetHierarchy();
				foreach ($hierarchy as $folder) {
					// Check for at least read permissions of the impersonater on folders
					$hasRights = GSync::GetBackend()->Setup($this->impersonating, true, $folder->BackendId, true);

					// the folder has no permissions
					if (!$hasRights) {
						$this->foldersWithoutPermissions[$folder->serverid] = $folder;
						// if it's on the device, remove it
						if (in_array($folder->serverid, $folderIdsOnClient)) {
							SLog::Write(LOGLEVEL_DEBUG, sprintf("ChangesMemoryWrapper->Config(AdditionalFolders) : previously synchronized folder '%s' has no permissions anymore. Sending delete to mobile.", $folder->displayname));
							// delete folder into memory so it's then sent to the client
							$this->ImportFolderDeletion($folder);
						}
					}
					// has permissions but is not on the device, add it
					elseif (!in_array($folder->serverid, $folderIdsOnClient)) {
						$folder->flags = SYNC_NEWMESSAGE;
						$this->ImportFolderChange($folder);
					}
				}
			}
		}

		return true;
	}

	/**
	 * Implement interfaces which are never used.
	 */
	public function GetState() {
		return false;
	}

	public function LoadConflicts($contentparameters, $state) {
		return true;
	}

	public function ConfigContentParameters($contentparameters) {
		return true;
	}

	public function ImportMessageReadFlag($id, $flags, $categories = []) {
		return true;
	}

	public function ImportMessageMove($id, $newfolder) {
		return true;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * IImportChanges & destination importer
	 */

	/**
	 * Sets an importer where incoming changes should be sent to.
	 *
	 * @param IImportChanges $importer message to be changed
	 *
	 * @return bool
	 */
	public function SetDestinationImporter(&$importer) {
		$this->destinationImporter = $importer;

		return true;
	}

	/**
	 * Imports a message change, which is imported into memory.
	 *
	 * @param string     $id      id of message which is changed
	 * @param SyncObject $message message to be changed
	 *
	 * @return bool
	 */
	public function ImportMessageChange($id, $message) {
		$this->changes[] = [self::CHANGE, $id];

		return true;
	}

	/**
	 * Imports a message deletion, which is imported into memory.
	 *
	 * @param string $id           id of message which is deleted
	 * @param bool   $asSoftDelete (opt) if true, the deletion is exported as "SoftDelete", else as "Remove" - default: false
	 *
	 * @return bool
	 */
	public function ImportMessageDeletion($id, $asSoftDelete = false) {
		if ($asSoftDelete === true) {
			$this->changes[] = [self::SOFTDELETION, $id];
		}
		else {
			$this->changes[] = [self::DELETION, $id];
		}

		return true;
	}

	/**
	 * Checks if a message id is flagged as changed.
	 *
	 * @param string $id message id
	 *
	 * @return bool
	 */
	public function IsChanged($id) {
		return (array_search([self::CHANGE, $id], $this->changes) === false) ? false : true;
	}

	/**
	 * Checks if a message id is flagged as deleted.
	 *
	 * @param string $id message id
	 *
	 * @return bool
	 */
	public function IsDeleted($id) {
		return !((array_search([self::DELETION, $id], $this->changes) === false) && (array_search([self::SOFTDELETION, $id], $this->changes) === false));
	}

	/**
	 * Imports a folder change.
	 *
	 * @param SyncFolder $folder folder to be changed
	 *
	 * @return bool|SyncObject status/object with the ath least the serverid of the folder set
	 */
	public function ImportFolderChange($folder) {
		// if the destinationImporter is set, then this folder should be processed by another importer
		// instead of being loaded in memory.
		if (isset($this->destinationImporter)) {
			// normally the $folder->type is not set, but we need this value to check if the change operation is permitted
			// e.g. system folders can normally not be changed - set the type from cache and let the destinationImporter decide
			if (!isset($folder->type) || !$folder->type) {
				$cacheFolder = $this->GetFolder($folder->serverid);
				$folder->type = $cacheFolder->type;
				SLog::Write(LOGLEVEL_DEBUG, sprintf("ChangesMemoryWrapper->ImportFolderChange(): Set foldertype for folder '%s' from cache as it was not sent: '%s'", $folder->displayname, $folder->type));
				if (isset($cacheFolder->TypeReal)) {
					$folder->TypeReal = $cacheFolder->TypeReal;
					SLog::Write(LOGLEVEL_DEBUG, sprintf("ChangesMemoryWrapper->ImportFolderChange(): Set REAL foldertype for folder '%s' from cache: '%s'", $folder->displayname, $folder->TypeReal));
				}
			}

			$retFolder = $this->destinationImporter->ImportFolderChange($folder);

			// if the operation was successful, update the HierarchyCache
			if ($retFolder) {
				// if we get a folder back, we need to update some data in the cache
				if (isset($retFolder->serverid) && $retFolder->serverid) {
					// for folder creation, the serverid & backendid are not set and have to be updated
					if (!isset($folder->serverid) || $folder->serverid == "") {
						$folder->serverid = $retFolder->serverid;
						if (isset($retFolder->BackendId) && $retFolder->BackendId) {
							$folder->BackendId = $retFolder->BackendId;
						}
					}

					// if the parentid changed (folder was moved) this needs to be updated as well
					if ($retFolder->parentid != $folder->parentid) {
						$folder->parentid = $retFolder->parentid;
					}
				}

				$this->AddFolder($folder);
			}

			return $retFolder;
		}
		// load into memory

		if (isset($folder->serverid)) {
			// The grommunio HierarchyExporter exports all kinds of changes for folders (e.g. update no. of unread messages in a folder).
			// These changes are not relevant for the mobiles, as something changes but the relevant displayname and parentid
			// stay the same. These changes will be dropped and are not sent!
			if ($folder->equals($this->GetFolder($folder->serverid), false, true)) {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("ChangesMemoryWrapper->ImportFolderChange(): Change for folder '%s' will not be sent as modification is not relevant.", $folder->displayname));

				return false;
			}

			// check if the parent ID is known on the device
			if (!isset($folder->parentid) || ($folder->parentid != "0" && !$this->GetFolder($folder->parentid))) {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("ChangesMemoryWrapper->ImportFolderChange(): Change for folder '%s' will not be sent as parent folder is not set or not known on mobile.", $folder->displayname));

				return false;
			}

			// folder changes are only sent if the user has permissions on that folder, if not, change is ignored
			if ($this->impersonating && array_key_exists($folder->serverid, $this->foldersWithoutPermissions)) {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("ChangesMemoryWrapper->ImportFolderChange(): Change for folder '%s' will not be sent as impersonating user has no permissions on folder.", $folder->displayname));

				return false;
			}

			// load this change into memory
			$this->changes[] = [self::CHANGE, $folder];

			// HierarchyCache: already add/update the folder so changes are not sent twice (if exported twice)
			$this->AddFolder($folder);

			return true;
		}

		return false;
	}

	/**
	 * Imports a folder deletion.
	 *
	 * @param SyncFolder $folder at least "serverid" needs to be set
	 *
	 * @return bool
	 */
	public function ImportFolderDeletion($folder) {
		$id = $folder->serverid;

		// if the forwarder is set, then this folder should be processed by another importer
		// instead of being loaded in mem.
		if (isset($this->destinationImporter)) {
			$ret = $this->destinationImporter->ImportFolderDeletion($folder);

			// if the operation was successful, update the HierarchyCache
			if ($ret) {
				$this->DelFolder($id);
			}

			return $ret;
		}

		// if this folder is not in the cache, the change does not need to be streamed to the mobile
		if ($this->GetFolder($id)) {
			// load this change into memory
			$this->changes[] = [self::DELETION, $folder];

			// HierarchyCache: delete the folder so changes are not sent twice (if exported twice)
			$this->DelFolder($id);

			return true;
		}
	}

	/*----------------------------------------------------------------------------------------------------------
	 * IExportChanges & destination importer
	 */

	/**
	 * Initializes the Exporter where changes are synchronized to.
	 *
	 * @param IImportChanges $importer
	 *
	 * @return bool
	 */
	public function InitializeExporter(&$importer) {
		$this->exportImporter = $importer;
		$this->step = 0;

		return true;
	}

	/**
	 * Returns the amount of changes to be exported.
	 *
	 * @return int
	 */
	public function GetChangeCount() {
		return count($this->changes);
	}

	/**
	 * Synchronizes a change. Only HierarchyChanges will be Synchronized().
	 *
	 * @return array
	 */
	public function Synchronize() {
		if ($this->step < count($this->changes) && isset($this->exportImporter)) {
			$change = $this->changes[$this->step];

			if ($change[0] == self::CHANGE) {
				if (!$this->GetFolder($change[1]->serverid, true)) {
					$change[1]->flags = SYNC_NEWMESSAGE;
				}

				$this->exportImporter->ImportFolderChange($change[1]);
			}
			// deletion
			else {
				$this->exportImporter->ImportFolderDeletion($change[1]);
			}
			++$this->step;

			// return progress array
			return ["steps" => count($this->changes), "progress" => $this->step];
		}

		return false;
	}

	/**
	 * Initializes a few instance variables
	 * called after unserialization.
	 *
	 * @return array
	 */
	public function __wakeup() {
		$this->changes = [];
		$this->step = 0;
		$this->foldersWithoutPermissions = [];
	}

	/**
	 * Removes internal data from the object, so this data can not be exposed.
	 *
	 * @return bool
	 */
	public function StripData() {
		unset($this->changes, $this->step, $this->destinationImporter, $this->exportImporter);

		return parent::StripData();
	}
}
