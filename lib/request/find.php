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
			if (($el = self::$decoder->getElementStartTag(SYNC_FIND_OPTIONS)) && ($el[EN_FLAGS] & EN_FLAGS_CONTENT)) {
				WBXMLDecoder::ResetInWhile("findOptions");
				while (WBXMLDecoder::InWhile("findOptions")) {
					if (self::$decoder->getElementStartTag(SYNC_FIND_RANGE)) {
						$findrange = self::$decoder->getElementContent();
						$cpo->SetFindRange($findrange);
						if (!self::$decoder->getElementEndTag()) {
							return false;
						}
					}

					if (($el = self::$decoder->getElementStartTag(SYNC_FIND_DEEPTRAVERSAL)) !== false) {
						$deeptraversal = true;
						if ($el[EN_FLAGS] & EN_FLAGS_CONTENT) {
							self::$decoder->getElementContent();
							if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_DEEPTRAVERSAL
								return false;
							}
						}
					}

					// pictures are only returned for GAL entries, the options are ignored for mailbox searches
					if (($el = self::$decoder->getElementStartTag(SYNC_FIND_PICTURE)) !== false && ($el[EN_FLAGS] & EN_FLAGS_CONTENT)) {
						if (self::$decoder->getElementStartTag(SYNC_FIND_MAXSIZE)) {
							self::$decoder->getElementContent();
							if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_MAXSIZE
								return false;
							}
						}
						if (self::$decoder->getElementStartTag(SYNC_FIND_MAXPICTURES)) {
							self::$decoder->getElementContent();
							if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_MAXPICTURES
								return false;
							}
						}
						if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_PICTURE
							return false;
						}
					}

					$e = self::$decoder->peek();
					if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
						self::$decoder->getElementEndTag(); // SYNC_FIND_OPTIONS

						break;
					}
					if (!in_array($e[EN_TAG] ?? false, [SYNC_FIND_RANGE, SYNC_FIND_DEEPTRAVERSAL, SYNC_FIND_PICTURE])) {
						SLog::Write(LOGLEVEL_WARN, sprintf("Find(): unexpected element '%s' in the options", $e[EN_TAG] ?? ''));

						return false;
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
			$galquery = "";
			$galrange = "0-99";
			$galpicture = false;

			if (!($el = self::$decoder->getElementStartTag(SYNC_FIND_QUERY))) {
				return false;
			}
			if ($el[EN_FLAGS] & EN_FLAGS_CONTENT) {
				// the query is a string, but accept it wrapped in FreeText like a mailbox query
				if (self::$decoder->getElementStartTag(SYNC_FIND_FREETEXT)) {
					$galquery = self::$decoder->getElementContent();
					if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_FREETEXT
						return false;
					}
				}
				else {
					$galquery = self::$decoder->getElementContent();
				}
				if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_QUERY
					return false;
				}
			}

			if (($el = self::$decoder->getElementStartTag(SYNC_FIND_OPTIONS)) && ($el[EN_FLAGS] & EN_FLAGS_CONTENT)) {
				WBXMLDecoder::ResetInWhile("findGalOptions");
				while (WBXMLDecoder::InWhile("findGalOptions")) {
					if (self::$decoder->getElementStartTag(SYNC_FIND_RANGE)) {
						$galrange = self::$decoder->getElementContent();
						if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_RANGE
							return false;
						}
					}

					if (($el = self::$decoder->getElementStartTag(SYNC_FIND_PICTURE)) !== false) {
						$galpicture = new SyncResolveRecipientsPicture();
						if ($el[EN_FLAGS] & EN_FLAGS_CONTENT) {
							if (self::$decoder->getElementStartTag(SYNC_FIND_MAXSIZE)) {
								$galpicture->maxsize = self::$decoder->getElementContent();
								if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_MAXSIZE
									return false;
								}
							}

							if (self::$decoder->getElementStartTag(SYNC_FIND_MAXPICTURES)) {
								$galpicture->maxpictures = self::$decoder->getElementContent();
								if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_MAXPICTURES
									return false;
								}
							}

							if (!self::$decoder->getElementEndTag()) { // SYNC_FIND_PICTURE
								return false;
							}
						}
					}

					$e = self::$decoder->peek();
					if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
						self::$decoder->getElementEndTag(); // SYNC_FIND_OPTIONS

						break;
					}
					if (!in_array($e[EN_TAG] ?? false, [SYNC_FIND_RANGE, SYNC_FIND_PICTURE])) {
						SLog::Write(LOGLEVEL_WARN, sprintf("Find(): unexpected element '%s' in the GAL options", $e[EN_TAG] ?? ''));

						return false;
					}
				}
			}

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
			$rows = [];
			$searchtotal = 0;

			try {
				if ($searchname == ISearchProvider::SEARCH_GAL) {
					if (!str_starts_with((string) $galrange, "0-")) {
						throw new StatusException(sprintf("Find(): GAL search range '%s' does not start with 0", $galrange), SYNC_FINDSTATUS_STARTRANGEZER0);
					}
					if (strlen((string) $galquery) == 0) {
						throw new StatusException("Find(): empty GAL search query", SYNC_FINDSTATUS_INVALIDREQUEST);
					}

					try {
						$rows = $searchprovider->GetGALSearchResults($galquery, $galrange, $galpicture);
					}
					catch (StatusException $stex) {
						// the search provider uses Search status codes, Find has no server error status
						throw new StatusException($stex->getMessage(), SYNC_FINDSTATUS_INVALIDREQUEST);
					}
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

			if (isset($rows['searchtotal'])) {
				$searchtotal = $rows['searchtotal'];
			}
			unset($rows['range'], $rows['searchtotal']);

			// the range of the results starts at the requested index
			$requestedrange = $searchname == ISearchProvider::SEARCH_GAL ? $galrange : $cpo->GetFindRange();
			$rangestart = (int) explode("-", (string) $requestedrange)[0];
			$results = 0;

			if ($status == SYNC_FINDSTATUS_SUCCESS && $searchname == ISearchProvider::SEARCH_GAL) {
				// GAL entries have no class, server or collection id
				foreach ($rows as $u) {
					self::$encoder->startTag(SYNC_FIND_RESULT);
					self::$encoder->startTag(SYNC_FIND_PROPERTIES);
					self::encodeGALProperties($u);
					self::$encoder->endTag(); // SYNC_FIND_PROPERTIES
					self::$encoder->endTag(); // SYNC_FIND_RESULT
					++$results;
				}
			}
			elseif ($status == SYNC_FINDSTATUS_SUCCESS) {
				foreach ($rows as $u) {
					// fetch the SyncObject for this result, it might have been deleted in the meantime
					try {
						$message = self::$backend->Fetch(false, $u['longid'], $cpo);
					}
					catch (StatusException) {
						SLog::Write(LOGLEVEL_DEBUG, sprintf("Find(): ignoring result '%s' which could not be fetched", $u['longid']));

						continue;
					}
					// the result is in the folder of the item, which differs from the searched one on a deep traversal
					$mfolderid = self::$deviceManager->GetFolderIdForBackendId(bin2hex((string) $message->ParentSourceKey));
					if (!$mfolderid) {
						$mfolderid = $cpo->GetRawFindFolderId();
					}

					self::$encoder->startTag(SYNC_FIND_RESULT);
					self::$encoder->startTag(SYNC_FOLDERTYPE);
					self::$encoder->content($u['class']);
					self::$encoder->endTag();

					self::$encoder->startTag(SYNC_SERVERENTRYID);
					self::$encoder->content($mfolderid . ":" . $u['serverid']);
					self::$encoder->endTag();
					self::$encoder->startTag(SYNC_FOLDERID);
					self::$encoder->content($mfolderid);
					self::$encoder->endTag();

					self::$encoder->startTag(SYNC_FIND_PROPERTIES);
					$fpmessage = SyncFindProperties::GetObjectFromSyncMail($message);
					$fpmessage->Encode(self::$encoder);

					self::$encoder->endTag(); // properties
					self::$encoder->endTag(); // result
					++$results;
				}
			}

			if ($status == SYNC_FINDSTATUS_SUCCESS) {
				// the range of the returned results, e.g. 0-0 for one result, omitted without results
				if ($results > 0) {
					self::$encoder->startTag(SYNC_FIND_RANGE);
					self::$encoder->content($rangestart . "-" . ($rangestart + $results - 1));
					self::$encoder->endTag();
				}
				self::$encoder->startTag(SYNC_FIND_TOTAL);
				self::$encoder->content((string) $searchtotal);
				self::$encoder->endTag();
			}

			self::$encoder->endTag(); // SYNC_FIND_RESPONSE
		}
		self::$encoder->endTag(); // SYNC_FIND_FIND

		return true;
	}
}
