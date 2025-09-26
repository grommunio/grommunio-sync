<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * This is a generic class that is used by both the proxy importer (for
 * outgoing messages) and our local importer (for incoming messages). Basically
 * all shared conversion data for converting to and from MAPI objects is in
 * here.
 */

/**
 * This is our local importer. Tt receives data from the PDA, for contents and hierarchy changes.
 * It must therefore receive the incoming data and convert it into MAPI objects, and then send
 * them to the ICS importer to do the actual writing of the object.
 * The creation of folders is fairly trivial, because folders that are created on
 * the PDA are always e-mail folders.
 */
class ImportChangesICS implements IImportChanges {
	private $folderidHex;
	private $store;
	private $session;
	private $flags;
	private $statestream;
	private $importer;
	private $memChanges;
	private $mapiprovider;
	private $conflictsLoaded;
	private $conflictsContentParameters;
	private $conflictsState;
	private $cutoffdate;
	private $contentClass;
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param mapisession $session
	 * @param mapistore   $store
	 * @param string      $folderid (opt)
	 *
	 * @throws StatusException
	 */
	public function __construct($session, $store, private $folderid = false) {
		$this->session = $session;
		$this->store = $store;
		$this->folderidHex = bin2hex($this->folderid);
		$this->conflictsLoaded = false;
		$this->cutoffdate = false;
		$this->contentClass = false;
		$this->prefix = '';

		if ($this->folderid) {
			$entryid = mapi_msgstore_entryidfromsourcekey($store, $this->folderid);
			$folderidForBackendId = GSync::GetDeviceManager()->GetFolderIdForBackendId($this->folderidHex);
			// Only append backend id if the mapping backendid<->folderid is available.
			if ($folderidForBackendId != $this->folderidHex) {
				$this->prefix = $folderidForBackendId . ':';
			}
		}
		else {
			$storeprops = mapi_getprops($store, [PR_IPM_SUBTREE_ENTRYID, PR_IPM_PUBLIC_FOLDERS_ENTRYID]);
			if (GSync::GetBackend()->GetImpersonatedUser() == 'system') {
				$entryid = $storeprops[PR_IPM_PUBLIC_FOLDERS_ENTRYID];
			}
			else {
				$entryid = $storeprops[PR_IPM_SUBTREE_ENTRYID];
			}
		}

		$folder = false;
		if ($entryid) {
			$folder = mapi_msgstore_openentry($store, $entryid);
		}

		if (!$folder) {
			$this->importer = false;

			// We throw an general error SYNC_FSSTATUS_CODEUNKNOWN (12) which is also SYNC_STATUS_FOLDERHIERARCHYCHANGED (12)
			// if this happened while doing content sync, the mobile will try to resync the folderhierarchy
			throw new StatusException(sprintf("ImportChangesICS('%s','%s'): Error, unable to open folder: 0x%X", $session, bin2hex($this->folderid), mapi_last_hresult()), SYNC_FSSTATUS_CODEUNKNOWN);
		}

		$this->mapiprovider = new MAPIProvider($this->session, $this->store);

		if ($this->folderid) {
			$this->importer = mapi_openproperty($folder, PR_COLLECTOR, IID_IExchangeImportContentsChanges, 0, 0);
		}
		else {
			$this->importer = mapi_openproperty($folder, PR_COLLECTOR, IID_IExchangeImportHierarchyChanges, 0, 0);
		}
	}

	/**
	 * Initializes the importer.
	 *
	 * @param string $state
	 * @param int    $flags
	 *
	 * @return bool
	 *
	 * @throws StatusException
	 */
	public function Config($state, $flags = 0) {
		$this->flags = $flags;

		// this should never happen
		if ($this->importer === false) {
			throw new StatusException("ImportChangesICS->Config(): Error, importer not available", SYNC_FSSTATUS_CODEUNKNOWN, null, LOGLEVEL_ERROR);
		}

		// Put the state information in a stream that can be used by ICS
		$stream = mapi_stream_create();
		if (strlen($state) == 0) {
			$state = hex2bin("0000000000000000");
		}

		SLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesICS->Config(): initializing importer with state: 0x%s", bin2hex($state)));

		mapi_stream_write($stream, $state);
		$this->statestream = $stream;

		if ($this->folderid !== false) {
			// possible conflicting messages will be cached here
			$this->memChanges = new ChangesMemoryWrapper();
			$stat = mapi_importcontentschanges_config($this->importer, $stream, $flags);
		}
		else {
			$stat = mapi_importhierarchychanges_config($this->importer, $stream, $flags);
		}

		if (!$stat) {
			throw new StatusException(sprintf("ImportChangesICS->Config(): Error, mapi_import_*_changes_config() failed: 0x%X", mapi_last_hresult()), SYNC_FSSTATUS_CODEUNKNOWN, null, LOGLEVEL_WARN);
		}

		return $stat;
	}

	/**
	 * Configures additional parameters for content selection.
	 *
	 * @param ContentParameters $contentparameters
	 *
	 * @return bool
	 *
	 * @throws StatusException
	 */
	public function ConfigContentParameters($contentparameters) {
		$filtertype = $contentparameters->GetFilterType();

		if ($filtertype == SYNC_FILTERTYPE_DISABLE) {
			$filtertype = false;
		}

		switch ($contentparameters->GetContentClass()) {
			case "Email":
				$this->cutoffdate = ($filtertype) ? Utils::GetCutOffDate($filtertype) : false;
				break;

			case "Calendar":
				$this->cutoffdate = ($filtertype) ? Utils::GetCutOffDate($filtertype) : false;
				break;

			default:
			case "Contacts":
			case "Tasks":
				$this->cutoffdate = false;
				break;
		}
		$this->contentClass = $contentparameters->GetContentClass();

		return true;
	}

	/**
	 * Reads state from the Importer.
	 *
	 * @return string
	 *
	 * @throws StatusException
	 */
	public function GetState() {
		$error = false;
		if (!isset($this->statestream) || $this->importer === false) {
			$error = true;
		}

		if ($error === false && $this->folderid !== false && function_exists("mapi_importcontentschanges_updatestate")) {
			if (mapi_importcontentschanges_updatestate($this->importer, $this->statestream) != true) {
				$error = true;
			}
		}

		if ($error === true) {
			throw new StatusException(sprintf("ImportChangesICS->GetState(): Error, state not available or unable to update: 0x%X", mapi_last_hresult()), ($this->folderid) ? SYNC_STATUS_FOLDERHIERARCHYCHANGED : SYNC_FSSTATUS_CODEUNKNOWN, null, LOGLEVEL_WARN);
		}

		mapi_stream_seek($this->statestream, 0, STREAM_SEEK_SET);

		$state = "";
		while (true) {
			$data = mapi_stream_read($this->statestream, 4096);
			if (strlen($data)) {
				$state .= $data;
			}
			else {
				break;
			}
		}

		return $state;
	}

	/**
	 * Checks if a message may be modified. This involves checking:
	 * - if there is a synchronization interval and if so, if the message is in it (sync window).
	 *   These checks only apply to Emails and Appointments only, Contacts, Tasks and Notes do not have time restrictions.
	 * - if the message is not marked as private in a shared folder.
	 *
	 * @param string $messageid the message id to be checked
	 *
	 * @return bool
	 */
	private function isModificationAllowed($messageid) {
		$sharedUser = GSync::GetAdditionalSyncFolderStore(bin2hex($this->folderid));
		// if this is either a user folder or SYSTEM and no restriction is set, we don't need to check
		if (($sharedUser == false || $sharedUser == 'SYSTEM') && $this->cutoffdate === false && !GSync::GetBackend()->GetImpersonatedUser()) {
			return true;
		}

		// open the existing object
		$entryid = mapi_msgstore_entryidfromsourcekey($this->store, $this->folderid, hex2bin($messageid));
		if (!$entryid) {
			SLog::Write(LOGLEVEL_WARN, sprintf("ImportChangesICS->isModificationAllowed('%s'): Error, unable to resolve message id: 0x%X", $messageid, mapi_last_hresult()));

			return false;
		}

		$mapimessage = mapi_msgstore_openentry($this->store, $entryid);
		if (!$mapimessage) {
			SLog::Write(LOGLEVEL_WARN, sprintf("ImportChangesICS->isModificationAllowed('%s'): Error, unable to open entry id: 0x%X", $messageid, mapi_last_hresult()));

			return false;
		}

		// check the sync interval
		if ($this->cutoffdate !== false) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesICS->isModificationAllowed('%s'): cut off date is: %s (%s)", $messageid, Utils::FormatDate($this->cutoffdate), $this->cutoffdate));
			if (($this->contentClass == "Email" && !MAPIUtils::IsInEmailSyncInterval($this->store, $mapimessage, $this->cutoffdate)) ||
				  ($this->contentClass == "Calendar" && !MAPIUtils::IsInCalendarSyncInterval($this->store, $mapimessage, $this->cutoffdate))) {
				SLog::Write(LOGLEVEL_WARN, sprintf("ImportChangesICS->isModificationAllowed('%s'): Message in %s is outside the sync interval. Data not saved.", $messageid, $this->contentClass));

				return false;
			}
		}

		// check if not private
		if (MAPIUtils::IsMessageSharedAndPrivate($this->folderid, $mapimessage)) {
			SLog::Write(LOGLEVEL_WARN, sprintf("ImportChangesICS->isModificationAllowed('%s'): Message is shared and marked as private. Data not saved.", $messageid));

			return false;
		}

		// yes, modification allowed
		return true;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * Methods for ContentsExporter
	 */

	/**
	 * Loads objects which are expected to be exported with the state
	 * Before importing/saving the actual message from the mobile, a conflict detection should be done.
	 *
	 * @param ContentParameters $contentparameters class of objects
	 * @param string            $state
	 *
	 * @return bool
	 *
	 * @throws StatusException
	 */
	public function LoadConflicts($contentparameters, $state) {
		if (!isset($this->session) || !isset($this->store) || !isset($this->folderid)) {
			throw new StatusException("ImportChangesICS->LoadConflicts(): Error, can not load changes for conflict detection. Session, store or folder information not available", SYNC_STATUS_SERVERERROR);
		}

		// save data to load changes later if necessary
		$this->conflictsLoaded = false;
		$this->conflictsContentParameters = $contentparameters;
		$this->conflictsState = $state;

		SLog::Write(LOGLEVEL_DEBUG, "ImportChangesICS->LoadConflicts(): will be loaded later if necessary");

		return true;
	}

	/**
	 * Potential conflicts are only loaded when really necessary,
	 * e.g. on ADD or MODIFY.
	 *
	 * @return bool
	 */
	private function lazyLoadConflicts() {
		if (!isset($this->session) || !isset($this->store) || !isset($this->folderid) ||
			!isset($this->conflictsContentParameters) || $this->conflictsState === false) {
			SLog::Write(LOGLEVEL_WARN, "ImportChangesICS->lazyLoadConflicts(): can not load potential conflicting changes in lazymode for conflict detection. Missing information");

			return false;
		}

		if (!$this->conflictsLoaded) {
			SLog::Write(LOGLEVEL_DEBUG, "ImportChangesICS->lazyLoadConflicts(): loading..");

			// configure an exporter so we can detect conflicts
			$exporter = new ExportChangesICS($this->session, $this->store, $this->folderid);
			$exporter->Config($this->conflictsState);
			$exporter->ConfigContentParameters($this->conflictsContentParameters);
			$exporter->InitializeExporter($this->memChanges);

			// monitor how long it takes to export potential conflicts
			// if this takes "too long" we cancel this operation!
			$potConflicts = $exporter->GetChangeCount();
			if ($potConflicts > 100) {
				SLog::Write(LOGLEVEL_INFO, sprintf("ImportChangesICS->lazyLoadConflicts(): conflict detection abandoned as there are too many (%d) changes to be exported.", $potConflicts));
				$this->conflictsLoaded = true;

				return false;
			}
			$started = time();
			$exported = 0;

			try {
				while (is_array($exporter->Synchronize())) {
					++$exported;

					// stop if this takes more than 15 seconds and there are more than 5 changes still to be exported
					// within 20 seconds this should be finished or it will not be performed
					if ((time() - $started) > 15 && ($potConflicts - $exported) > 5) {
						SLog::Write(LOGLEVEL_WARN, sprintf("ImportChangesICS->lazyLoadConflicts(): conflict detection cancelled as operation is too slow. In %d seconds only %d from %d changes were processed.", time() - $started, $exported, $potConflicts));
						$this->conflictsLoaded = true;

						return false;
					}
				}
			}
			// something really bad happened while exporting changes
			catch (StatusException $stex) {
				SLog::Write(LOGLEVEL_WARN, sprintf("ImportChangesICS->lazyLoadConflicts(): got StatusException code %d while exporting changes. Ignore and mark conflicts as loaded.", $stex->getCode()));
			}
			$this->conflictsLoaded = true;
		}

		return true;
	}

	/**
	 * Imports a single message.
	 *
	 * @param string     $id
	 * @param SyncObject $message
	 *
	 * @return bool|SyncObject - failure / response
	 *
	 * @throws StatusException
	 */
	public function ImportMessageChange($id, $message) {
		$flags = 0;
		$props = [];
		$props[PR_PARENT_SOURCE_KEY] = $this->folderid;
		$messageClass = $message::class;

		// set the PR_SOURCE_KEY if available or mark it as new message
		if ($id) {
			[, $sk] = Utils::SplitMessageId($id);
			$props[PR_SOURCE_KEY] = hex2bin((string) $sk);

			// check if message is in the synchronization interval and/or shared+private
			if (!$this->isModificationAllowed($sk)) {
				throw new StatusException(sprintf("ImportChangesICS->ImportMessageChange('%s','%s'): Message modification is not allowed. Data not saved.", $id, $messageClass), SYNC_STATUS_SYNCCANNOTBECOMPLETED);
			}

			// check for conflicts
			$this->lazyLoadConflicts();
			if ($this->memChanges->IsChanged($id)) {
				if ($this->flags & SYNC_CONFLICT_OVERWRITE_PIM) {
					// in these cases the status SYNC_STATUS_CONFLICTCLIENTSERVEROBJECT should be returned, so the mobile client can inform the end user
					throw new StatusException(sprintf("ImportChangesICS->ImportMessageChange('%s','%s'): Conflict detected. Data from PIM will be dropped! Server overwrites PIM. User is informed.", $id, $messageClass), SYNC_STATUS_CONFLICTCLIENTSERVEROBJECT, null, LOGLEVEL_INFO);
				}

				SLog::Write(LOGLEVEL_INFO, sprintf("ImportChangesICS->ImportMessageChange('%s','%s'): Conflict detected. Data from Server will be dropped! PIM overwrites server.", $id, $messageClass));
			}
			if ($this->memChanges->IsDeleted($id)) {
				SLog::Write(LOGLEVEL_INFO, sprintf("ImportChangesICS->ImportMessageChange('%s','%s'): Conflict detected. Data from PIM will be dropped! Object was deleted on server.", $id, $messageClass));
				$response = Utils::GetResponseFromMessageClass($messageClass);
				$response->hasResponse = false;

				return $response;
			}
		}
		else {
			$flags = SYNC_NEW_MESSAGE;
		}

		if (mapi_importcontentschanges_importmessagechange($this->importer, $props, $flags, $mapimessage)) {
			// grommunio-sync #113: workaround blocking notifications on this item
			$sourcekeyprops = mapi_getprops($mapimessage, [PR_ENTRYID]);
			if (isset($sourcekeyprops[PR_ENTRYID])) {
				GSync::ReplyCatchMark(bin2hex((string) $sourcekeyprops[PR_ENTRYID]));
			}

			$response = $this->mapiprovider->SetMessage($mapimessage, $message);
			mapi_savechanges($mapimessage);

			if (mapi_last_hresult()) {
				throw new StatusException(sprintf("ImportChangesICS->ImportMessageChange('%s','%s'): Error, mapi_savechanges() failed: 0x%X", $id, $messageClass, mapi_last_hresult()), SYNC_STATUS_SYNCCANNOTBECOMPLETED);
			}

			$sourcekeyprops = mapi_getprops($mapimessage, [PR_SOURCE_KEY]);

			$response->serverid = $this->prefix . bin2hex((string) $sourcekeyprops[PR_SOURCE_KEY]);

			return $response;
		}

		throw new StatusException(sprintf("ImportChangesICS->ImportMessageChange('%s','%s'): Error updating object: 0x%X", $id, $messageClass, mapi_last_hresult()), SYNC_STATUS_OBJECTNOTFOUND);
	}

	/**
	 * Imports a deletion. This may conflict if the local object has been modified.
	 *
	 * @param string $id
	 * @param bool   $asSoftDelete (opt) if true, the deletion is exported as "SoftDelete", else as "Remove" - default: false
	 *
	 * @return bool
	 */
	public function ImportMessageDeletion($id, $asSoftDelete = false) {
		[, $sk] = Utils::SplitMessageId($id);

		// check if message is in the synchronization interval and/or shared+private
		if (!$this->isModificationAllowed($sk)) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageDeletion('%s'): Message deletion is not allowed. Deletion not executed.", $id), SYNC_STATUS_OBJECTNOTFOUND);
		}

		// check for conflicts
		$this->lazyLoadConflicts();
		if ($this->memChanges->IsChanged($id)) {
			SLog::Write(LOGLEVEL_INFO, sprintf("ImportChangesICS->ImportMessageDeletion('%s'): Conflict detected. Data from Server will be dropped! PIM deleted object.", $id));
		}
		elseif ($this->memChanges->IsDeleted($id)) {
			SLog::Write(LOGLEVEL_INFO, sprintf("ImportChangesICS->ImportMessageDeletion('%s'): Conflict detected. Data is already deleted. Request will be ignored.", $id));

			return true;
		}

		// check if we need to do actions before deleting this message (e.g. send meeting cancellations to attendees)
		$entryid = mapi_msgstore_entryidfromsourcekey($this->store, $this->folderid, hex2bin((string) $sk));
		if ($entryid) {
			// open the source message
			$mapimessage = mapi_msgstore_openentry($this->store, $entryid);
			$this->mapiprovider->PreDeleteMessage($mapimessage);
			// grommunio-sync #113: workaround blocking notifications on this item
			GSync::ReplyCatchMark(bin2hex($entryid));
		}

		// do a 'soft' delete so people can un-delete if necessary
		mapi_importcontentschanges_importmessagedeletion($this->importer, 1, [hex2bin((string) $sk)]);
		if (mapi_last_hresult()) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageDeletion('%s'): Error updating object: 0x%X", $sk, mapi_last_hresult()), SYNC_STATUS_OBJECTNOTFOUND);
		}

		return true;
	}

	/**
	 * Imports a change in 'read' flag
	 * This can never conflict.
	 *
	 * @param string $id
	 * @param int    $flags      - read/unread
	 * @param array  $categories
	 *
	 * @return bool
	 *
	 * @throws StatusException
	 */
	public function ImportMessageReadFlag($id, $flags, $categories = []) {
		$response = new SyncMailResponse();
		[$fsk, $sk] = Utils::SplitMessageId($id);

		// if $fsk is set, we convert it into a backend id.
		if ($fsk) {
			$fsk = GSync::GetDeviceManager()->GetBackendIdForFolderId($fsk);
		}

		// read flag change for our current folder
		if ($this->folderidHex == $fsk || empty($fsk)) {
			// check if it is in the synchronization interval and/or shared+private
			if (!$this->isModificationAllowed($sk)) {
				throw new StatusException(sprintf("ImportChangesICS->ImportMessageReadFlag('%s','%d'): Flag update is not allowed. Flags not updated.", $id, $flags), SYNC_STATUS_OBJECTNOTFOUND);
			}

			// check for conflicts
			/*
			 * Checking for conflicts is correct at this point, but is a very expensive operation.
			 * If the message was deleted, only an error will be shown.
			 *
			$this->lazyLoadConflicts();
			if($this->memChanges->IsDeleted($id)) {
				SLog::Write(LOGLEVEL_INFO, sprintf("ImportChangesICS->ImportMessageReadFlag('%s'): Conflict detected. Data is already deleted. Request will be ignored.", $id));
				return true;
			}
			 */

			// grommunio-sync #113: workaround blocking notifications on this item
			$entryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($fsk), hex2bin((string) $sk));
			if ($entryid !== false) {
				GSync::ReplyCatchMark(bin2hex($entryid));
			}

			$readstate = ["sourcekey" => hex2bin((string) $sk), "flags" => $flags];

			if (!mapi_importcontentschanges_importperuserreadstatechange($this->importer, [$readstate])) {
				throw new StatusException(sprintf("ImportChangesICS->ImportMessageReadFlag('%s','%d'): Error setting read state: 0x%X", $id, $flags, mapi_last_hresult()), SYNC_STATUS_OBJECTNOTFOUND);
			}
		}
		// yeah OL sucks
		else {
			if (!$fsk) {
				throw new StatusException(sprintf("ImportChangesICS->ImportMessageReadFlag('%s','%d'): Error setting read state. The message is in another folder but id is unknown as no short folder id is available. Please remove your device states to fully resync your device. Operation ignored.", $id, $flags), SYNC_STATUS_OBJECTNOTFOUND);
			}
			$store = GSync::GetBackend()->GetMAPIStoreForFolderId(GSync::GetAdditionalSyncFolderStore($fsk), $fsk);
			$entryid = mapi_msgstore_entryidfromsourcekey($store, hex2bin((string) $fsk), hex2bin((string) $sk));
			$realMessage = mapi_msgstore_openentry($store, $entryid);
			$flag = 0;
			if ($flags == 0) {
				$flag |= CLEAR_READ_FLAG;
			}
			$p = mapi_message_setreadflag($realMessage, $flag);
			SLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesICS->ImportMessageReadFlag('%s','%d'): setting readflag on message: 0x%X", $id, $flags, mapi_last_hresult()));
		}

		return $response;
	}

	/**
	 * Imports a move of a message. This occurs when a user moves an item to another folder.
	 *
	 * Normally, we would implement this via the 'offical' importmessagemove() function on the ICS importer,
	 * but the grommunio importer does not support this. Therefore we currently implement it via a standard mapi
	 * call. This causes a mirror 'add/delete' to be sent to the PDA at the next sync.
	 * Manfred, 2010-10-21. For some mobiles import was causing duplicate messages in the destination folder
	 * (Mantis #202). Therefore we will create a new message in the destination folder, copy properties
	 * of the source message to the new one and then delete the source message.
	 *
	 * @param string $id
	 * @param string $newfolder destination folder
	 *
	 * @return bool|string
	 *
	 * @throws StatusException
	 */
	public function ImportMessageMove($id, $newfolder) {
		[, $sk] = Utils::SplitMessageId($id);
		if (strtolower($newfolder) == strtolower(bin2hex($this->folderid))) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, source and destination are equal", $id, $newfolder), SYNC_MOVEITEMSSTATUS_SAMESOURCEANDDEST);
		}

		// Get the entryid of the message we're moving
		$entryid = mapi_msgstore_entryidfromsourcekey($this->store, $this->folderid, hex2bin((string) $sk));
		$srcmessage = false;

		if ($entryid) {
			// open the source message
			$srcmessage = mapi_msgstore_openentry($this->store, $entryid);
		}

		if (!$entryid || !$srcmessage) {
			$code = SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID;
			$mapiLastHresult = mapi_last_hresult();
			// if we move to the trash and the source message is not found, we can also just tell the mobile that we successfully moved to avoid errors
			if ($newfolder == GSync::GetBackend()->GetWasteBasket()) {
				$code = SYNC_MOVEITEMSSTATUS_SUCCESS;
			}
			$errorCase = !$entryid ? "resolve source message id" : "open source message";

			throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to %s: 0x%X", $sk, $newfolder, $errorCase, $mapiLastHresult), $code);
		}

		// check if it is in the synchronization interval and/or shared+private
		if (!$this->isModificationAllowed($sk)) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Source message move is not allowed. Move not performed.", $id, $newfolder), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);
		}

		// get correct mapi store for the destination folder
		$dststore = GSync::GetBackend()->GetMAPIStoreForFolderId(GSync::GetAdditionalSyncFolderStore($newfolder), $newfolder);
		if ($dststore === false) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to open store of destination folder", $sk, $newfolder), SYNC_MOVEITEMSSTATUS_INVALIDDESTID);
		}

		$dstentryid = mapi_msgstore_entryidfromsourcekey($dststore, hex2bin($newfolder));
		if (!$dstentryid) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to resolve destination folder", $sk, $newfolder), SYNC_MOVEITEMSSTATUS_INVALIDDESTID);
		}

		$dstfolder = mapi_msgstore_openentry($dststore, $dstentryid);
		if (!$dstfolder) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to open destination folder", $sk, $newfolder), SYNC_MOVEITEMSSTATUS_INVALIDDESTID);
		}

		$newmessage = mapi_folder_createmessage($dstfolder);
		if (!$newmessage) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to create message in destination folder: 0x%X", $sk, $newfolder, mapi_last_hresult()), SYNC_MOVEITEMSSTATUS_INVALIDDESTID);
		}

		// Copy message
		mapi_copyto($srcmessage, [], [], $newmessage);
		if (mapi_last_hresult()) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, copy to destination message failed: 0x%X", $sk, $newfolder, mapi_last_hresult()), SYNC_MOVEITEMSSTATUS_CANNOTMOVE);
		}

		$srcfolderentryid = mapi_msgstore_entryidfromsourcekey($this->store, $this->folderid);
		if (!$srcfolderentryid) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to resolve source folder", $sk, $newfolder), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);
		}

		$srcfolder = mapi_msgstore_openentry($this->store, $srcfolderentryid);
		if (!$srcfolder) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, unable to open source folder: 0x%X", $sk, $newfolder, mapi_last_hresult()), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);
		}

		// Save changes
		mapi_savechanges($newmessage);
		if (mapi_last_hresult()) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, mapi_savechanges() failed: 0x%X", $sk, $newfolder, mapi_last_hresult()), SYNC_MOVEITEMSSTATUS_CANNOTMOVE);
		}

		// Delete the old message
		if (!mapi_folder_deletemessages($srcfolder, [$entryid])) {
			throw new StatusException(sprintf("ImportChangesICS->ImportMessageMove('%s','%s'): Error, delete of source message failed: 0x%X. Possible duplicates.", $sk, $newfolder, mapi_last_hresult()), SYNC_MOVEITEMSSTATUS_SOURCEORDESTLOCKED);
		}

		$sourcekeyprops = mapi_getprops($newmessage, [PR_SOURCE_KEY]);
		if (isset($sourcekeyprops[PR_SOURCE_KEY]) && $sourcekeyprops[PR_SOURCE_KEY]) {
			$prefix = "";
			// prepend the destination short folderid, if it exists
			$destShortId = GSync::GetDeviceManager()->GetFolderIdForBackendId($newfolder);
			if ($destShortId !== $newfolder) {
				$prefix = $destShortId . ":";
			}

			return $prefix . bin2hex((string) $sourcekeyprops[PR_SOURCE_KEY]);
		}

		return false;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * Methods for HierarchyExporter
	 */

	/**
	 * Imports a change on a folder.
	 *
	 * @param object $folder SyncFolder
	 *
	 * @return bool|SyncFolder false on error or a SyncFolder object with serverid and BackendId set (if available)
	 *
	 * @throws StatusException
	 */
	public function ImportFolderChange($folder) {
		$id = $folder->BackendId ?? false;
		$parent = $folder->parentid;
		$parent_org = $folder->parentid;
		$displayname = $folder->displayname;
		$type = $folder->type;

		if (Utils::IsSystemFolder($type)) {
			throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, system folder can not be created/modified", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname), SYNC_FSSTATUS_SYSTEMFOLDER);
		}

		// create a new folder if $id is not set
		if (!$id) {
			// the root folder is "0" - get IPM_SUBTREE
			if ($parent == "0") {
				$parentprops = mapi_getprops($this->store, [PR_IPM_SUBTREE_ENTRYID, PR_IPM_PUBLIC_FOLDERS_ENTRYID]);
				if (GSync::GetBackend()->GetImpersonatedUser() == 'system' && isset($parentprops[PR_IPM_PUBLIC_FOLDERS_ENTRYID])) {
					$parentfentryid = $parentprops[PR_IPM_PUBLIC_FOLDERS_ENTRYID];
				}
				elseif (isset($parentprops[PR_IPM_SUBTREE_ENTRYID])) {
					$parentfentryid = $parentprops[PR_IPM_SUBTREE_ENTRYID];
				}
			}
			else {
				$parentfentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin((string) $parent));
			}

			if (!$parentfentryid) {
				throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, unable to open parent folder (no entry id)", Utils::PrintAsString(false), $folder->parentid, $displayname), SYNC_FSSTATUS_PARENTNOTFOUND);
			}

			$parentfolder = mapi_msgstore_openentry($this->store, $parentfentryid);
			if (!$parentfolder) {
				throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, unable to open parent folder (open entry)", Utils::PrintAsString(false), $folder->parentid, $displayname), SYNC_FSSTATUS_PARENTNOTFOUND);
			}

			//  mapi_folder_createfolder() fails if a folder with this name already exists -> MAPI_E_COLLISION
			$newfolder = mapi_folder_createfolder($parentfolder, $displayname, "");
			if (mapi_last_hresult()) {
				throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, mapi_folder_createfolder() failed: 0x%X", Utils::PrintAsString(false), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_FOLDEREXISTS);
			}

			mapi_setprops($newfolder, [PR_CONTAINER_CLASS => MAPIUtils::GetContainerClassFromFolderType($type)]);

			$props = mapi_getprops($newfolder, [PR_SOURCE_KEY]);
			if (isset($props[PR_SOURCE_KEY])) {
				$folder->BackendId = bin2hex((string) $props[PR_SOURCE_KEY]);
				$folderOrigin = DeviceManager::FLD_ORIGIN_USER;
				if (GSync::GetBackend()->GetImpersonatedUser()) {
					$folderOrigin = DeviceManager::FLD_ORIGIN_IMPERSONATED;
				}
				$folder->serverid = GSync::GetDeviceManager()->GetFolderIdForBackendId($folder->BackendId, true, $folderOrigin, $folder->displayname);
				SLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesICS->ImportFolderChange(): Created folder '%s' with id: '%s' backendid: '%s'", $displayname, $folder->serverid, $folder->BackendId));

				return $folder;
			}

			throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, folder created but PR_SOURCE_KEY not available: 0x%X", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_SERVERERROR);
		}

		// open folder for update
		$entryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin((string) $id));
		if (!$entryid) {
			throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, unable to open folder (no entry id): 0x%X", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_PARENTNOTFOUND);
		}

		// check if this is a MAPI default folder
		if ($this->mapiprovider->IsMAPIDefaultFolder($entryid)) {
			throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, MAPI default folder can not be created/modified", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname), SYNC_FSSTATUS_SYSTEMFOLDER);
		}

		$mfolder = mapi_msgstore_openentry($this->store, $entryid);
		if (!$mfolder) {
			throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, unable to open folder (open entry): 0x%X", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_PARENTNOTFOUND);
		}

		$props = mapi_getprops($mfolder, [PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY, PR_DISPLAY_NAME, PR_CONTAINER_CLASS]);
		if (!isset($props[PR_SOURCE_KEY]) || !isset($props[PR_PARENT_SOURCE_KEY]) || !isset($props[PR_DISPLAY_NAME]) || !isset($props[PR_CONTAINER_CLASS])) {
			throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, folder data not available: 0x%X", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_SERVERERROR);
		}

		// get the real parent source key from mapi
		if ($parent == "0") {
			$parentprops = mapi_getprops($this->store, [PR_IPM_SUBTREE_ENTRYID, PR_IPM_PUBLIC_FOLDERS_ENTRYID]);
			if (GSync::GetBackend()->GetImpersonatedUser() == 'system') {
				$parentfentryid = $parentprops[PR_IPM_PUBLIC_FOLDERS_ENTRYID];
			}
			else {
				$parentfentryid = $parentprops[PR_IPM_SUBTREE_ENTRYID];
			}
			$mapifolder = mapi_msgstore_openentry($this->store, $parentfentryid);

			$rootfolderprops = mapi_getprops($mapifolder, [PR_SOURCE_KEY]);
			$parent = bin2hex((string) $rootfolderprops[PR_SOURCE_KEY]);
			SLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesICS->ImportFolderChange(): resolved AS parent '0' to sourcekey '%s'", $parent));
		}

		// a changed parent id means that the folder should be moved
		if (bin2hex((string) $props[PR_PARENT_SOURCE_KEY]) !== $parent) {
			$sourceparentfentryid = mapi_msgstore_entryidfromsourcekey($this->store, $props[PR_PARENT_SOURCE_KEY]);
			if (!$sourceparentfentryid) {
				throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, unable to open parent source folder (no entry id): 0x%X", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_PARENTNOTFOUND);
			}

			$sourceparentfolder = mapi_msgstore_openentry($this->store, $sourceparentfentryid);
			if (!$sourceparentfolder) {
				throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, unable to open parent source folder (open entry): 0x%X", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_PARENTNOTFOUND);
			}

			$destparentfentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin((string) $parent));
			if (!$sourceparentfentryid) {
				throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, unable to open destination folder (no entry id): 0x%X", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_SERVERERROR);
			}

			$destfolder = mapi_msgstore_openentry($this->store, $destparentfentryid);
			if (!$destfolder) {
				throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, unable to open destination folder (open entry): 0x%X", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_SERVERERROR);
			}

			// mapi_folder_copyfolder() fails if a folder with this name already exists -> MAPI_E_COLLISION
			if (!mapi_folder_copyfolder($sourceparentfolder, $entryid, $destfolder, $displayname, FOLDER_MOVE)) {
				throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, unable to move folder: 0x%X", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_FOLDEREXISTS);
			}

			// the parent changed, but we got a backendID as parent and have to return an AS folderid - the parent-backendId must be mapped at this point already
			if ($folder->parentid != 0) {
				$folder->parentid = GSync::GetDeviceManager()->GetFolderIdForBackendId($parent);
			}
			SLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesICS->ImportFolderChange(): Moved folder '%s' with id: %s/%s from: %s to: %s/%s", $displayname, $folder->serverid, $folder->BackendId, bin2hex((string) $props[PR_PARENT_SOURCE_KEY]), $folder->parentid, $parent_org));

			return $folder;
		}

		// update the display name
		$props = [PR_DISPLAY_NAME => $displayname];
		mapi_setprops($mfolder, $props);
		mapi_savechanges($mfolder);
		if (mapi_last_hresult()) {
			throw new StatusException(sprintf("ImportChangesICS->ImportFolderChange('%s','%s','%s'): Error, mapi_savechanges() failed: 0x%X", Utils::PrintAsString($folder->serverid), $folder->parentid, $displayname, mapi_last_hresult()), SYNC_FSSTATUS_SERVERERROR);
		}

		SLog::Write(LOGLEVEL_DEBUG, "Imported changes for folder: {$id}");

		return true;
	}

	/**
	 * Imports a folder deletion.
	 *
	 * @param SyncFolder $folder at least "serverid" needs to be set
	 *
	 * @return int SYNC_FOLDERHIERARCHY_STATUS
	 *
	 * @throws StatusException
	 */
	public function ImportFolderDeletion($folder) {
		$id = $folder->BackendId;
		$parent = $folder->parentid ?? false;
		SLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesICS->ImportFolderDeletion('%s','%s'): importing folder deletetion", $id, $parent));

		$folderentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin((string) $id));
		if (!$folderentryid) {
			throw new StatusException(sprintf("ImportChangesICS->ImportFolderDeletion('%s','%s'): Error, unable to resolve folder: 0x%X", $id, $parent, mapi_last_hresult()), SYNC_FSSTATUS_FOLDERDOESNOTEXIST);
		}

		// get the folder type from the MAPIProvider
		$type = $this->mapiprovider->GetFolderType($folderentryid);

		if (Utils::IsSystemFolder($type) || $this->mapiprovider->IsMAPIDefaultFolder($folderentryid)) {
			throw new StatusException(sprintf("ImportChangesICS->ImportFolderDeletion('%s','%s'): Error deleting system/default folder", $id, $parent), SYNC_FSSTATUS_SYSTEMFOLDER);
		}

		$ret = mapi_importhierarchychanges_importfolderdeletion($this->importer, 0, [PR_SOURCE_KEY => hex2bin((string) $id)]);
		if (!$ret) {
			throw new StatusException(sprintf("ImportChangesICS->ImportFolderDeletion('%s','%s'): Error deleting folder: 0x%X", $id, $parent, mapi_last_hresult()), SYNC_FSSTATUS_SERVERERROR);
		}

		return $ret;
	}
}
