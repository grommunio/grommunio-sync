<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML send mail source entities that can be parsed directly (as a stream)
 * from WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncSendMailSource extends SyncObject {
	public $folderid;
	public $itemid;
	public $longid;
	public $instanceid;

	public function __construct() {
		$mapping = [
			SYNC_COMPOSEMAIL_FOLDERID => [self::STREAMER_VAR => "folderid"],
			SYNC_COMPOSEMAIL_ITEMID => [self::STREAMER_VAR => "itemid"],
			SYNC_COMPOSEMAIL_LONGID => [self::STREAMER_VAR => "longid"],
			SYNC_COMPOSEMAIL_INSTANCEID => [self::STREAMER_VAR => "instanceid"],
		];

		parent::__construct($mapping);
	}
}
