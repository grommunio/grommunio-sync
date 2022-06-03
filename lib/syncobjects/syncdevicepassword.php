<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2013,2015-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML appointment entities that can be parsed directly (as a stream) from
 * WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncDevicePassword extends SyncObject {
	public $password;
	public $Status;

	public function __construct() {
		$mapping = [
			SYNC_SETTINGS_PW => [self::STREAMER_VAR => "password"],
			SYNC_SETTINGS_PROP_STATUS => [
				self::STREAMER_VAR => "Status",
				self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
			],
		];

		parent::__construct($mapping);
	}
}
