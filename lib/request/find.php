<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2022-2024 grommunio GmbH
 *
 * Provides the FIND command
 */

class Find extends RequestProcessor {
	/**
	 * Handles the Find command.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public function Handle($commandCode) {
		$cpo = new ContentParameters();
		if (!self::$decoder->getElementStartTag(SYNC_FIND_FIND)) {
			return false;
		}

		if (!self::$decoder->getElementStartTag(SYNC_FIND_SEARCHID)) {
			return false;
		}
		$searchId = self::$decoder->getElementContent();
		$cpo->SetFindSearchId($searchId);
		if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_SEARCHID
			return false;
		}

		if (!self::$decoder->getElementStartTag(SYNC_FIND_EXECUTESEARCH)) {
			return false;
		}

		if (self::$decoder->getElementStartTag(SYNC_FIND_MAILBOXSEARCHCRITERION)) {
			$searchname = ISearchProvider::SEARCH_MAILBOX;
			if (!self::$decoder->getElementStartTag(SYNC_FIND_QUERY)) {
				return false;
			}

			if (self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
				$folderType = self::$decoder->getElementContent();
				$cpo->SetFindFolderType($folderType);
				if (!self::$decoder->getElementEndTag()) { // SYNC_FOLDERTYPE
					return false;
				}
			}

			if (self::$decoder->getElementStartTag(SYNC_FOLDERID)) {
				$folderId = self::$decoder->getElementContent();
				$cpo->SetFindFolderId($folderId);
				$cpo->SetRawFindFolderId($folderId);
				if (!self::$decoder->getElementEndTag()) { // SYNC_FOLDERID
					return false;
				}
			}

			if (self::$decoder->getElementStartTag(SYNC_FIND_FREETEXT)) {
				$freeText = self::$decoder->getElementContent();
				$cpo->SetFindFreeText($freeText);
				if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_FREETEXT
					return false;
				}
			}

			if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_QUERY
				return false;
			}

			$deeptraversal = false;
			if (self::$decoder->getElementStartTag(SYNC_FIND_OPTIONS)) {
				WBXMLDecoder::ResetInWhile("findOptions");
				while (WBXMLDecoder::InWhile("findOptions")) {
					if (self::$decoder->getElementStartTag(SYNC_FIND_RANGE)) {
						$findrange = self::$decoder->getElementContent();
						$cpo->SetFindRange($findrange);
						if (!self::$decoder->getElementEndTag()) {
							return false;
						}
					}

					if (self::$decoder->getElementStartTag(SYNC_FIND_DEEPTRAVERSAL)) {
						$deeptraversal = true;
						if (($dam = self::$decoder->getElementContent()) !== false) {
							$deeptraversal = true;
							if (!self::$decoder->getElementEndTag()) {
								return false;
							}
						}
					}
					$e = self::$decoder->peek();
					if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
						self::$decoder->getElementEndTag();

						break;
					}
				}
			}
			$cpo->SetFindDeepTraversal($deeptraversal);

			if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_MAILBOXSEARCHCRITERION
				return false;
			}
		}

		if (self::$decoder->getElementStartTag(SYNC_FIND_GALSEARCHCRITERION)) {
			$searchname = ISearchProvider::SEARCH_GAL;
			$galSearchCriterion = self::$decoder->getElementContent();
			if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_GALSEARCHCRITERION
				return false;
			}
		}

		if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_EXECUTESEARCH
			return false;
		}

		if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_FIND
			return false;
		}

		// get SearchProvider
		$searchprovider = GSync::GetBackend()->GetSearchProvider();
		$findstatus = SYNC_FINDSTATUS_SUCCESS;

		if (!isset($searchname)) {
			$findstatus = SYNC_FINDSTATUS_INVALIDREQUEST;
		}

		self::$encoder->startWBXML();
		self::$encoder->startTag(SYNC_FIND_FIND);

		self::$encoder->startTag(SYNC_FIND_STATUS);
		self::$encoder->content($findstatus);
		self::$encoder->endTag();

		if ($findstatus == SYNC_FINDSTATUS_SUCCESS) {
			$status = SYNC_FINDSTATUS_SUCCESS;

			try {
				if ($searchname == ISearchProvider::SEARCH_GAL) {
					// TODO: Fix GAL search in Find command
					// $rows = $searchprovider->GetGALSearchResults($cpo->GetFindFreeText(), "0-100", null);
					throw new StatusException("GAL search in FIND command is not implemented. Please report this including the 'WBXML debug data' logged. Be aware that the debug data could contain confidential information.", SYNC_FINDSTATUS_INVALIDREQUEST, null, LOGLEVEL_FATAL);
				}
				if ($searchname == ISearchProvider::SEARCH_MAILBOX) {
					$backendFolderId = self::$deviceManager->GetBackendIdForFolderId($cpo->GetFindFolderid());
					$cpo->SetFindFolderid($backendFolderId);
					$rows = $searchprovider->GetMailboxSearchResults($cpo);
				}
			}
			catch (StatusException $stex) {
				$status = $stex->getCode();
			}

			self::$encoder->startTag(SYNC_FIND_RESPONSE);

			self::$encoder->startTag(SYNC_ITEMOPERATIONS_STORE);
			self::$encoder->content("Mailbox");
			self::$encoder->endTag();

			self::$encoder->startTag(SYNC_FIND_STATUS);
			self::$encoder->content($status);
			self::$encoder->endTag();

			if (isset($rows['range'])) {
				$searchrange = $rows['range'];
				unset($rows['range']);
			}
			if (isset($rows['searchtotal'])) {
				$searchtotal = $rows['searchtotal'];
				unset($rows['searchtotal']);
			}

			if ($searchtotal > 0) {
				foreach ($rows as $u) {
					// fetch the SyncObject for this result
					$message = self::$backend->Fetch(false, $u['longid'], $cpo);
					$mfolderid = self::$deviceManager->GetFolderIdForBackendId(bin2hex($message->ParentSourceKey));

					self::$encoder->startTag(SYNC_FIND_RESULT);
					self::$encoder->startTag(SYNC_FOLDERTYPE);
					self::$encoder->content($u['class']);
					self::$encoder->endTag();

					self::$encoder->startTag(SYNC_SERVERENTRYID);
					self::$encoder->content($mfolderid . ":" . $u['serverid']);
					self::$encoder->endTag();
					self::$encoder->startTag(SYNC_FOLDERID);
					self::$encoder->content($cpo->GetRawFindFolderId());
					self::$encoder->endTag();

					self::$encoder->startTag(SYNC_FIND_PROPERTIES);
					$fpmessage = SyncFindProperties::GetObjectFromSyncMail($message);
					$fpmessage->Encode(self::$encoder);

					self::$encoder->endTag(); // properties
					self::$encoder->endTag(); // result
				}
			}

			self::$encoder->startTag(SYNC_FIND_RANGE);
			self::$encoder->content("0-" . $searchtotal);
			self::$encoder->endTag();
			if ($searchtotal > 0) {
				self::$encoder->startTag(SYNC_FIND_TOTAL);
				self::$encoder->content($searchtotal); // $searchtotal);
				self::$encoder->endTag();
			}

			self::$encoder->endTag(); // SYNC_FIND_RESPONSE
		}
		self::$encoder->endTag(); // SYNC_FIND_FIND

		return true;
	}
}
