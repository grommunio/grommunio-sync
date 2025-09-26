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

class SyncResolveRecipients extends SyncObject {
	public $to = [];
	public $options;
	public $status;
	public $response;

	public function __construct() {
		$mapping = [
			SYNC_RESOLVERECIPIENTS_TO => [
				self::STREAMER_VAR => "to",
				self::STREAMER_ARRAY => SYNC_RESOLVERECIPIENTS_TO,
				self::STREAMER_PROP => self::STREAMER_TYPE_NO_CONTAINER,
			],
			SYNC_RESOLVERECIPIENTS_OPTIONS => [
				self::STREAMER_VAR => "options",
				self::STREAMER_TYPE => "SyncResolveRecipientsOptions",
			],
			SYNC_RESOLVERECIPIENTS_STATUS => [self::STREAMER_VAR => "status"],
			SYNC_RESOLVERECIPIENTS_RESPONSE => [
				self::STREAMER_VAR => "response",
				self::STREAMER_TYPE => "SyncResolveRecipientsResponse",
				self::STREAMER_ARRAY => SYNC_RESOLVERECIPIENTS_RESPONSE,
			],
		];

		parent::__construct($mapping);
	}
}
