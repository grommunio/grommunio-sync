<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2013,2015-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Provides the ResolveRecipients command
 */

class ResolveRecipients extends RequestProcessor {
	/**
	 * Handles the ResolveRecipients command.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public function Handle($commandCode) {
		// Parse input
		if (!self::$decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_RESOLVERECIPIENTS)) {
			return false;
		}

		$resolveRecipients = new SyncResolveRecipients();
		$resolveRecipients->Decode(self::$decoder);

		if (!self::$decoder->getElementEndTag()) {
			return false;
		} // SYNC_RESOLVERECIPIENTS_RESOLVERECIPIENTS

		$resolveRecipients = self::$backend->ResolveRecipients($resolveRecipients);

		self::$encoder->startWBXML();
		self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_RESOLVERECIPIENTS);

		self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_STATUS);
		self::$encoder->content($resolveRecipients->status);
		self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_STATUS

		if ($resolveRecipients->status == SYNC_COMMONSTATUS_SUCCESS && !empty($resolveRecipients->response)) {
			foreach ($resolveRecipients->response as $i => $response) {
				self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_RESPONSE);
				self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_TO);
				self::$encoder->content($response->to);
				self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_TO

				self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_STATUS);
				self::$encoder->content($response->status);
				self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_STATUS

				// do only if recipient is resolved
				if ($response->status != SYNC_RESOLVERECIPSSTATUS_RESPONSE_UNRESOLVEDRECIP && !empty($response->recipient)) {
					self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_RECIPIENTCOUNT);
					self::$encoder->content(count($response->recipient));
					self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_RECIPIENTCOUNT

					foreach ($response->recipient as $recipient) {
						self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_RECIPIENT);
						$recipient->Encode(self::$encoder);
						self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_RECIPIENT
					}
				}
				self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_RESPONSE
			}
		}

		self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_RESOLVERECIPIENTS

		return true;
	}
}
