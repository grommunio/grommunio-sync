<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Provides the NOTIFY command
 */

class Notify extends RequestProcessor {
	/**
	 * Handles the Notify command.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public function Handle($commandCode) {
		if (!self::$decoder->getElementStartTag(SYNC_AIRNOTIFY_NOTIFY)) {
			return false;
		}

		if (!self::$decoder->getElementStartTag(SYNC_AIRNOTIFY_DEVICEINFO)) {
			return false;
		}

		if (!self::$decoder->getElementEndTag()) {
			return false;
		}

		if (!self::$decoder->getElementEndTag()) {
			return false;
		}

		self::$encoder->StartWBXML();

		self::$encoder->startTag(SYNC_AIRNOTIFY_NOTIFY);

		self::$encoder->startTag(SYNC_AIRNOTIFY_STATUS);
		self::$encoder->content(1);
		self::$encoder->endTag();

		self::$encoder->startTag(SYNC_AIRNOTIFY_VALIDCARRIERPROFILES);
		self::$encoder->endTag();

		self::$encoder->endTag();

		return true;
	}
}
