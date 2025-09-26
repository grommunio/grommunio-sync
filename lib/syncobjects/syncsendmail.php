<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML sendmail entities that can be parsed directly (as a stream) from
 * WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncSendMail extends SyncObject {
	public $clientid;
	public $saveinsent;
	public $replacemime;
	public $accountid;
	public $source;
	public $mime;
	public $replyflag;
	public $forwardflag;

	public function __construct() {
		$mapping = [
			SYNC_COMPOSEMAIL_CLIENTID => [self::STREAMER_VAR => "clientid"],
			SYNC_COMPOSEMAIL_SAVEINSENTITEMS => [
				self::STREAMER_VAR => "saveinsent",
				self::STREAMER_PROP => self::STREAMER_TYPE_SEND_EMPTY,
			],
			SYNC_COMPOSEMAIL_REPLACEMIME => [
				self::STREAMER_VAR => "replacemime",
				self::STREAMER_PROP => self::STREAMER_TYPE_SEND_EMPTY,
			],
			SYNC_COMPOSEMAIL_ACCOUNTID => [self::STREAMER_VAR => "accountid"],
			SYNC_COMPOSEMAIL_SOURCE => [
				self::STREAMER_VAR => "source",
				self::STREAMER_TYPE => "SyncSendMailSource",
			],
			SYNC_COMPOSEMAIL_MIME => [self::STREAMER_VAR => "mime"],
			SYNC_COMPOSEMAIL_REPLYFLAG => [
				self::STREAMER_VAR => "replyflag",
				self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
			],
			SYNC_COMPOSEMAIL_FORWARDFLAG => [
				self::STREAMER_VAR => "forwardflag",
				self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
			],
		];

		parent::__construct($mapping);
	}
}
