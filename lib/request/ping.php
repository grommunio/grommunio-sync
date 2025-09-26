<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * Provides the PING command
 */

class Ping extends RequestProcessor {
	/**
	 * Handles the Ping command.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public function Handle($commandCode) {
		$interval = (defined('PING_INTERVAL') && PING_INTERVAL > 0) ? PING_INTERVAL : 30;
		$pingstatus = false;
		$fakechanges = [];
		$foundchanges = false;

		// Contains all requested folders (containers)
		$sc = new SyncCollections();

		// read from stream to see if the symc params are being sent
		$params_present = self::$decoder->getElementStartTag(SYNC_PING_PING);

		// Load all collections - do load states, check permissions and allow unconfirmed states
		try {
			$sc->LoadAllCollections(true, true, true, true, false);
		}
		catch (StateInvalidException) {
			// if no params are present, indicate to send params, else do hierarchy sync
			if (!$params_present) {
				$pingstatus = SYNC_PINGSTATUS_FAILINGPARAMS;
				self::$topCollector->AnnounceInformation("StateInvalidException: require PingParameters", true);
			}
			elseif (self::$deviceManager->IsHierarchySyncRequired()) {
				// we could be in a looping  - see LoopDetection->ProcessLoopDetectionIsHierarchySyncAdvised()
				$pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
				self::$topCollector->AnnounceInformation("Potential loop detection: require HierarchySync", true);
			}
			else {
				// we do not have a ping status for this, but SyncCollections should have generated fake changes for the folders which are broken
				$fakechanges = $sc->GetChangedFolderIds();
				$foundchanges = true;

				self::$topCollector->AnnounceInformation("StateInvalidException: force sync", true);
			}
		}
		catch (StatusException) {
			$pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
			self::$topCollector->AnnounceInformation("StatusException: require HierarchySync", true);
		}

		SLog::Write(LOGLEVEL_DEBUG, sprintf("HandlePing(): reference PolicyKey for PING: %s", $sc->GetReferencePolicyKey()));

		// receive PING initialization data
		if ($params_present) {
			self::$topCollector->AnnounceInformation("Processing PING data");
			SLog::Write(LOGLEVEL_DEBUG, "HandlePing(): initialization data received");

			if (self::$decoder->getElementStartTag(SYNC_PING_LIFETIME)) {
				$sc->SetLifetime(self::$decoder->getElementContent());
				self::$decoder->getElementEndTag();
			}

			if (($el = self::$decoder->getElementStartTag(SYNC_PING_FOLDERS)) && $el[EN_FLAGS] & EN_FLAGS_CONTENT) {
				// cache requested (pingable) folderids
				$pingable = [];

				while (self::$decoder->getElementStartTag(SYNC_PING_FOLDER)) {
					WBXMLDecoder::ResetInWhile("pingFolder");
					while (WBXMLDecoder::InWhile("pingFolder")) {
						if (self::$decoder->getElementStartTag(SYNC_PING_SERVERENTRYID)) {
							$folderid = self::$decoder->getElementContent();
							self::$decoder->getElementEndTag();
						}
						if (self::$decoder->getElementStartTag(SYNC_PING_FOLDERTYPE)) {
							$class = self::$decoder->getElementContent();
							self::$decoder->getElementEndTag();
						}

						$e = self::$decoder->peek();
						if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
							self::$decoder->getElementEndTag();

							break;
						}
					}

					$spa = $sc->GetCollection($folderid);
					if (!$spa) {
						// The requested collection is not synchronized.
						// check if the HierarchyCache is available, if not, trigger a HierarchySync
						try {
							self::$deviceManager->GetFolderClassFromCacheByID($folderid);
							// ignore all folders with SYNC_FOLDER_TYPE_UNKNOWN
							if (self::$deviceManager->GetFolderTypeFromCacheById($folderid) == SYNC_FOLDER_TYPE_UNKNOWN) {
								SLog::Write(LOGLEVEL_DEBUG, sprintf("HandlePing(): ignoring folder id '%s' as it's of type UNKNOWN ", $folderid));

								continue;
							}
						}
						catch (NoHierarchyCacheAvailableException) {
							SLog::Write(LOGLEVEL_INFO, sprintf("HandlePing(): unknown collection '%s', triggering HierarchySync", $folderid));
							$pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
						}

						// Trigger a Sync request because then the device will be forced to resync this folder.
						$fakechanges[$folderid] = 1;
						$foundchanges = true;
					}
					elseif ($class == $spa->GetContentClass()) {
						$pingable[] = $folderid;
						SLog::Write(LOGLEVEL_DEBUG, sprintf("HandlePing(): using saved sync state for '%s' id '%s'", $spa->GetContentClass(), $folderid));
					}
				}
				if (!self::$decoder->getElementEndTag()) {
					return false;
				}

				// update pingable flags
				foreach ($sc as $folderid => $spa) {
					// if the folderid is in $pingable, we should ping it, else remove the flag
					if (in_array($folderid, $pingable)) {
						$spa->SetPingableFlag(true);
					}
					else {
						$spa->DelPingableFlag();
					}
				}
			}
			if (!self::$decoder->getElementEndTag()) {
				return false;
			}

			if (!$this->lifetimeBetweenBound($sc->GetLifetime())) {
				$pingstatus = SYNC_PINGSTATUS_HBOUTOFRANGE;
				SLog::Write(LOGLEVEL_DEBUG, sprintf("HandlePing(): ping lifetime not between bound (higher bound:'%d' lower bound:'%d' current lifetime:'%d'. Returning SYNC_PINGSTATUS_HBOUTOFRANGE.", PING_HIGHER_BOUND_LIFETIME, PING_LOWER_BOUND_LIFETIME, $sc->GetLifetime()));
			}
			// save changed data
			foreach ($sc as $folderid => $spa) {
				$sc->SaveCollection($spa);
			}
		} // END SYNC_PING_PING
		else {
			// if no ping initialization data was sent, we check if we have pingable folders
			// if not, we indicate that there is nothing to do.
			if (!$sc->PingableFolders()) {
				$pingstatus = SYNC_PINGSTATUS_FAILINGPARAMS;
				SLog::Write(LOGLEVEL_DEBUG, "HandlePing(): no pingable folders found and no initialization data sent. Returning SYNC_PINGSTATUS_FAILINGPARAMS.");
			}
			elseif (!$this->lifetimeBetweenBound($sc->GetLifetime())) {
				$pingstatus = SYNC_PINGSTATUS_FAILINGPARAMS;
				SLog::Write(LOGLEVEL_DEBUG, sprintf("HandlePing(): ping lifetime not between bound (higher bound:'%d' lower bound:'%d' current lifetime:'%d'. Returning SYNC_PINGSTATUS_FAILINGPARAMS.", PING_HIGHER_BOUND_LIFETIME, PING_LOWER_BOUND_LIFETIME, $sc->GetLifetime()));
			}
		}

		// Check for changes on the default LifeTime, set interval and ONLY on pingable collections
		try {
			if (!$pingstatus && empty($fakechanges)) {
				self::$deviceManager->DoAutomaticASDeviceSaving(false);
				$foundchanges = $sc->CheckForChanges($sc->GetLifetime(), $interval, true);
			}
		}
		catch (StatusException $ste) {
			switch ($ste->getCode()) {
				case SyncCollections::ERROR_NO_COLLECTIONS:
					$pingstatus = SYNC_PINGSTATUS_FAILINGPARAMS;
					break;

				case SyncCollections::ERROR_WRONG_HIERARCHY:
					$pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
					self::$deviceManager->AnnounceProcessStatus(false, $pingstatus);
					break;

				case SyncCollections::OBSOLETE_CONNECTION:
					$foundchanges = false;
					break;

				case SyncCollections::HIERARCHY_CHANGED:
					$pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
					break;
			}
		}

		self::$encoder->StartWBXML();
		self::$encoder->startTag(SYNC_PING_PING);

		self::$encoder->startTag(SYNC_PING_STATUS);
		if ($pingstatus) {
			self::$encoder->content($pingstatus);
		}
		else {
			self::$encoder->content($foundchanges ? SYNC_PINGSTATUS_CHANGES : SYNC_PINGSTATUS_HBEXPIRED);
		}
		self::$encoder->endTag();

		if (!$pingstatus) {
			self::$encoder->startTag(SYNC_PING_FOLDERS);

			if (empty($fakechanges)) {
				$changes = $sc->GetChangedFolderIds();
			}
			else {
				$changes = $fakechanges;
			}

			$announceAggregated = false;
			if (count($changes) > 1) {
				$announceAggregated = 0;
			}
			foreach ($changes as $folderid => $changecount) {
				if ($changecount > 0) {
					self::$encoder->startTag(SYNC_PING_FOLDER);
					self::$encoder->content($folderid);
					self::$encoder->endTag();
					if ($announceAggregated === false) {
						if (empty($fakechanges)) {
							self::$topCollector->AnnounceInformation(sprintf("Found change in %s", $sc->GetCollection($folderid)->GetContentClass()), true);
						}
					}
					else {
						$announceAggregated += $changecount;
					}
					self::$deviceManager->AnnounceProcessStatus($folderid, SYNC_PINGSTATUS_CHANGES);
				}
			}
			if ($announceAggregated !== false) {
				self::$topCollector->AnnounceInformation(sprintf("Found %d changes in %d folders", $announceAggregated, count($changes)), true);
			}
			self::$encoder->endTag();
		}
		elseif ($pingstatus == SYNC_PINGSTATUS_HBOUTOFRANGE) {
			self::$encoder->startTag(SYNC_PING_LIFETIME);
			if ($sc->GetLifetime() > PING_HIGHER_BOUND_LIFETIME) {
				self::$encoder->content(PING_HIGHER_BOUND_LIFETIME);
			}
			else {
				self::$encoder->content(PING_LOWER_BOUND_LIFETIME);
			}
			self::$encoder->endTag();
		}

		self::$encoder->endTag();

		// update the waittime waited
		self::$waitTime = $sc->GetWaitedSeconds();

		return true;
	}

	/**
	 * Return true if the ping lifetime is between the specified bound (PING_HIGHER_BOUND_LIFETIME and PING_LOWER_BOUND_LIFETIME). If no bound are specified, it returns true.
	 *
	 * @param int $lifetime
	 *
	 * @return bool
	 */
	private function lifetimeBetweenBound($lifetime) {
		if (PING_HIGHER_BOUND_LIFETIME !== false && PING_LOWER_BOUND_LIFETIME !== false) {
			return $lifetime <= PING_HIGHER_BOUND_LIFETIME && $lifetime >= PING_LOWER_BOUND_LIFETIME;
		}
		if (PING_HIGHER_BOUND_LIFETIME !== false) {
			return $lifetime <= PING_HIGHER_BOUND_LIFETIME;
		}
		if (PING_LOWER_BOUND_LIFETIME !== false) {
			return $lifetime >= PING_LOWER_BOUND_LIFETIME;
		}

		return true;
	}
}
