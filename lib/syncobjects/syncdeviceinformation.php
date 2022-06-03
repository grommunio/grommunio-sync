<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML appointment entities that can be parsed directly (as a stream) from
 * WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncDeviceInformation extends SyncObject {
	public $model;
	public $imei;
	public $friendlyname;
	public $os;
	public $oslanguage;
	public $phonenumber;
	public $useragent; // 12.1 &14.0
	public $mobileoperator; // 14.0
	public $enableoutboundsms; // 14.0
	public $Status;

	public function __construct() {
		$mapping = [
			SYNC_SETTINGS_MODEL => [self::STREAMER_VAR => "model"],
			SYNC_SETTINGS_IMEI => [self::STREAMER_VAR => "imei"],
			SYNC_SETTINGS_FRIENDLYNAME => [self::STREAMER_VAR => "friendlyname"],
			SYNC_SETTINGS_OS => [self::STREAMER_VAR => "os"],
			SYNC_SETTINGS_OSLANGUAGE => [self::STREAMER_VAR => "oslanguage"],
			SYNC_SETTINGS_PHONENUMBER => [self::STREAMER_VAR => "phonenumber"],
			SYNC_SETTINGS_PROP_STATUS => [
				self::STREAMER_VAR => "Status",
				self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
			],
		];

		if (Request::GetProtocolVersion() >= 12.1) {
			$mapping[SYNC_SETTINGS_USERAGENT] = [self::STREAMER_VAR => "useragent"];
		}

		if (Request::GetProtocolVersion() >= 14.0) {
			$mapping[SYNC_SETTINGS_MOBILEOPERATOR] = [self::STREAMER_VAR => "mobileoperator"];
			$mapping[SYNC_SETTINGS_ENABLEOUTBOUNDSMS] = [self::STREAMER_VAR => "enableoutboundsms"];
		}

		parent::__construct($mapping);
	}
}
