<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2015-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML appointment entities that can be parsed directly (as a stream) from
 * WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncResolveRecipientsResponse extends SyncObject {
	public $to;
	public $status;
	public $recipientcount;
	public $recipient;

	public function __construct() {
		$mapping = [
			SYNC_RESOLVERECIPIENTS_TO => [self::STREAMER_VAR => "to"],
			SYNC_RESOLVERECIPIENTS_STATUS => [self::STREAMER_VAR => "status"],
			SYNC_RESOLVERECIPIENTS_RECIPIENTCOUNT => [self::STREAMER_VAR => "recipientcount"],
			SYNC_RESOLVERECIPIENTS_RECIPIENT => [
				self::STREAMER_VAR => "recipient",
				self::STREAMER_TYPE => "SyncResolveRecipient",
				self::STREAMER_ARRAY => SYNC_RESOLVERECIPIENTS_RECIPIENT,
			],
		];

		parent::__construct($mapping);
	}
}
