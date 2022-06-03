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

class SyncOOFMessage extends SyncObject {
	public $appliesToInternal;
	public $appliesToExternal;
	public $appliesToExternalUnknown;
	public $enabled;
	public $replymessage;
	public $bodytype;

	public function __construct() {
		$mapping = [
			// only one of the following 3 apply types will be available
			SYNC_SETTINGS_APPLIESTOINTERVAL => [
				self::STREAMER_VAR => "appliesToInternal",
				self::STREAMER_PROP => self::STREAMER_TYPE_SEND_EMPTY,
			],
			SYNC_SETTINGS_APPLIESTOEXTERNALKNOWN => [
				self::STREAMER_VAR => "appliesToExternal",
				self::STREAMER_PROP => self::STREAMER_TYPE_SEND_EMPTY,
			],
			SYNC_SETTINGS_APPLIESTOEXTERNALUNKNOWN => [
				self::STREAMER_VAR => "appliesToExternalUnknown",
				self::STREAMER_PROP => self::STREAMER_TYPE_SEND_EMPTY,
			],
			SYNC_SETTINGS_ENABLED => [self::STREAMER_VAR => "enabled"],
			SYNC_SETTINGS_REPLYMESSAGE => [self::STREAMER_VAR => "replymessage"],
			SYNC_SETTINGS_BODYTYPE => [
				self::STREAMER_VAR => "bodytype",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_ONEVALUEOF => [
						SYNC_SETTINGSOOF_BODYTYPE_HTML,
						ucfirst(strtolower(SYNC_SETTINGSOOF_BODYTYPE_TEXT)),
					],
				],
			],
		];

		parent::__construct($mapping);
	}
}
