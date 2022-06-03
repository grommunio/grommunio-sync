<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML user information entities that can be parsed directly (as a stream)
 * from WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncUserInformation extends SyncObject {
	public $emailaddresses;
	public $accounts;
	public $Status;

	public function __construct() {
		$mapping = [
			SYNC_SETTINGS_PROP_STATUS => [
				self::STREAMER_VAR => "Status",
				self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
			],
		];

		// In AS protocol versions 12.0, 12.1 and 14.0 EmailAddresses element is child of Get in UserSettings
		// Since AS protocol version 14.1 EmailAddresses element is child of Account element of Get in UserSettings
		if (Request::GetProtocolVersion() >= 12.0) {
			$mapping[SYNC_SETTINGS_EMAILADDRESSES] = [
				self::STREAMER_VAR => "emailaddresses",
				self::STREAMER_ARRAY => SYNC_SETTINGS_SMPTADDRESS,
			];
		}

		if (Request::GetProtocolVersion() >= 14.1) {
			unset($mapping[SYNC_SETTINGS_EMAILADDRESSES]);
			$mapping[SYNC_SETTINGS_ACCOUNTS] = [
				self::STREAMER_VAR => "accounts",
				self::STREAMER_TYPE => "SyncAccount",
				self::STREAMER_ARRAY => SYNC_SETTINGS_ACCOUNT,
			];
		}

		parent::__construct($mapping);
	}
}
