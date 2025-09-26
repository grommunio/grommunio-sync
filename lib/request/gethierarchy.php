<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * Provides the GETHIERARCHY command
 */

class GetHierarchy extends RequestProcessor {
	/**
	 * Handles the GetHierarchy command
	 * simply returns current hierarchy of all folders.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public function Handle($commandCode) {
		try {
			$folders = self::$backend->GetHierarchy();
			if (!$folders || count($folders) == 0) {
				throw new StatusException("GetHierarchy() did not return any data.");
			}

			// TODO execute $data->Check() to see if SyncObject is valid
		}
		catch (StatusException $ex) {
			return false;
		}

		self::$encoder->StartWBXML();
		self::$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERS);
		foreach ($folders as $folder) {
			self::$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDER);
			$folder->Encode(self::$encoder);
			self::$encoder->endTag();
		}
		self::$encoder->endTag();

		// save hierarchy for upcoming syncing
		return self::$deviceManager->InitializeFolderCache($folders);
	}
}
