<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Provides the ItemOperations command
 */

class ItemOperations extends RequestProcessor {
	/**
	 * Handles the ItemOperations command
	 * Provides batched online handling for Fetch, EmptyFolderContents and Move.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public function Handle($commandCode) {
		// Parse input
		if (!self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_ITEMOPERATIONS)) {
			return false;
		}

		$itemoperations = [];
		// ItemOperations can either be Fetch, EmptyFolderContents or Move
		WBXMLDecoder::ResetInWhile("itemOperationsActions");
		while (WBXMLDecoder::InWhile("itemOperationsActions")) {
			$el = self::$decoder->getElement();

			if ($el[EN_TYPE] != EN_TYPE_STARTTAG) {
				return false;
			}

			$fetch = $efc = $move = false;
			$operation = [];
			if ($el[EN_TAG] == SYNC_ITEMOPERATIONS_FETCH) {
				$fetch = true;
				$operation['operation'] = SYNC_ITEMOPERATIONS_FETCH;
				self::$topCollector->AnnounceInformation("Fetch", true);
			}
			elseif ($el[EN_TAG] == SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENTS) {
				$efc = true;
				$operation['operation'] = SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENTS;
				self::$topCollector->AnnounceInformation("Empty Folder", true);
			}
			elseif ($el[EN_TAG] == SYNC_ITEMOPERATIONS_MOVE) {
				$move = true;
				$operation['operation'] = SYNC_ITEMOPERATIONS_MOVE;
				self::$topCollector->AnnounceInformation("Move", true);
			}

			if (!$fetch && !$efc && !$move) {
				SLog::Write(LOGLEVEL_DEBUG, "Unknown item operation:" . print_r($el, 1));
				self::$topCollector->AnnounceInformation("Unknown operation", true);

				return false;
			}

			// process operation
			WBXMLDecoder::ResetInWhile("itemOperationsOperation");
			while (WBXMLDecoder::InWhile("itemOperationsOperation")) {
				if ($fetch) {
					// Save all OPTIONS into a ContentParameters object
					$operation["cpo"] = new ContentParameters();

					if (self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_STORE)) {
						$operation['store'] = self::$decoder->getElementContent();
						if (!self::$decoder->getElementEndTag()) {
							return false;
						}// SYNC_ITEMOPERATIONS_STORE
					}

					if (self::$decoder->getElementStartTag(SYNC_SEARCH_LONGID)) {
						$operation['longid'] = self::$decoder->getElementContent();
						if (!self::$decoder->getElementEndTag()) {
							return false;
						}// SYNC_SEARCH_LONGID
					}

					if (self::$decoder->getElementStartTag(SYNC_FOLDERID)) {
						$operation['folderid'] = self::$decoder->getElementContent();
						if (!self::$decoder->getElementEndTag()) {
							return false;
						}// SYNC_FOLDERID
					}

					if (self::$decoder->getElementStartTag(SYNC_SERVERENTRYID)) {
						$operation['serverid'] = self::$decoder->getElementContent();
						if (!self::$decoder->getElementEndTag()) {
							return false;
						}// SYNC_SERVERENTRYID
					}

					if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_FILEREFERENCE)) {
						$operation['filereference'] = self::$decoder->getElementContent();
						if (!self::$decoder->getElementEndTag()) {
							return false;
						}// SYNC_AIRSYNCBASE_FILEREFERENCE
					}

					if (($el = self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_OPTIONS)) && ($el[EN_FLAGS] & EN_FLAGS_CONTENT)) {
						// UserName and Password are only used for document libraries, which are not supported

						WBXMLDecoder::ResetInWhile("itemOperationsOptions");
						while (WBXMLDecoder::InWhile("itemOperationsOptions")) {
							while (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)) {
								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
									$bptype = self::$decoder->getElementContent();
									$operation["cpo"]->BodyPreference($bptype);
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
									$operation["cpo"]->BodyPreference($bptype)->SetTruncationSize(self::$decoder->getElementContent());
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
									$operation["cpo"]->BodyPreference($bptype)->SetAllOrNone(self::$decoder->getElementContent());
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_PREVIEW)) {
									$operation["cpo"]->BodyPreference($bptype)->SetPreview(self::$decoder->getElementContent());
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (!self::$decoder->getElementEndTag()) {
									return false;
								}// SYNC_AIRSYNCBASE_BODYPREFERENCE
							}

							if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPARTPREFERENCE)) {
								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
									$bpptype = self::$decoder->getElementContent();
									$operation["cpo"]->BodyPartPreference($bpptype);
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
									$operation["cpo"]->BodyPartPreference($bpptype)->SetTruncationSize(self::$decoder->getElementContent());
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
									$operation["cpo"]->BodyPartPreference($bpptype)->SetAllOrNone(self::$decoder->getElementContent());
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_PREVIEW)) {
									$operation["cpo"]->BodyPartPreference($bpptype)->SetPreview(self::$decoder->getElementContent());
									if (!self::$decoder->getElementEndTag()) {
										return false;
									}
								}

								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}

							if (self::$decoder->getElementStartTag(SYNC_MIMESUPPORT)) {
								$operation["cpo"]->SetMimeSupport(self::$decoder->getElementContent());
								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}

							if (self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_RANGE)) {
								$operation["range"] = self::$decoder->getElementContent();
								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}

							if (self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_SCHEMA)) {
								// the schema is skipped, the complete item is returned
								WBXMLDecoder::ResetInWhile("itemOperationsSchema");
								while (WBXMLDecoder::InWhile("itemOperationsSchema")) {
									$el = self::$decoder->getElement();
									$e = self::$decoder->peek();
									if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
										self::$decoder->getElementEndTag();

										break;
									}
								}
							}

							if (self::$decoder->getElementStartTag(SYNC_RIGHTSMANAGEMENT_SUPPORT)) {
								$operation["cpo"]->SetRmSupport(self::$decoder->getElementContent());
								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}

							// break if it reached the endtag
							$e = self::$decoder->peek();
							if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
								self::$decoder->getElementEndTag();

								break;
							}
						}
					}

					if (self::$decoder->getElementStartTag(SYNC_RIGHTSMANAGEMENT_REMOVERIGHTSMGNTPROTECTION)) {
						$operation["cpo"]->SetRemoveRmProtection(true);
						if (($rrmp = self::$decoder->getElementContent()) !== false) {
							$operation["cpo"]->SetRemoveRmProtection($rrmp);
							if (!self::$decoder->getElementEndTag()) {
								return false;
							}
						}
					}
				} // end if fetch

				if ($efc) {
					if (self::$decoder->getElementStartTag(SYNC_FOLDERID)) {
						$operation['folderid'] = self::$decoder->getElementContent();
						if (!self::$decoder->getElementEndTag()) {
							return false;
						}// SYNC_FOLDERID
					}
					if (self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_OPTIONS)) {
						if (self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_DELETESUBFOLDERS)) {
							$operation['deletesubfolders'] = true;
							if (($dsf = self::$decoder->getElementContent()) !== false) {
								$operation['deletesubfolders'] = (bool) $dsf;
								if (!self::$decoder->getElementEndTag()) {
									return false;
								}
							}
						}
						self::$decoder->getElementEndTag();
					}
				}

				if ($move) {
					if (self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_CONVERSATIONID)) {
						$operation['conversationid'] = self::$decoder->getElementContent();
						if (!self::$decoder->getElementEndTag()) {
							return false;
						}// SYNC_ITEMOPERATIONS_CONVERSATIONID
					}

					if (self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_DSTFLDID)) {
						$operation['dstfldid'] = self::$decoder->getElementContent();
						if (!self::$decoder->getElementEndTag()) {
							return false;
						}// SYNC_ITEMOPERATIONS_DSTFLDID
					}

					if (($el = self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_OPTIONS)) && ($el[EN_FLAGS] & EN_FLAGS_CONTENT)) {
						if ($el = self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_MOVEALWAYS)) {
							$operation['movealways'] = true;
							if (($el[EN_FLAGS] & EN_FLAGS_CONTENT) && !self::$decoder->getElementEndTag()) {
								return false;
							}// SYNC_ITEMOPERATIONS_MOVEALWAYS
						}
						if (!self::$decoder->getElementEndTag()) {
							return false;
						}// SYNC_ITEMOPERATIONS_OPTIONS
					}
				}

				// break if it reached the endtag SYNC_ITEMOPERATIONS_FETCH or SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENTS or SYNC_ITEMOPERATIONS_MOVE
				$e = self::$decoder->peek();
				if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
					self::$decoder->getElementEndTag();

					break;
				}
			} // end while operation

			// rewrite folderid into backendfolderid to be used on backend operations below
			if (isset($operation['folderid'])) {
				$operation['backendfolderid'] = self::$deviceManager->GetBackendIdForFolderId($operation['folderid']);
			}

			$itemoperations[] = $operation;
			// break if it reached the endtag
			$e = self::$decoder->peek();
			if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
				self::$decoder->getElementEndTag(); // SYNC_ITEMOPERATIONS_ITEMOPERATIONS

				break;
			}
		} // end operations loop

		$status = SYNC_ITEMOPERATIONSSTATUS_SUCCESS;

		self::$encoder->startWBXML();

		self::$encoder->startTag(SYNC_ITEMOPERATIONS_ITEMOPERATIONS);

		self::$encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
		self::$encoder->content($status);
		self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_STATUS

		// Stop here if something went wrong
		if ($status != SYNC_ITEMOPERATIONSSTATUS_SUCCESS) {
			self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_ITEMOPERATIONS

			return true;
		}

		self::$encoder->startTag(SYNC_ITEMOPERATIONS_RESPONSE);

		foreach ($itemoperations as $operation) {
			// fetch response
			if ($operation['operation'] == SYNC_ITEMOPERATIONS_FETCH) {
				$status = SYNC_ITEMOPERATIONSSTATUS_SUCCESS;

				// retrieve the data
				// Fetch throws Sync status codes, - GetAttachmentData ItemOperations codes
				if (isset($operation['filereference'])) {
					try {
						self::$topCollector->AnnounceInformation("Get attachment data from backend with file reference");
						$data = self::$backend->GetAttachmentData($operation['filereference']);
					}
					catch (StatusException $stex) {
						$status = $stex->getCode();
					}
				}
				else {
					try {
						if (isset($operation['folderid'], $operation['serverid'])) {
							self::$topCollector->AnnounceInformation("Fetching data from backend with item and folder id");
							$data = self::$backend->Fetch($operation['backendfolderid'], $operation['serverid'], $operation["cpo"]);
						}
						elseif (isset($operation['longid'])) {
							self::$topCollector->AnnounceInformation("Fetching data from backend with long id");
							$tmp = explode(":", $operation['longid']);
							$data = self::$backend->Fetch(self::$deviceManager->GetBackendIdForFolderId($tmp[0]), $tmp[1], $operation["cpo"]);
						}
					}
					catch (StatusException) {
						// the only option to return is that we could not retrieve it
						$status = SYNC_ITEMOPERATIONSSTATUS_CONVERSIONFAILED;
					}
				}

				self::$encoder->startTag(SYNC_ITEMOPERATIONS_FETCH);

				self::$encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
				self::$encoder->content($status);
				self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_STATUS

				if (isset($operation['folderid'], $operation['serverid'])) {
					self::$encoder->startTag(SYNC_FOLDERID);
					self::$encoder->content($operation['folderid']);
					self::$encoder->endTag(); // end SYNC_FOLDERID

					self::$encoder->startTag(SYNC_SERVERENTRYID);
					self::$encoder->content($operation['serverid']);
					self::$encoder->endTag(); // end SYNC_SERVERENTRYID

					self::$encoder->startTag(SYNC_FOLDERTYPE);
					self::$encoder->content("Email");
					self::$encoder->endTag();
				}

				if (isset($operation['longid'])) {
					self::$encoder->startTag(SYNC_SEARCH_LONGID);
					self::$encoder->content($operation['longid']);
					self::$encoder->endTag(); // end SYNC_FOLDERID

					self::$encoder->startTag(SYNC_FOLDERTYPE);
					self::$encoder->content("Email");
					self::$encoder->endTag();
				}

				if (isset($operation['filereference'])) {
					self::$encoder->startTag(SYNC_AIRSYNCBASE_FILEREFERENCE);
					self::$encoder->content($operation['filereference']);
					self::$encoder->endTag(); // end SYNC_AIRSYNCBASE_FILEREFERENCE
				}

				if (isset($data)) {
					self::$topCollector->AnnounceInformation("Streaming data");

					self::$encoder->startTag(SYNC_ITEMOPERATIONS_PROPERTIES);
					if (isset($operation['range'])) {
						self::$encoder->startTag(SYNC_ITEMOPERATIONS_RANGE);
						self::$encoder->content($operation['range']);
						self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_RANGE
					}
					$data->Encode(self::$encoder);
					self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_PROPERTIES
				}

				self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_FETCH
			}
			// empty folder contents operation
			elseif ($operation['operation'] == SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENTS) {
				try {
					self::$topCollector->AnnounceInformation("Emptying folder");

					// send request to backend
					self::$backend->EmptyFolder($operation['backendfolderid'], $operation['deletesubfolders']);
				}
				catch (StatusException $stex) {
					$status = $stex->getCode();
				}

				self::$encoder->startTag(SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENTS);

				self::$encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
				self::$encoder->content($status);
				self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_STATUS

				if (isset($operation['folderid'])) {
					self::$encoder->startTag(SYNC_FOLDERID);
					self::$encoder->content($operation['folderid']);
					self::$encoder->endTag(); // end SYNC_FOLDERID
				}
				self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENTS
			}
			// move conversation operation
			else {
				$status = SYNC_ITEMOPERATIONSSTATUS_SUCCESS;
				if (empty($operation['conversationid']) || empty($operation['dstfldid'])) {
					$status = SYNC_ITEMOPERATIONSSTATUS_PROTERROR;
				}
				elseif (!isset($operation['movealways'])) {
					$status = SYNC_ITEMOPERATIONSSTATUS_PROTOCOLERROR;
				}
				else {
					try {
						$dstclass = self::$deviceManager->GetFolderClassFromCacheByID($operation['dstfldid']);
					}
					catch (Exception) {
						$dstclass = false;
					}
					if ($dstclass !== "Email") {
						$status = SYNC_ITEMOPERATIONSSTATUS_UNSUPPORTEDACTION;
					}
				}

				if ($status == SYNC_ITEMOPERATIONSSTATUS_SUCCESS) {
					try {
						self::$topCollector->AnnounceInformation("Moving conversation");
						// MoveAlways is accepted, but only the messages of the conversation which exist now are moved
						self::$backend->MoveConversation($operation['conversationid'], self::$deviceManager->GetBackendIdForFolderId($operation['dstfldid']));
					}
					catch (StatusException $stex) {
						$status = $stex->getCode();
					}
				}

				self::$encoder->startTag(SYNC_ITEMOPERATIONS_MOVE);
				self::$encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
				self::$encoder->content($status);
				self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_STATUS

				if (!empty($operation['conversationid'])) {
					self::$encoder->startTag(SYNC_ITEMOPERATIONS_CONVERSATIONID);
					self::$encoder->contentStream(StringStreamWrapper::Open($operation['conversationid']), false, true);
					self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_CONVERSATIONID
				}
				self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_MOVE
			}
		}
		self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_RESPONSE
		self::$encoder->endTag(); // SYNC_ITEMOPERATIONS_ITEMOPERATIONS

		return true;
	}
}
