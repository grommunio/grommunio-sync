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

class SyncOOF extends SyncObject {
	public $oofstate;
	public $starttime;
	public $endtime;
	public $oofmessage = [];
	public $bodytype;
	public $Status;

	public function __construct() {
		$mapping = [
			SYNC_SETTINGS_OOFSTATE => [
				self::STREAMER_VAR => "oofstate",
				self::STREAMER_CHECKS => [
					[self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2]],
				],
			],
			SYNC_SETTINGS_STARTTIME => [
				self::STREAMER_VAR => "starttime",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
			],
			SYNC_SETTINGS_ENDTIME => [
				self::STREAMER_VAR => "endtime",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
			],
			SYNC_SETTINGS_OOFMESSAGE => [
				self::STREAMER_VAR => "oofmessage",
				self::STREAMER_TYPE => "SyncOOFMessage",
				self::STREAMER_PROP => self::STREAMER_TYPE_NO_CONTAINER,
				self::STREAMER_ARRAY => SYNC_SETTINGS_OOFMESSAGE,
			],
			SYNC_SETTINGS_BODYTYPE => [
				self::STREAMER_VAR => "bodytype",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_ONEVALUEOF => [
						SYNC_SETTINGSOOF_BODYTYPE_HTML,
						ucfirst(strtolower(SYNC_SETTINGSOOF_BODYTYPE_TEXT)),
					],
				],
			],
			SYNC_SETTINGS_PROP_STATUS => [
				self::STREAMER_VAR => "Status",
				self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE, ],
		];

		parent::__construct($mapping);
	}
}
