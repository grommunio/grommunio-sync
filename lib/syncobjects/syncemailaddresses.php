<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2017 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML email addresses entities that can be parsed directly (as a stream)
 * from WBXML. It is automatically decoded according to $mapping and the
 * Sync WBXML mappings.
 */

class SyncEmailAddresses extends SyncObject {
	public $smtpaddress;
	public $primarysmtpaddress;

	public function __construct() {
		$mapping = [
			SYNC_SETTINGS_SMPTADDRESS => [
				self::STREAMER_VAR => "smtpaddress",
				self::STREAMER_PROP => self::STREAMER_TYPE_NO_CONTAINER,
				self::STREAMER_ARRAY => SYNC_SETTINGS_SMPTADDRESS, ],
			SYNC_SETTINGS_PRIMARYSMTPADDRESS => [self::STREAMER_VAR => "primarysmtpaddress"],
		];

		parent::__construct($mapping);
	}
}
