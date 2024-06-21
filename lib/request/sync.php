<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * Provides the SYNC command
 */

class Sync extends RequestProcessor {
	// Ignored SMS identifier
	public const GSYNCIGNORESMS = "ZPISMS";
	private $importer;
	private $globallyExportedItems;
	private $singleFolder;
	private $multiFolderInfo;
	private $startTagsSent = false;
	private $startFolderTagSent = false;

	/**
	 * Handles the Sync command
	 * Performs the synchronization of messages.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public function Handle($commandCode) {
		// Contains all requested folders (containers)
		$sc = new SyncCollections();
		$status = SYNC_STATUS_SUCCESS;
		$wbxmlproblem = false;
		$emptysync = false;
		$this->singleFolder = true;
		$this->multiFolderInfo = [];
		$this->globallyExportedItems = 0;

		// check if the hierarchySync was fully completed
		if (USE_PARTIAL_FOLDERSYNC) {
			if (self::$deviceManager->GetFolderSyncComplete() === false) {
				SLog::Write(LOGLEVEL_INFO, "Request->HandleSync(): Sync request aborted, as exporting of folders has not yet completed");
				self::$topCollector->AnnounceInformation("Aborted due incomplete folder sync", true);
				$status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
			}
			else {
				SLog::Write(LOGLEVEL_INFO, "Request->HandleSync(): FolderSync marked as complete");
			}
		}

		// Start Synchronize
		if (self::$decoder->getElementStartTag(SYNC_SYNCHRONIZE)) {
			// AS 1.0 sends version information in WBXML
			if (self::$decoder->getElementStartTag(SYNC_VERSION)) {
				$sync_version = self::$decoder->getElementContent();
				SLog::Write(LOGLEVEL_DEBUG, sprintf("WBXML sync version: '%s'", $sync_version));
				if (!self::$decoder->getElementEndTag()) {
					return false;
				}
			}

			// Syncing specified folders
			// Android still sends heartbeat sync even if all syncfolders are disabled.
			// Check if Folders tag is empty (<Folders/>) and only sync if there are
			// some folders in the request.
			$startTag = self::$decoder->getElementStartTag(SYNC_FOLDERS);
			if (isset($startTag[EN_FLAGS]) && $startTag[EN_FLAGS]) {
				while (self::$decoder->getElementStartTag(SYNC_FOLDER)) {
					$actiondata = [];
					$actiondata["requested"] = true;
					$actiondata["clientids"] = [];
					$actiondata["modifyids"] = [];
					$actiondata["removeids"] = [];
					$actiondata["fetchids"] = [];
					$actiondata["statusids"] = [];

					// read class, synckey and folderid without SyncParameters Object for now
					$class = $synckey = $folderid = false;

					// if there are already collections in SyncCollections, this is min. the second folder
					if ($sc->HasCollections()) {
						$this->singleFolder = false;
					}

					// for AS versions < 2.5
					if (self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
						$class = self::$decoder->getElementContent();
						SLog::Write(LOGLEVEL_DEBUG, sprintf("Sync folder: '%s'", $class));

						if (!self::$decoder->getElementEndTag()) {
							return false;
						}
					}

					// SyncKey
					if (self::$decoder->getElementStartTag(SYNC_SYNCKEY)) {
						$synckey = "0";
						if (($synckey = self::$decoder->getElementContent()) !== false) {
							if (!self::$decoder->getElementEndTag()) {
								return false;
							}
						}
					}
					else {
						return false;
					}

					// FolderId
					if (self::$decoder->getElementStartTag(SYNC_FOLDERID)) {
						$folderid = self::$decoder->getElementContent();

						if (!self::$decoder->getElementEndTag()) {
							return false;
						}
					}

					// compatibility mode AS 1.0 - get folderid which was sent during GetHierarchy()
					if (!$folderid && $class) {
						$folderid = self::$deviceManager->GetFolderIdFromCacheByClass($class);
					}

					// folderid HAS TO BE known by now, so we retrieve the correct SyncParameters object for an update
					try {
						$spa = self::$deviceManager->GetStateManager()->GetSynchedFolderState($folderid);

						// TODO remove resync of folders
						// this forces a resync of all states
						if (!$spa instanceof SyncParameters) {
							throw new StateInvalidException("Saved state are not of type SyncParameters");
						}

						// new/resync requested
						if ($synckey == "0") {
							$spa->RemoveSyncKey();
							$spa->DelFolderStat();
							$spa->SetMoveState(false);
						}
						elseif ($synckey !== false) {
							if ($synckey !== $spa->GetSyncKey() && $synckey !== $spa->GetNewSyncKey()) {
								SLog::Write(LOGLEVEL_DEBUG, "HandleSync(): Synckey does not match latest saved for this folder or there is a move state, removing folderstat to force Exporter setup");
								$spa->DelFolderStat();
							}
							$spa->SetSyncKey($synckey);
						}
					}
					catch (StateInvalidException $stie) {
						$spa = new SyncParameters();
						$status = SYNC_STATUS_INVALIDSYNCKEY;
						self::$topCollector->AnnounceInformation("State invalid - Resync folder", $this->singleFolder);
						self::$deviceManager->ForceFolderResync($folderid);
						$this->saveMultiFolderInfo("exception", "StateInvalidException");
					}

					// update folderid.. this might be a new object
					$spa->SetFolderId($folderid);
					$spa->SetBackendFolderId(self::$deviceManager->GetBackendIdForFolderId($folderid));

					if ($class !== false) {
						$spa->SetContentClass($class);
					}

					// Get class for as versions >= 12.0
					if (!$spa->HasContentClass()) {
						try {
							$spa->SetContentClass(self::$deviceManager->GetFolderClassFromCacheByID($spa->GetFolderId()));
							SLog::Write(LOGLEVEL_DEBUG, sprintf("GetFolderClassFromCacheByID from Device Manager: '%s' for id:'%s'", $spa->GetContentClass(), $spa->GetFolderId()));
						}
						catch (NoHierarchyCacheAvailableException $nhca) {
							$status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
							self::$deviceManager->ForceFullResync();
						}
					}

					// done basic SPA initialization/loading -> add to SyncCollection
					$sc->AddCollection($spa);
					$sc->AddParameter($spa, "requested", true);

					if ($spa->HasContentClass()) {
						self::$topCollector->AnnounceInformation(sprintf("%s request", $spa->GetContentClass()), $this->singleFolder);
					}
					else {
						SLog::Write(LOGLEVEL_WARN, "Not possible to determine class of request. Request did not contain class and apparently there is an issue with the HierarchyCache.");
					}

					// SUPPORTED properties
					if (($se = self::$decoder->getElementStartTag(SYNC_SUPPORTED)) !== false) {
						// LG phones send an empty supported tag, so only read the contents if available here
						// if <Supported/> is received, it's as no supported fields would have been sent at all.
						// unsure if this is the correct approach, or if in this case some default list should be used
						if ($se[EN_FLAGS] & EN_FLAGS_CONTENT) {
							$supfields = [];
							WBXMLDecoder::ResetInWhile("syncSupported");
							while (WBXMLDecoder::InWhile("syncSupported")) {
								$el = self::$decoder->getElement();

								if ($el[EN_TYPE] == EN_TYPE_ENDTAG) {
									break;
								}

								$supfields[] = $el[EN_TAG];
							}
							self::$deviceManager->SetSupportedFields($spa->GetFolderId(), $supfields);
						}
					}

					// Deletes as moves can be an empty tag as well as have value
					if (self::$decoder->getElementStartTag(SYNC_DELETESASMOVES)) {
						$spa->SetDeletesAsMoves(true);
						if (($dam = self::$decoder->getElementContent()) !== false) {
							$spa->SetDeletesAsMoves((bool) $dam);
							if (!self::$decoder->getElementEndTag()) {
								return false;
							}
						}
					}

					// Get changes can be an empty tag as well as have value
					// code block partly contributed by dw2412
					if ($starttag = self::$decoder->getElementStartTag(SYNC_GETCHANGES)) {
						$sc->AddParameter($spa, "getchanges", true);
						if (($gc = self::$decoder->getElementContent()) !== false) {
							$sc->AddParameter($spa, "getchanges", $gc);
						}
						// read the endtag if SYNC_GETCHANGES wasn't an empty tag
						if ($starttag[EN_FLAGS] & EN_FLAGS_CONTENT) {
							if (!self::$decoder->getElementEndTag()) {
								return false;
							}
						}
					}

					if (self::$decoder->getElementStartTag(SYNC_WINDOWSIZE)) {
						$ws = self::$decoder->getElementContent();
						// normalize windowsize
						if ($ws == 0 || $ws > WINDOW_SIZE_MAX) {
							$ws = WINDOW_SIZE_MAX;
						}

						$spa->SetWindowSize($ws);

						// also announce the currently requested window size to the DeviceManager
						self::$deviceManager->SetWindowSize($spa->GetFolderId(), $spa->GetWindowSize());

						if (!self::$decoder->getElementEndTag()) {
							return false;
						}
					}

					// conversation mode requested
					if (self::$decoder->getElementStartTag(SYNC_CONVERSATIONMODE)) {
						$spa->SetConversationMode(true);
						if (($conversationmode = self::$decoder->getElementContent()) !== false) {
							$spa->SetConversationMode((bool) $conversationmode);
							if (!self::$decoder->getElementEndTag()) {
								return false;
							}
						}
					}

					// Do not truncate by default
					$spa->SetTruncation(SYNC_TRUNCATION_ALL);

					// use default conflict handling if not specified by the mobile
					$spa->SetConflict(SYNC_CONFLICT_DEFAULT);

					// save the current filtertype because it might have been changed on the mobile
					$currentFilterType = $spa->GetFilterType();

					while (self::$decoder->getElementStartTag(SYNC_OPTIONS)) {
						$firstOption = true;
						WBXMLDecoder::ResetInWhile("syncOptions");
						while (WBXMLDecoder::InWhile("syncOptions")) {
							// foldertype definition
							if (self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
								$foldertype = self::$decoder->getElementContent();
								SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): specified options block with foldertype '%s'", $foldertype));

								// switch the foldertype for the next options
								$spa->UseCPO($foldertype);

								// save the current filtertype because it might have been changed on the mobile
								$currentFilterType = $spa->GetFilterType();

								// set to synchronize all changes. The mobile could overwrite this value
								$spa->SetFilterType(SYNC_FILTERTYPE_ALL);

								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}
							// if no foldertype is defined, use default cpo
							elseif ($firstOption) {
								$spa->UseCPO();
								// save the current filtertype because it might have been changed on the mobile
								$currentFilterType = $spa->GetFilterType();
								// set to synchronize all changes. The mobile could overwrite this value
								$spa->SetFilterType(SYNC_FILTERTYPE_ALL);
							}
							$firstOption = false;

							if (self::$decoder->getElementStartTag(SYNC_FILTERTYPE)) {
								$spa->SetFilterType(self::$decoder->getElementContent());
								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}
							if (self::$decoder->getElementStartTag(SYNC_TRUNCATION)) {
								$spa->SetTruncation(self::$decoder->getElementContent());
								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}
							if (self::$decoder->getElementStartTag(SYNC_RTFTRUNCATION)) {
								$spa->SetRTFTruncation(self::$decoder->getElementContent());
								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}

							if (self::$decoder->getElementStartTag(SYNC_MIMESUPPORT)) {
								$spa->SetMimeSupport(self::$decoder->getElementContent());
								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}

							if (self::$decoder->getElementStartTag(SYNC_MIMETRUNCATION)) {
								$spa->SetMimeTruncation(self::$decoder->getElementContent());
								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}

							if (self::$decoder->getElementStartTag(SYNC_CONFLICT)) {
								$spa->SetConflict(self::$decoder->getElementContent());
								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}

							while (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)) {
								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
									$bptype = self::$decoder->getElementContent();
									$spa->BodyPreference($bptype);
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
									$spa->BodyPreference($bptype)->SetTruncationSize(self::$decoder->getElementContent());
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
									$spa->BodyPreference($bptype)->SetAllOrNone(self::$decoder->getElementContent());
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_PREVIEW)) {
									$spa->BodyPreference($bptype)->SetPreview(self::$decoder->getElementContent());
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}

							if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPARTPREFERENCE)) {
								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
									$bpptype = self::$decoder->getElementContent();
									$spa->BodyPartPreference($bpptype);
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
									$spa->BodyPartPreference($bpptype)->SetTruncationSize(self::$decoder->getElementContent());
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
									$spa->BodyPartPreference($bpptype)->SetAllOrNone(self::$decoder->getElementContent());
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_PREVIEW)) {
									$spa->BodyPartPreference($bpptype)->SetPreview(self::$decoder->getElementContent());
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}

							if (self::$decoder->getElementStartTag(SYNC_RIGHTSMANAGEMENT_SUPPORT)) {
								$spa->SetRmSupport(self::$decoder->getElementContent());
								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}

							$e = self::$decoder->peek();
							if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
								self::$decoder->getElementEndTag();

								break;
							}
						}
					}

					// limit items to be synchronized to the mobiles if configured
					$maxAllowed = self::$deviceManager->GetFilterType($spa->GetFolderId(), $spa->GetBackendFolderId());
					if ($maxAllowed > SYNC_FILTERTYPE_ALL &&
						(!$spa->HasFilterType() || $spa->GetFilterType() == SYNC_FILTERTYPE_ALL || $spa->GetFilterType() > $maxAllowed)) {
						SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): FilterType applied globally or specifically, using value: %s", $maxAllowed));
						$spa->SetFilterType($maxAllowed);
					}

					if ($currentFilterType != $spa->GetFilterType()) {
						SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): FilterType has changed (old: '%s', new: '%s'), removing folderstat to force Exporter setup", $currentFilterType, $spa->GetFilterType()));
						$spa->DelFolderStat();
					}

					// Check if the hierarchycache is available. If not, trigger a HierarchySync
					if (self::$deviceManager->IsHierarchySyncRequired()) {
						$status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
						SLog::Write(LOGLEVEL_DEBUG, "HierarchyCache is also not available. Triggering HierarchySync to device");
					}

					// AS16: Check if this is a DRAFTS folder - if so, disable FilterType
					if (Request::GetProtocolVersion() >= 16.0 && self::$deviceManager->GetFolderTypeFromCacheById($spa->GetFolderId()) == SYNC_FOLDER_TYPE_DRAFTS) {
						$spa->SetFilterType(SYNC_FILTERTYPE_DISABLE);
						SLog::Write(LOGLEVEL_DEBUG, "HandleSync(): FilterType has been disabled as this is a DRAFTS folder.");
					}

					if (($el = self::$decoder->getElementStartTag(SYNC_PERFORM)) && ($el[EN_FLAGS] & EN_FLAGS_CONTENT)) {
						// We can not proceed here as the content class is unknown
						if ($status != SYNC_STATUS_SUCCESS) {
							SLog::Write(LOGLEVEL_WARN, "Ignoring all incoming actions as global status indicates problem.");
							$wbxmlproblem = true;

							break;
						}

						$performaction = true;

						// unset the importer
						$this->importer = false;

						$nchanges = 0;
						WBXMLDecoder::ResetInWhile("syncActions");
						while (WBXMLDecoder::InWhile("syncActions")) {
							// ADD, MODIFY, REMOVE or FETCH
							$element = self::$decoder->getElement();

							if ($element[EN_TYPE] != EN_TYPE_STARTTAG) {
								self::$decoder->ungetElement($element);

								break;
							}

							if ($status == SYNC_STATUS_SUCCESS) {
								++$nchanges;
							}

							// Foldertype sent when syncing SMS
							if (self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
								$foldertype = self::$decoder->getElementContent();
								SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): incoming data with foldertype '%s'", $foldertype));

								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}
							else {
								$foldertype = false;
							}

							$serverid = false;
							if (self::$decoder->getElementStartTag(SYNC_SERVERENTRYID)) {
								if (($serverid = self::$decoder->getElementContent()) !== false) {
									if (!self::$decoder->getElementEndTag()) { // end serverid
										return false;
									}
								}
							}
							// get the instanceId if available
							$instanceid = false;
							if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_INSTANCEID)) {
								if (($instanceid = self::$decoder->getElementContent()) !== false) {
									if (!self::$decoder->getElementEndTag()) { // end instanceid
										return false;
									}
								}
							}

							if (self::$decoder->getElementStartTag(SYNC_CLIENTENTRYID)) {
								$clientid = self::$decoder->getElementContent();

								if (!self::$decoder->getElementEndTag()) { // end clientid
									return false;
								}
							}
							else {
								$clientid = false;
							}

							// Get the SyncMessage if sent
							if (($el = self::$decoder->getElementStartTag(SYNC_DATA)) && ($el[EN_FLAGS] & EN_FLAGS_CONTENT)) {
								$message = GSync::getSyncObjectFromFolderClass($spa->GetContentClass());
								$message->Decode(self::$decoder);

								// set Ghosted fields
								$message->emptySupported(self::$deviceManager->GetSupportedFields($spa->GetFolderId()));

								if (!self::$decoder->getElementEndTag()) { // end applicationdata
									return false;
								}
							}
							else {
								$message = false;
							}

							// InstanceID sent: do action to a recurrency exception
							if ($instanceid) {
								// for delete actions we don't have an ASObject
								if (!$message) {
									$message = GSync::getSyncObjectFromFolderClass($spa->GetContentClass());
									$message->Decode(self::$decoder);
								}
								$message->instanceid = $instanceid;
								if ($element[EN_TAG] == SYNC_REMOVE) {
									$message->instanceiddelete = true;
									$element[EN_TAG] = SYNC_MODIFY;
								}
							}

							switch ($element[EN_TAG]) {
								case SYNC_FETCH:
									array_push($actiondata["fetchids"], $serverid);
									break;

								default:
									// get the importer
									if ($this->importer === false) {
										$status = $this->getImporter($sc, $spa, $actiondata);
									}

									if ($status == SYNC_STATUS_SUCCESS) {
										$this->importMessage($spa, $actiondata, $element[EN_TAG], $message, $clientid, $serverid, $foldertype, $nchanges);
									}
									else {
										SLog::Write(LOGLEVEL_WARN, "Ignored incoming change, global status indicates problem.");
									}
									break;
							}

							if ($actiondata["fetchids"]) {
								self::$topCollector->AnnounceInformation(sprintf("Fetching %d", $nchanges));
							}
							else {
								self::$topCollector->AnnounceInformation(sprintf("Incoming %d", $nchanges));
							}

							if (!self::$decoder->getElementEndTag()) { // end add/change/delete/move
								return false;
							}
						}

						if ($status == SYNC_STATUS_SUCCESS && $this->importer !== false) {
							SLog::Write(LOGLEVEL_INFO, sprintf("Processed '%d' incoming changes", $nchanges));
							if (!$actiondata["fetchids"]) {
								self::$topCollector->AnnounceInformation(sprintf("%d incoming", $nchanges), $this->singleFolder);
								$this->saveMultiFolderInfo("incoming", $nchanges);
							}

							try {
								// Save the updated state, which is used for the exporter later
								$sc->AddParameter($spa, "state", $this->importer->GetState());
							}
							catch (StatusException $stex) {
								$status = $stex->getCode();
							}

							// Check if changes are requested - if not and there are no changes to be exported anyway, update folderstat!
							if (!$sc->GetParameter($spa, "getchanges") && !$sc->CountChange($spa->GetFolderId())) {
								SLog::Write(LOGLEVEL_DEBUG, "Incoming changes, no export requested: update folderstat");
								$newFolderStatAfterImport = self::$backend->GetFolderStat(GSync::GetAdditionalSyncFolderStore($spa->GetBackendFolderId()), $spa->GetBackendFolderId());
								$this->setFolderStat($spa, $newFolderStatAfterImport);
							}
						}

						if (!self::$decoder->getElementEndTag()) { // end PERFORM
							return false;
						}
					}

					// save the failsafe state
					if (!empty($actiondata["statusids"])) {
						unset($actiondata["failstate"]);
						$actiondata["failedsyncstate"] = $sc->GetParameter($spa, "state");
						self::$deviceManager->GetStateManager()->SetSyncFailState($actiondata);
					}

					// save actiondata
					$sc->AddParameter($spa, "actiondata", $actiondata);

					if (!self::$decoder->getElementEndTag()) { // end collection
						return false;
					}

					// AS14 does not send GetChanges anymore. We should do it if there were no incoming changes
					if (!isset($performaction) && !$sc->GetParameter($spa, "getchanges") && $spa->HasSyncKey()) {
						$sc->AddParameter($spa, "getchanges", true);
					}
				} // END FOLDER

				if (!$wbxmlproblem && !self::$decoder->getElementEndTag()) { // end collections
					return false;
				}
			} // end FOLDERS

			if (self::$decoder->getElementStartTag(SYNC_HEARTBEATINTERVAL)) {
				$hbinterval = self::$decoder->getElementContent();
				if (!self::$decoder->getElementEndTag()) { // SYNC_HEARTBEATINTERVAL
					return false;
				}
			}

			if (self::$decoder->getElementStartTag(SYNC_WAIT)) {
				$wait = self::$decoder->getElementContent();
				if (!self::$decoder->getElementEndTag()) { // SYNC_WAIT
					return false;
				}

				// internally the heartbeat interval and the wait time are the same
				// heartbeat is in seconds, wait in minutes
				$hbinterval = $wait * 60;
			}

			if (self::$decoder->getElementStartTag(SYNC_WINDOWSIZE)) {
				$sc->SetGlobalWindowSize(self::$decoder->getElementContent());
				SLog::Write(LOGLEVEL_DEBUG, "Sync(): Global WindowSize requested: " . $sc->GetGlobalWindowSize());
				if (!self::$decoder->getElementEndTag()) { // SYNC_WINDOWSIZE
					return false;
				}
			}

			if (self::$decoder->getElementStartTag(SYNC_PARTIAL)) {
				$partial = true;
			}
			else {
				$partial = false;
			}

			if (!$wbxmlproblem && !self::$decoder->getElementEndTag()) { // end sync
				return false;
			}
		}
		// we did not receive a SYNCHRONIZE block - assume empty sync
		else {
			$emptysync = true;
		}
		// END SYNCHRONIZE

		// check heartbeat/wait time
		if (isset($hbinterval)) {
			if ($hbinterval < 60 || $hbinterval > 3540) {
				$status = SYNC_STATUS_INVALIDWAITORHBVALUE;
				SLog::Write(LOGLEVEL_WARN, sprintf("HandleSync(): Invalid heartbeat or wait value '%s'", $hbinterval));
			}
		}

		// Partial & Empty Syncs need saved data to proceed with synchronization
		if ($status == SYNC_STATUS_SUCCESS && ($emptysync === true || $partial === true)) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Partial or Empty sync requested. Retrieving data of synchronized folders."));

			// Load all collections - do not overwrite existing (received!), load states, check permissions and only load confirmed states!
			try {
				$sc->LoadAllCollections(false, true, true, true, true);
			}
			catch (StateInvalidException $siex) {
				$status = SYNC_STATUS_INVALIDSYNCKEY;
				self::$topCollector->AnnounceInformation("StateNotFoundException", $this->singleFolder);
				$this->saveMultiFolderInfo("exception", "StateNotFoundException");
			}
			catch (StatusException $stex) {
				$status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
				self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), $this->singleFolder);
				$this->saveMultiFolderInfo("exception", "StatusException");
			}

			// update a few values
			foreach ($sc as $folderid => $spa) {
				// manually set getchanges parameter for this collection if it is synchronized
				if ($spa->HasSyncKey()) {
					$actiondata = $sc->GetParameter($spa, "actiondata");
					// request changes if no other actions are executed
					if (empty($actiondata["modifyids"]) && empty($actiondata["clientids"]) && empty($actiondata["removeids"])) {
						$sc->AddParameter($spa, "getchanges", true);
					}

					// announce WindowSize to DeviceManager
					self::$deviceManager->SetWindowSize($folderid, $spa->GetWindowSize());
				}
			}
			if (!$sc->HasCollections()) {
				$status = SYNC_STATUS_SYNCREQUESTINCOMPLETE;
			}
		}
		elseif (isset($hbinterval)) {
			// load the hierarchy data - there are no permissions to verify so we just set it to false
			if (!$sc->LoadCollection(false, true, false)) {
				$status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
				self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), $this->singleFolder);
				$this->saveMultiFolderInfo("exception", "StatusException");
			}
		}

		// HEARTBEAT
		if ($status == SYNC_STATUS_SUCCESS && isset($hbinterval)) {
			$interval = (defined('PING_INTERVAL') && PING_INTERVAL > 0) ? PING_INTERVAL : 30;
			$sc->SetLifetime($hbinterval);

			// states are lazy loaded - we have to make sure that they are there!
			$loadstatus = SYNC_STATUS_SUCCESS;
			foreach ($sc as $folderid => $spa) {
				// some androids do heartbeat on the OUTBOX folder, with weird results
				// we do not load the state so we will never get relevant changes on the OUTBOX folder
				if (self::$deviceManager->GetFolderTypeFromCacheById($folderid) == SYNC_FOLDER_TYPE_OUTBOX) {
					SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Heartbeat on Outbox folder not allowed"));

					continue;
				}

				$fad = [];
				// if loading the states fails, we do not enter heartbeat, but we keep $status on SYNC_STATUS_SUCCESS
				// so when the changes are exported the correct folder gets an SYNC_STATUS_INVALIDSYNCKEY
				if ($loadstatus == SYNC_STATUS_SUCCESS) {
					$loadstatus = $this->loadStates($sc, $spa, $fad);
				}
			}

			if ($loadstatus == SYNC_STATUS_SUCCESS) {
				$foundchanges = false;

				try {
					// always check for changes
					SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Entering Heartbeat mode"));
					$foundchanges = $sc->CheckForChanges($sc->GetLifetime(), $interval);
				}
				catch (StatusException $stex) {
					if ($stex->getCode() == SyncCollections::OBSOLETE_CONNECTION) {
						$status = SYNC_COMMONSTATUS_SYNCSTATEVERSIONINVALID;
					}
					else {
						$status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
						self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), $this->singleFolder);
						$this->saveMultiFolderInfo("exception", "StatusException");
					}
				}

				// update the waittime waited
				self::$waitTime = $sc->GetWaitedSeconds();

				// in case there are no changes and no other request has synchronized while we waited, we can reply with an empty response
				if (!$foundchanges && $status == SYNC_STATUS_SUCCESS) {
					// if there were changes to the SPA or CPOs we need to save this before we terminate
					// only save if the state was not modified by some other request, if so, return state invalid status
					foreach ($sc as $folderid => $spa) {
						if (self::$deviceManager->CheckHearbeatStateIntegrity($spa->GetFolderId(), $spa->GetUuid(), $spa->GetUuidCounter())) {
							$status = SYNC_COMMONSTATUS_SYNCSTATEVERSIONINVALID;
						}
						else {
							$sc->SaveCollection($spa);
						}
					}

					if ($status == SYNC_STATUS_SUCCESS) {
						SLog::Write(LOGLEVEL_DEBUG, "No changes found and no other process changed states. Replying with empty response and closing connection.");
						self::$specialHeaders = [];
						self::$specialHeaders[] = "Connection: close";

						return true;
					}
				}

				if ($foundchanges) {
					foreach ($sc->GetChangedFolderIds() as $folderid => $changecount) {
						// check if there were other sync requests for a folder during the heartbeat
						$spa = $sc->GetCollection($folderid);
						if ($changecount > 0 && $sc->WaitedForChanges() && self::$deviceManager->CheckHearbeatStateIntegrity($spa->GetFolderId(), $spa->GetUuid(), $spa->GetUuidCounter())) {
							SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): heartbeat: found %d changes in '%s' which was already synchronized. Heartbeat aborted!", $changecount, $folderid));
							$status = SYNC_COMMONSTATUS_SYNCSTATEVERSIONINVALID;
						}
						else {
							SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): heartbeat: found %d changes in '%s'", $changecount, $folderid));
						}
					}
				}
			}
		}

		// Start the output
		SLog::Write(LOGLEVEL_DEBUG, "HandleSync(): Start Output");

		// global status
		// SYNC_COMMONSTATUS_* start with values from 101
		if ($status != SYNC_COMMONSTATUS_SUCCESS && ($status == SYNC_STATUS_FOLDERHIERARCHYCHANGED || $status > 100)) {
			self::$deviceManager->AnnounceProcessStatus($folderid, $status);
			$this->sendStartTags();
			self::$encoder->startTag(SYNC_STATUS);
			self::$encoder->content($status);
			self::$encoder->endTag();
			self::$encoder->endTag(); // SYNC_SYNCHRONIZE

			return true;
		}

		// Loop through requested folders
		foreach ($sc as $folderid => $spa) {
			// get actiondata
			$actiondata = $sc->GetParameter($spa, "actiondata");

			if ($status == SYNC_STATUS_SUCCESS && (!$spa->GetContentClass() || !$spa->GetFolderId())) {
				SLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): no content class or folderid found for collection."));

				continue;
			}

			if (!$sc->GetParameter($spa, "requested")) {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): partial sync for folder class '%s' with id '%s'", $spa->GetContentClass(), $spa->GetFolderId()));
				// reload state and initialize StateMachine correctly
				$sc->AddParameter($spa, "state", null);
				$status = $this->loadStates($sc, $spa, $actiondata);
			}

			// initialize exporter to get changecount
			$changecount = false;
			$exporter = false;
			$streamimporter = false;
			$newFolderStat = false;
			$setupExporter = true;

			// TODO we could check against $sc->GetChangedFolderIds() on heartbeat so we do not need to configure all exporter again
			if ($status == SYNC_STATUS_SUCCESS && ($sc->GetParameter($spa, "getchanges") || !$spa->HasSyncKey())) {
				// no need to run the exporter if the globalwindowsize is already full - if collection already has a synckey
				if ($sc->GetGlobalWindowSize() == $this->globallyExportedItems && $spa->HasSyncKey()) {
					SLog::Write(LOGLEVEL_DEBUG, sprintf("Sync(): no exporter setup for '%s' as GlobalWindowSize is full.", $spa->GetFolderId()));
					$setupExporter = false;
				}
				// if the maximum request timeout is reached, stop processing other collections
				if (Request::IsRequestTimeoutReached()) {
					SLog::Write(LOGLEVEL_DEBUG, sprintf("Sync(): no exporter setup for '%s' as request timeout reached, omitting output for collection.", $spa->GetFolderId()));
					$setupExporter = false;
				}

				// if max memory allocation is reached, stop processing other collections
				if (Request::IsRequestMemoryLimitReached()) {
					SLog::Write(LOGLEVEL_DEBUG, sprintf("Sync(): no exporter setup for '%s' as max memory allocatation reached, omitting output for collection.", $spa->GetFolderId()));
					$setupExporter = false;
				}

				// force exporter run if there is a saved status
				if ($setupExporter && self::$deviceManager->HasFolderSyncStatus($spa->GetFolderId())) {
					SLog::Write(LOGLEVEL_DEBUG, sprintf("Sync(): forcing exporter setup for '%s' as a sync status is saved - ignoring backend folder stats", $spa->GetFolderId()));
				}
				// compare the folder statistics if the backend supports this
				elseif ($setupExporter && self::$backend->HasFolderStats()) {
					// check if the folder stats changed -> if not, don't setup the exporter, there are no changes!
					$newFolderStat = self::$backend->GetFolderStat(GSync::GetAdditionalSyncFolderStore($spa->GetBackendFolderId()), $spa->GetBackendFolderId());
					if ($newFolderStat !== false && !$spa->IsExporterRunRequired($newFolderStat, true)) {
						$changecount = 0;
						$setupExporter = false;
					}
				}

				// Do a full Exporter setup if we can't avoid it
				if ($setupExporter) {
					// make sure the states are loaded
					$status = $this->loadStates($sc, $spa, $actiondata);

					if ($status == SYNC_STATUS_SUCCESS) {
						try {
							// if this is an additional folder the backend has to be setup correctly
							if (!self::$backend->Setup(GSync::GetAdditionalSyncFolderStore($spa->GetBackendFolderId()))) {
								throw new StatusException(sprintf("HandleSync() could not Setup() the backend for folder id %s/%s", $spa->GetFolderId(), $spa->GetBackendFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);
							}

							// Use the state from the importer, as changes may have already happened
							$exporter = self::$backend->GetExporter($spa->GetBackendFolderId());

							if ($exporter === false) {
								throw new StatusException(sprintf("HandleSync() could not get an exporter for folder id %s/%s", $spa->GetFolderId(), $spa->GetBackendFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);
							}
						}
						catch (StatusException $stex) {
							$status = $stex->getCode();
						}

						try {
							// Stream the messages directly to the PDA
							$streamimporter = new ImportChangesStream(self::$encoder, GSync::getSyncObjectFromFolderClass($spa->GetContentClass()));

							if ($exporter !== false) {
								$exporter->Config($sc->GetParameter($spa, "state"));
								$exporter->ConfigContentParameters($spa->GetCPO());
								$exporter->InitializeExporter($streamimporter);

								$changecount = $exporter->GetChangeCount();
							}
						}
						catch (StatusException $stex) {
							if ($stex->getCode() === SYNC_FSSTATUS_CODEUNKNOWN && $spa->HasSyncKey()) {
								$status = SYNC_STATUS_INVALIDSYNCKEY;
							}
							else {
								$status = $stex->getCode();
							}
						}

						if (!$spa->HasSyncKey()) {
							self::$topCollector->AnnounceInformation(sprintf("Exporter registered. %d objects queued.", $changecount), $this->singleFolder);
							$this->saveMultiFolderInfo("queued", $changecount);
							// update folder status as initialized
							$spa->SetFolderSyncTotal($changecount);
							$spa->SetFolderSyncRemaining($changecount);
							if ($changecount > 0) {
								self::$deviceManager->SetFolderSyncStatus($folderid, DeviceManager::FLD_SYNC_INITIALIZED);
							}
						}
						elseif ($status != SYNC_STATUS_SUCCESS) {
							self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), $this->singleFolder);
							$this->saveMultiFolderInfo("exception", "StatusException");
						}
						self::$deviceManager->AnnounceProcessStatus($spa->GetFolderId(), $status);
					}
				}
			}

			// Get a new sync key to output to the client if any changes have been send by the mobile or a new synckey is to be sent
			if (!empty($actiondata["modifyids"]) ||
				!empty($actiondata["clientids"]) ||
				!empty($actiondata["removeids"]) ||
				(!$spa->HasSyncKey() && $status == SYNC_STATUS_SUCCESS)) {
				$spa->SetNewSyncKey(self::$deviceManager->GetStateManager()->GetNewSyncKey($spa->GetSyncKey()));
			}
			// get a new synckey only if we did not reach the global limit yet
			else {
				// when reaching the global limit for changes of all collections, stop processing other collections
				if ($sc->GetGlobalWindowSize() <= $this->globallyExportedItems) {
					SLog::Write(LOGLEVEL_DEBUG, "Global WindowSize for amount of exported changes reached, omitting output for collection.");

					continue;
				}

				// get a new synckey if there are changes are we did not reach the limit yet
				if ($changecount > 0) {
					$spa->SetNewSyncKey(self::$deviceManager->GetStateManager()->GetNewSyncKey($spa->GetSyncKey()));
				}
			}

			// Fir AS 14.0+ omit output for folder, if there were no incoming or outgoing changes and no Fetch
			if (Request::GetProtocolVersion() >= 14.0 && !$spa->HasNewSyncKey() && $changecount == 0 && empty($actiondata["fetchids"]) && $status == SYNC_STATUS_SUCCESS &&
					!$spa->HasConfirmationChanged() && ($newFolderStat === false || !$spa->IsExporterRunRequired($newFolderStat))) {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync: No changes found for %s folder id '%s'. Omitting output.", $spa->GetContentClass(), $spa->GetFolderId()));

				continue;
			}

			// if there are no other responses sent, we should end with a global status
			if ($status == SYNC_STATUS_FOLDERHIERARCHYCHANGED && $this->startTagsSent === false) {
				$this->sendStartTags();
				self::$encoder->startTag(SYNC_STATUS);
				self::$encoder->content($status);
				self::$encoder->endTag();
				self::$encoder->endTag(); // SYNC_SYNCHRONIZE

				return true;
			}

			// there is something to send here, sync folder to output
			$this->syncFolder($sc, $spa, $exporter, $changecount, $streamimporter, $status, $newFolderStat);

			// reset status for the next folder
			$status = SYNC_STATUS_SUCCESS;
		} // END foreach collection

		// SYNC_FOLDERS - only if the starttag was sent
		if ($this->startFolderTagSent) {
			self::$encoder->endTag();
		}

		// Check if there was any response - in case of an empty sync request, we shouldn't send an empty answer
		if (!$this->startTagsSent && $emptysync === true) {
			$this->sendStartTags();
			self::$encoder->startTag(SYNC_STATUS);
			self::$encoder->content(SYNC_STATUS_SYNCREQUESTINCOMPLETE);
			self::$encoder->endTag();
		}

		// SYNC_SYNCHRONIZE - only if the starttag was sent
		if ($this->startTagsSent) {
			self::$encoder->endTag();
		}

		// final top announcement for a multi-folder sync
		if ($sc->GetCollectionCount() > 1) {
			self::$topCollector->AnnounceInformation($this->getMultiFolderInfoLine($sc->GetCollectionCount()), true);
			SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync: Processed %d folders", $sc->GetCollectionCount()));
		}

		// update the waittime waited
		self::$waitTime = $sc->GetWaitedSeconds();

		return true;
	}

	/**
	 * Sends the SYNC_SYNCHRONIZE once per request.
	 */
	private function sendStartTags() {
		if ($this->startTagsSent === false) {
			self::$encoder->startWBXML();
			self::$encoder->startTag(SYNC_SYNCHRONIZE);
			$this->startTagsSent = true;
		}
	}

	/**
	 * Sends the SYNC_FOLDERS once per request.
	 */
	private function sendFolderStartTag() {
		$this->sendStartTags();
		if ($this->startFolderTagSent === false) {
			self::$encoder->startTag(SYNC_FOLDERS);
			$this->startFolderTagSent = true;
		}
	}

	/**
	 * Synchronizes a folder to the output stream. Changes for this folders are expected.
	 *
	 * @param SyncCollections     $sc
	 * @param SyncParameters      $spa
	 * @param IExportChanges      $exporter       Fully configured exporter for this folder
	 * @param int                 $changecount    Amount of changes expected
	 * @param ImportChangesStream $streamimporter Output stream
	 * @param int                 $status         current status of the folder processing
	 * @param string              $newFolderStat  the new folder stat to be set if everything was exported
	 *
	 * @return int sync status code
	 *
	 * @throws StatusException
	 */
	private function syncFolder($sc, $spa, $exporter, $changecount, $streamimporter, $status, $newFolderStat) {
		$actiondata = $sc->GetParameter($spa, "actiondata");

		// send the WBXML start tags (if not happened already)
		$this->sendFolderStartTag();
		self::$encoder->startTag(SYNC_FOLDER);

		if ($spa->HasContentClass()) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("Folder type: %s", $spa->GetContentClass()));
			// AS 12.0 devices require content class
			if (Request::GetProtocolVersion() < 12.1) {
				self::$encoder->startTag(SYNC_FOLDERTYPE);
				self::$encoder->content($spa->GetContentClass());
				self::$encoder->endTag();
			}
		}

		self::$encoder->startTag(SYNC_SYNCKEY);
		if ($status == SYNC_STATUS_SUCCESS && $spa->HasNewSyncKey()) {
			self::$encoder->content($spa->GetNewSyncKey());
		}
		else {
			self::$encoder->content($spa->GetSyncKey());
		}
		self::$encoder->endTag();

		self::$encoder->startTag(SYNC_FOLDERID);
		self::$encoder->content($spa->GetFolderId());
		self::$encoder->endTag();

		self::$encoder->startTag(SYNC_STATUS);
		self::$encoder->content($status);
		self::$encoder->endTag();

		// announce failing status to the process loop detection
		if ($status !== SYNC_STATUS_SUCCESS) {
			self::$deviceManager->AnnounceProcessStatus($spa->GetFolderId(), $status);
		}

		// Output IDs and status for incoming items & requests
		if ($status == SYNC_STATUS_SUCCESS && (
			!empty($actiondata["clientids"]) ||
				!empty($actiondata["modifyids"]) ||
				!empty($actiondata["removeids"]) ||
				!empty($actiondata["fetchids"])
		)) {
			self::$encoder->startTag(SYNC_REPLIES);
			// output result of all new incoming items
			foreach ($actiondata["clientids"] as $clientid => $response) {
				self::$encoder->startTag(SYNC_ADD);
				self::$encoder->startTag(SYNC_CLIENTENTRYID);
				self::$encoder->content($clientid);
				self::$encoder->endTag();
				if (!empty($response->serverid)) {
					self::$encoder->startTag(SYNC_SERVERENTRYID);
					self::$encoder->content($response->serverid);
					self::$encoder->endTag();
				}
				self::$encoder->startTag(SYNC_STATUS);
				self::$encoder->content(isset($actiondata["statusids"][$clientid]) ? $actiondata["statusids"][$clientid] : SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR);
				self::$encoder->endTag();
				if (!empty($response->hasResponse)) {
					self::$encoder->startTag(SYNC_DATA);
					$response->Encode(self::$encoder);
					self::$encoder->endTag();
				}
				self::$encoder->endTag();
			}

			// loop through modify operations which were not a success, send status
			foreach ($actiondata["modifyids"] as $serverid => $response) {
				if (isset($actiondata["statusids"][$serverid]) && ($actiondata["statusids"][$serverid] !== SYNC_STATUS_SUCCESS || !empty($response->hasResponse))) {
					self::$encoder->startTag(SYNC_MODIFY);
					self::$encoder->startTag(SYNC_SERVERENTRYID);
					self::$encoder->content($serverid);
					self::$encoder->endTag();
					self::$encoder->startTag(SYNC_STATUS);
					self::$encoder->content($actiondata["statusids"][$serverid]);
					self::$encoder->endTag();
					if (!empty($response->hasResponse)) {
						self::$encoder->startTag(SYNC_DATA);
						$response->Encode(self::$encoder);
						self::$encoder->endTag();
					}
					self::$encoder->endTag();
				}
			}

			// loop through remove operations which were not a success, send status
			foreach ($actiondata["removeids"] as $serverid) {
				if (isset($actiondata["statusids"][$serverid]) && $actiondata["statusids"][$serverid] !== SYNC_STATUS_SUCCESS) {
					self::$encoder->startTag(SYNC_REMOVE);
					self::$encoder->startTag(SYNC_SERVERENTRYID);
					self::$encoder->content($serverid);
					self::$encoder->endTag();
					self::$encoder->startTag(SYNC_STATUS);
					self::$encoder->content($actiondata["statusids"][$serverid]);
					self::$encoder->endTag();
					self::$encoder->endTag();
				}
			}

			if (!empty($actiondata["fetchids"])) {
				self::$topCollector->AnnounceInformation(sprintf("Fetching %d objects ", count($actiondata["fetchids"])), $this->singleFolder);
				$this->saveMultiFolderInfo("fetching", count($actiondata["fetchids"]));
			}

			foreach ($actiondata["fetchids"] as $id) {
				$data = false;

				try {
					$fetchstatus = SYNC_STATUS_SUCCESS;

					// if this is an additional folder the backend has to be setup correctly
					if (!self::$backend->Setup(GSync::GetAdditionalSyncFolderStore($spa->GetBackendFolderId()))) {
						throw new StatusException(sprintf("HandleSync(): could not Setup() the backend to fetch in folder id %s/%s", $spa->GetFolderId(), $spa->GetBackendFolderId()), SYNC_STATUS_OBJECTNOTFOUND);
					}

					$data = self::$backend->Fetch($spa->GetBackendFolderId(), $id, $spa->GetCPO());

					// check if the message is broken
					if (GSync::GetDeviceManager(false) && GSync::GetDeviceManager()->DoNotStreamMessage($id, $data)) {
						SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): message not to be streamed as requested by DeviceManager, id = %s", $id));
						$fetchstatus = SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR;
					}
				}
				catch (StatusException $stex) {
					$fetchstatus = $stex->getCode();
				}

				self::$encoder->startTag(SYNC_FETCH);
				self::$encoder->startTag(SYNC_SERVERENTRYID);
				self::$encoder->content($id);
				self::$encoder->endTag();

				self::$encoder->startTag(SYNC_STATUS);
				self::$encoder->content($fetchstatus);
				self::$encoder->endTag();

				if ($data !== false && $status == SYNC_STATUS_SUCCESS) {
					self::$encoder->startTag(SYNC_DATA);
					$data->Encode(self::$encoder);
					self::$encoder->endTag();
				}
				else {
					SLog::Write(LOGLEVEL_WARN, sprintf("Unable to Fetch '%s'", $id));
				}
				self::$encoder->endTag();
			}
			self::$encoder->endTag();
		}

		if ($sc->GetParameter($spa, "getchanges") && $spa->HasFolderId() && $spa->HasContentClass() && $spa->HasSyncKey()) {
			$moreAvailableSent = false;
			$windowSize = self::$deviceManager->GetWindowSize($spa->GetFolderId(), $spa->GetUuid(), $spa->GetUuidCounter(), $changecount);

			// limit windowSize to the max available limit of the global window size left
			$globallyAvailable = $sc->GetGlobalWindowSize() - $this->globallyExportedItems;
			if ($changecount > $globallyAvailable && $windowSize > $globallyAvailable) {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Limit window size to %d as the global window size limit will be reached", $globallyAvailable));
				$windowSize = $globallyAvailable;
			}
			// send <MoreAvailable/> if there are more changes than fit in the folder windowsize
			// or there is a move state (another sync should be done afterwards)
			if ($changecount > $windowSize) {
				self::$encoder->startTag(SYNC_MOREAVAILABLE, false, true);
				$moreAvailableSent = true;
				$spa->DelFolderStat();
			}
		}

		// Stream outgoing changes
		if ($status == SYNC_STATUS_SUCCESS && $sc->GetParameter($spa, "getchanges") == true && $windowSize > 0 && (bool) $exporter) {
			self::$topCollector->AnnounceInformation(sprintf("Streaming data of %d objects", ($changecount > $windowSize) ? $windowSize : $changecount));

			// Output message changes per folder
			self::$encoder->startTag(SYNC_PERFORM);

			$n = 0;
			WBXMLDecoder::ResetInWhile("syncSynchronize");
			while (WBXMLDecoder::InWhile("syncSynchronize")) {
				try {
					$progress = $exporter->Synchronize();
					if (!is_array($progress)) {
						break;
					}
					++$n;
					if ($n % 10 == 0) {
						self::$topCollector->AnnounceInformation(sprintf("Streamed data of %d objects out of %d", $n, ($changecount > $windowSize) ? $windowSize : $changecount));
					}
				}
				catch (SyncObjectBrokenException $mbe) {
					$brokenSO = $mbe->GetSyncObject();
					if (!$brokenSO) {
						SLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): Caught SyncObjectBrokenException but broken SyncObject not available. This should be fixed in the backend."));
					}
					else {
						if (!isset($brokenSO->id)) {
							$brokenSO->id = "Unknown ID";
							SLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): Caught SyncObjectBrokenException but no ID of object set. This should be fixed in the backend."));
						}
						self::$deviceManager->AnnounceIgnoredMessage($spa->GetFolderId(), $brokenSO->id, $brokenSO);
					}
				}
				// something really bad happened while exporting changes
				catch (StatusException $stex) {
					$status = $stex->getCode();
					// during export we found out that the states should be thrown away
					if ($status == SYNC_STATUS_INVALIDSYNCKEY) {
						self::$deviceManager->ForceFolderResync($spa->GetFolderId());

						break;
					}
				}

				if ($n >= $windowSize || Request::IsRequestTimeoutReached() || Request::IsRequestMemoryLimitReached()) {
					SLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Exported maxItems of messages: %d / %d", $n, $changecount));

					break;
				}
			}

			// $progress is not an array when exporting the last message
			// so we get the number to display from the streamimporter if it's available
			if ((bool) $streamimporter) {
				$n = $streamimporter->GetImportedMessages();
			}

			self::$encoder->endTag();

			// log the request timeout
			if (Request::IsRequestTimeoutReached() || Request::IsRequestMemoryLimitReached()) {
				SLog::Write(LOGLEVEL_DEBUG, "HandleSync(): Stopping export as limits of request timeout or available memory are almost reached!");
				// Send a <MoreAvailable/> tag if we reached the request timeout or max memory, there are more changes and a moreavailable was not already send
				if (!$moreAvailableSent && ($n > $windowSize)) {
					self::$encoder->startTag(SYNC_MOREAVAILABLE, false, true);
					$spa->DelFolderStat();
					$moreAvailableSent = true;
				}
			}

			self::$topCollector->AnnounceInformation(sprintf("Outgoing %d objects%s", $n, ($n >= $windowSize) ? " of " . $changecount : ""), $this->singleFolder);
			$this->saveMultiFolderInfo("outgoing", $n);
			$this->saveMultiFolderInfo("queued", $changecount);

			$this->globallyExportedItems += $n;

			// update folder status, if there is something set
			if ($spa->GetFolderSyncRemaining() && $changecount > 0) {
				$spa->SetFolderSyncRemaining($changecount);
			}
			// changecount is initialized with 'false', so 0 means no changes!
			if ($changecount === 0 || ($changecount !== false && $changecount <= $windowSize)) {
				self::$deviceManager->SetFolderSyncStatus($spa->GetFolderId(), DeviceManager::FLD_SYNC_COMPLETED);

				// we should update the folderstat, but we recheck to see if it changed since the exporter setup. If so, it's not updated to force another sync
				if (self::$backend->HasFolderStats()) {
					$newFolderStatAfterExport = self::$backend->GetFolderStat(GSync::GetAdditionalSyncFolderStore($spa->GetBackendFolderId()), $spa->GetBackendFolderId());
					if ($newFolderStat === $newFolderStatAfterExport) {
						$this->setFolderStat($spa, $newFolderStat);
					}
					else {
						SLog::Write(LOGLEVEL_DEBUG, "Sync() Folderstat differs after export, force another exporter run.");
					}
				}
			}
			else {
				self::$deviceManager->SetFolderSyncStatus($spa->GetFolderId(), DeviceManager::FLD_SYNC_INPROGRESS);
			}
		}

		self::$encoder->endTag();

		// Save the sync state for the next time
		if ($spa->HasNewSyncKey()) {
			self::$topCollector->AnnounceInformation("Saving state");

			try {
				if ($exporter) {
					$state = $exporter->GetState();
				}

				// nothing exported, but possibly imported - get the importer state
				elseif ($sc->GetParameter($spa, "state") !== null) {
					$state = $sc->GetParameter($spa, "state");
				}

				// if a new request without state information (hierarchy) save an empty state
				elseif (!$spa->HasSyncKey()) {
					$state = "";
				}
			}
			catch (StatusException $stex) {
				$status = $stex->getCode();
			}

			if (isset($state) && $status == SYNC_STATUS_SUCCESS) {
				self::$deviceManager->GetStateManager()->SetSyncState($spa->GetNewSyncKey(), $state, $spa->GetFolderId());
			}
			else {
				SLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): error saving '%s' - no state information available", $spa->GetNewSyncKey()));
			}
		}

		// save SyncParameters
		if ($status == SYNC_STATUS_SUCCESS && empty($actiondata["fetchids"])) {
			$sc->SaveCollection($spa);
		}

		return $status;
	}

	/**
	 * Loads the states and writes them into the SyncCollection Object and the actiondata failstate.
	 *
	 * @param SyncCollection $sc           SyncCollection object
	 * @param SyncParameters $spa          SyncParameters object
	 * @param array          $actiondata   Actiondata array
	 * @param bool           $loadFailsafe (opt) default false - indicates if the failsafe states should be loaded
	 *
	 * @return status indicating if there were errors. If no errors, status is SYNC_STATUS_SUCCESS
	 */
	private function loadStates($sc, $spa, &$actiondata, $loadFailsafe = false) {
		$status = SYNC_STATUS_SUCCESS;

		if ($sc->GetParameter($spa, "state") == null) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("Sync->loadStates(): loading states for folder '%s'", $spa->GetFolderId()));

			try {
				$sc->AddParameter($spa, "state", self::$deviceManager->GetStateManager()->GetSyncState($spa->GetSyncKey()));

				if ($loadFailsafe) {
					// if this request was made before, there will be a failstate available
					$actiondata["failstate"] = self::$deviceManager->GetStateManager()->GetSyncFailState();
				}

				// if this is an additional folder the backend has to be setup correctly
				if (!self::$backend->Setup(GSync::GetAdditionalSyncFolderStore($spa->GetBackendFolderId()))) {
					throw new StatusException(sprintf("HandleSync() could not Setup() the backend for folder id %s/%s", $spa->GetFolderId(), $spa->GetBackendFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);
				}
			}
			catch (StateNotFoundException $snfex) {
				$status = SYNC_STATUS_INVALIDSYNCKEY;
				self::$topCollector->AnnounceInformation("StateNotFoundException", $this->singleFolder);
				$this->saveMultiFolderInfo("exception", "StateNotFoundException");
			}
			catch (StatusException $stex) {
				$status = $stex->getCode();
				self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), $this->singleFolder);
				$this->saveMultiFolderInfo("exception", "StateNotFoundException");
			}
		}

		return $status;
	}

	/**
	 * Initializes the importer for the SyncParameters folder, loads necessary
	 * states (incl. failsafe states) and initializes the conflict detection.
	 *
	 * @param SyncCollection $sc         SyncCollection object
	 * @param SyncParameters $spa        SyncParameters object
	 * @param array          $actiondata Actiondata array
	 *
	 * @return status indicating if there were errors. If no errors, status is SYNC_STATUS_SUCCESS
	 */
	private function getImporter($sc, $spa, &$actiondata) {
		SLog::Write(LOGLEVEL_DEBUG, "Sync->getImporter(): initialize importer");
		$status = SYNC_STATUS_SUCCESS;

		// load the states with failsafe data
		$status = $this->loadStates($sc, $spa, $actiondata, true);

		try {
			if ($status == SYNC_STATUS_SUCCESS) {
				// Configure importer with last state
				$this->importer = self::$backend->GetImporter($spa->GetBackendFolderId());

				// if something goes wrong, ask the mobile to resync the hierarchy
				if ($this->importer === false) {
					throw new StatusException(sprintf("Sync->getImporter(): no importer for folder id %s/%s", $spa->GetFolderId(), $spa->GetBackendFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);
				}

				// if there is a valid state obtained after importing changes in a previous loop, we use that state
				if (isset($actiondata["failstate"], $actiondata["failstate"]["failedsyncstate"])) {
					$this->importer->Config($actiondata["failstate"]["failedsyncstate"], $spa->GetConflict());
				}
				else {
					$this->importer->Config($sc->GetParameter($spa, "state"), $spa->GetConflict());
				}

				// the CPO is also needed by the importer to check if imported changes are inside the sync window
				$this->importer->ConfigContentParameters($spa->GetCPO());
				$this->importer->LoadConflicts($spa->GetCPO(), $sc->GetParameter($spa, "state"));
			}
		}
		catch (StatusException $stex) {
			$status = $stex->getCode();
		}

		return $status;
	}

	/**
	 * Imports a message.
	 *
	 * @param SyncParameters $spa          SyncParameters object
	 * @param array          $actiondata   Actiondata array
	 * @param int            $todo         WBXML flag indicating how message should be imported.
	 *                                     Valid values: SYNC_ADD, SYNC_MODIFY, SYNC_REMOVE
	 * @param SyncObject     $message      SyncObject message to be imported
	 * @param string         $clientid     Client message identifier
	 * @param string         $serverid     Server message identifier
	 * @param string         $foldertype   On sms sync, this says "SMS", else false
	 * @param int            $messageCount Counter of already imported messages
	 *
	 * @return - message related status are returned in the actiondata
	 *
	 * @throws StatusException in case the importer is not available
	 */
	private function importMessage($spa, &$actiondata, $todo, $message, $clientid, $serverid, $foldertype, $messageCount) {
		// the importer needs to be available!
		if ($this->importer == false) {
			throw new StatusException("Sync->importMessage(): importer not available", SYNC_STATUS_SERVERERROR);
		}

		// mark this state as used, e.g. for HeartBeat
		self::$deviceManager->SetHeartbeatStateIntegrity($spa->GetFolderId(), $spa->GetUuid(), $spa->GetUuidCounter());

		// Detect incoming loop
		// messages which were created/removed before will not have the same action executed again
		// if a message is edited we perform this action "again", as the message could have been changed on the mobile in the meantime
		$ignoreMessage = false;
		if ($actiondata["failstate"]) {
			// message was ADDED before, do NOT add it again
			if ($todo == SYNC_ADD && isset($actiondata["failstate"]["clientids"][$clientid])) {
				$ignoreMessage = true;

				// make sure no messages are sent back
				self::$deviceManager->SetWindowSize($spa->GetFolderId(), 0);

				$actiondata["clientids"][$clientid] = $actiondata["failstate"]["clientids"][$clientid];
				$actiondata["statusids"][$clientid] = $actiondata["failstate"]["statusids"][$clientid];

				SLog::Write(LOGLEVEL_INFO, sprintf("Mobile loop detected! Incoming new message '%s' was created on the server before. Replying with known new server id: %s", $clientid, $actiondata["clientids"][$clientid]));
			}

			// message was REMOVED before, do NOT attempt to remove it again
			if ($todo == SYNC_REMOVE && isset($actiondata["failstate"]["removeids"][$serverid])) {
				$ignoreMessage = true;

				// make sure no messages are sent back
				self::$deviceManager->SetWindowSize($spa->GetFolderId(), 0);

				$actiondata["removeids"][$serverid] = $actiondata["failstate"]["removeids"][$serverid];
				$actiondata["statusids"][$serverid] = $actiondata["failstate"]["statusids"][$serverid];

				SLog::Write(LOGLEVEL_INFO, sprintf("Mobile loop detected! Message '%s' was deleted by the mobile before. Replying with known status: %s", $clientid, $actiondata["statusids"][$serverid]));
			}
		}

		if (!$ignoreMessage) {
			switch ($todo) {
				case SYNC_MODIFY:
					self::$topCollector->AnnounceInformation(sprintf("Saving modified message %d", $messageCount));

					try {
						// ignore sms messages
						if ($foldertype == "SMS" || stripos($serverid, self::GSYNCIGNORESMS) !== false) {
							SLog::Write(LOGLEVEL_DEBUG, "SMS sync are not supported. Ignoring message.");
							// TODO we should update the SMS
							$actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
						}
						// check incoming message without logging WARN messages about errors
						elseif (!($message instanceof SyncObject) || !$message->Check(true)) {
							$actiondata["statusids"][$serverid] = SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR;
						}
						else {
							// if there is just a read flag change, import it via ImportMessageReadFlag()
							if (isset($message->read) && !isset($message->flag) && $message->getCheckedParameters() < 3) {
								$response = $this->importer->ImportMessageReadFlag($serverid, $message->read);
							}
							else {
								$response = $this->importer->ImportMessageChange($serverid, $message);
							}

							$response->serverid = $serverid;
							$actiondata["modifyids"][$serverid] = $response;
							$actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
						}
					}
					catch (StatusException $stex) {
						$actiondata["statusids"][$serverid] = $stex->getCode();
					}
					break;

				case SYNC_ADD:
					self::$topCollector->AnnounceInformation(sprintf("Creating new message from mobile %d", $messageCount));

					try {
						// mark the message as new message so SyncObject->Check() can differentiate
						$message->flags = SYNC_NEWMESSAGE;

						// ignore sms messages
						if ($foldertype == "SMS") {
							SLog::Write(LOGLEVEL_DEBUG, "SMS sync are not supported. Ignoring message.");
							// TODO we should create the SMS
							// return a fake serverid which we can identify later
							$actiondata["clientids"][$clientid] = self::GSYNCIGNORESMS . $clientid;
							$actiondata["statusids"][$clientid] = SYNC_STATUS_SUCCESS;
						}
						// check incoming message without logging WARN messages about errors
						elseif (!($message instanceof SyncObject) || !$message->Check(true)) {
							$actiondata["clientids"][$clientid] = false;
							$actiondata["statusids"][$clientid] = SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR;
						}
						else {
							$actiondata["clientids"][$clientid] = false;
							$actiondata["clientids"][$clientid] = $this->importer->ImportMessageChange(false, $message);
							$actiondata["statusids"][$clientid] = SYNC_STATUS_SUCCESS;
						}
					}
					catch (StatusException $stex) {
						$actiondata["statusids"][$clientid] = $stex->getCode();
					}
					break;

				case SYNC_REMOVE:
					self::$topCollector->AnnounceInformation(sprintf("Deleting message removed on mobile %d", $messageCount));

					try {
						$actiondata["removeids"][] = $serverid;
						// ignore sms messages
						if ($foldertype == "SMS" || stripos($serverid, self::GSYNCIGNORESMS) !== false) {
							SLog::Write(LOGLEVEL_DEBUG, "SMS sync are not supported. Ignoring message.");
							// TODO we should delete the SMS
							$actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
						}
						else {
							// if message deletions are to be moved, move them
							if ($spa->GetDeletesAsMoves()) {
								$folderid = self::$backend->GetWasteBasket();

								if ($folderid) {
									$actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
									$this->importer->ImportMessageMove($serverid, $folderid);

									break;
								}

								SLog::Write(LOGLEVEL_WARN, "Message should be moved to WasteBasket, but the Backend did not return a destination ID. Message is hard deleted now!");
							}

							$this->importer->ImportMessageDeletion($serverid);
							$actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
						}
					}
					catch (StatusException $stex) {
						if ($stex->getCode() != SYNC_MOVEITEMSSTATUS_SUCCESS) {
							$actiondata["statusids"][$serverid] = SYNC_STATUS_OBJECTNOTFOUND;
						}
					}
					break;
			}
			SLog::Write(LOGLEVEL_DEBUG, "Sync->importMessage(): message imported");
		}
	}

	/**
	 * Keeps some interesting information about the sync process of several folders.
	 *
	 * @param mixed $key
	 * @param mixed $value
	 */
	private function saveMultiFolderInfo($key, $value) {
		if ($key == "incoming" || $key == "outgoing" || $key == "queued" || $key == "fetching") {
			if (!isset($this->multiFolderInfo[$key])) {
				$this->multiFolderInfo[$key] = 0;
			}
			$this->multiFolderInfo[$key] += $value;
		}
		if ($key == "exception") {
			if (!isset($this->multiFolderInfo[$key])) {
				$this->multiFolderInfo[$key] = [];
			}
			$this->multiFolderInfo[$key][] = $value;
		}
	}

	/**
	 * Returns a single string with information about the multi folder synchronization.
	 *
	 * @param int $amountOfFolders
	 *
	 * @return string
	 */
	private function getMultiFolderInfoLine($amountOfFolders) {
		$s = $amountOfFolders . " folders";
		if (isset($this->multiFolderInfo["incoming"])) {
			$s .= ": " . $this->multiFolderInfo["incoming"] . " saved";
		}
		if (isset($this->multiFolderInfo["outgoing"], $this->multiFolderInfo["queued"]) && $this->multiFolderInfo["outgoing"] > 0) {
			$s .= sprintf(": Streamed %d out of %d", $this->multiFolderInfo["outgoing"], $this->multiFolderInfo["queued"]);
		}
		elseif (!isset($this->multiFolderInfo["outgoing"]) && !isset($this->multiFolderInfo["queued"])) {
			$s .= ": no changes";
		}
		else {
			if (isset($this->multiFolderInfo["outgoing"])) {
				$s .= "/" . $this->multiFolderInfo["outgoing"] . " streamed";
			}
			if (isset($this->multiFolderInfo["queued"])) {
				$s .= "/" . $this->multiFolderInfo["queued"] . " queued";
			}
		}
		if (isset($this->multiFolderInfo["exception"])) {
			$exceptions = array_count_values($this->multiFolderInfo["exception"]);
			foreach ($exceptions as $name => $count) {
				$s .= sprintf("-%s(%d)", $name, $count);
			}
		}

		return $s;
	}

	/**
	 * Sets the new folderstat and calculates & sets an expiration date for the folder stat.
	 *
	 * @param SyncParameters $spa
	 * @param string         $newFolderStat
	 */
	private function setFolderStat($spa, $newFolderStat) {
		$spa->SetFolderStat($newFolderStat);
		$maxTimeout = 60 * 60 * 24 * 31; // one month

		$interval = Utils::GetFiltertypeInterval($spa->GetFilterType());
		$timeout = time() + (($interval && $interval < $maxTimeout) ? $interval : $maxTimeout);
		// randomize timeout in 12h
		$timeout -= rand(0, 43200);
		SLog::Write(LOGLEVEL_DEBUG, sprintf("Sync()->setFolderStat() on %s: %s expiring %s", $spa->getFolderId(), $newFolderStat, date('Y-m-d H:i:s', $timeout)));
		$spa->SetFolderStatTimeout($timeout);
	}
}
