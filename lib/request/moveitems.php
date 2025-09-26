<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Provides the MOVEITEMS command
 */

class MoveItems extends RequestProcessor {
	/**
	 * Handles the MoveItems command.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public function Handle($commandCode) {
		if (!self::$decoder->getElementStartTag(SYNC_MOVE_MOVES)) {
			return false;
		}

		$moves = [];
		while (self::$decoder->getElementStartTag(SYNC_MOVE_MOVE)) {
			$move = [];
			if (self::$decoder->getElementStartTag(SYNC_MOVE_SRCMSGID)) {
				$move["srcmsgid"] = self::$decoder->getElementContent();
				if (!self::$decoder->getElementEndTag()) {
					break;
				}
			}
			if (self::$decoder->getElementStartTag(SYNC_MOVE_SRCFLDID)) {
				$move["srcfldid"] = self::$decoder->getElementContent();
				if (!self::$decoder->getElementEndTag()) {
					break;
				}
			}
			if (self::$decoder->getElementStartTag(SYNC_MOVE_DSTFLDID)) {
				$move["dstfldid"] = self::$decoder->getElementContent();
				if (!self::$decoder->getElementEndTag()) {
					break;
				}
			}
			array_push($moves, $move);

			if (!self::$decoder->getElementEndTag()) {
				return false;
			}
		}

		if (!self::$decoder->getElementEndTag()) {
			return false;
		}

		self::$encoder->StartWBXML();

		self::$encoder->startTag(SYNC_MOVE_MOVES);

		$operationResults = [];
		$operationCounter = 0;
		$operationTotal = count($moves);
		foreach ($moves as $move) {
			++$operationCounter;
			self::$encoder->startTag(SYNC_MOVE_RESPONSE);
			self::$encoder->startTag(SYNC_MOVE_SRCMSGID);
			self::$encoder->content($move["srcmsgid"]);
			self::$encoder->endTag();

			$status = SYNC_MOVEITEMSSTATUS_SUCCESS;
			$result = false;

			try {
				$sourceBackendFolderId = self::$deviceManager->GetBackendIdForFolderId($move["srcfldid"]);

				// if the source folder is an additional folder the backend has to be setup correctly
				if (!self::$backend->Setup(GSync::GetAdditionalSyncFolderStore($sourceBackendFolderId))) {
					throw new StatusException(sprintf("HandleMoveItems() could not Setup() the backend for folder id %s/%s", $move["srcfldid"], $sourceBackendFolderId), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);
				}

				$importer = self::$backend->GetImporter($sourceBackendFolderId);
				if ($importer === false) {
					throw new StatusException(sprintf("HandleMoveItems() could not get an importer for folder id %s/%s", $move["srcfldid"], $sourceBackendFolderId), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);
				}

				// get saved SyncParameters of the source folder
				$spa = self::$deviceManager->GetStateManager()->GetSynchedFolderState($move["srcfldid"]);
				if (!$spa->HasSyncKey()) {
					throw new StatusException(sprintf("MoveItems(): Source folder id '%s' is not fully synchronized. Unable to perform operation.", $move["srcfldid"]), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);
				}

				$importer->ConfigContentParameters($spa->GetCPO());

				$result = $importer->ImportMessageMove($move["srcmsgid"], self::$deviceManager->GetBackendIdForFolderId($move["dstfldid"]));
				// We discard the standard importer state for now.
			}
			catch (StatusException $stex) {
				if ($stex->getCode() == SYNC_STATUS_FOLDERHIERARCHYCHANGED) { // same as SYNC_FSSTATUS_CODEUNKNOWN
					$status = SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID;
				}
				else {
					$status = $stex->getCode();
				}
			}

			if ($operationCounter % 10 == 0) {
				self::$topCollector->AnnounceInformation(sprintf("Moved %d objects out of %d", $operationCounter, $operationTotal));
			}

			// save the operation result
			if (!isset($operationResults[$status])) {
				$operationResults[$status] = 0;
			}
			++$operationResults[$status];

			self::$encoder->startTag(SYNC_MOVE_STATUS);
			self::$encoder->content($status);
			self::$encoder->endTag();

			self::$encoder->startTag(SYNC_MOVE_DSTMSGID);
			self::$encoder->content(($result !== false) ? $result : $move["srcmsgid"]);
			self::$encoder->endTag();
			self::$encoder->endTag();
		}

		self::$topCollector->AnnounceInformation(sprintf("Moved %d - Codes", $operationTotal), true);
		foreach ($operationResults as $status => $occurrences) {
			self::$topCollector->AnnounceInformation(sprintf("%dx%d", $occurrences, $status), true);
		}

		self::$encoder->endTag();

		return true;
	}
}
