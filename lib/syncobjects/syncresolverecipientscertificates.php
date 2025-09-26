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

class SyncResolveRecipientsCertificates extends SyncObject {
	public $status;
	public $certificatecount;
	public $recipientcount;
	public $certificate;
	public $minicertificate;

	public function __construct() {
		$mapping = [
			SYNC_RESOLVERECIPIENTS_STATUS => [self::STREAMER_VAR => "status"],
			SYNC_RESOLVERECIPIENTS_CERTIFICATECOUNT => [self::STREAMER_VAR => "certificatecount"],
			SYNC_RESOLVERECIPIENTS_RECIPIENTCOUNT => [self::STREAMER_VAR => "recipientcount"],
			SYNC_RESOLVERECIPIENTS_CERTIFICATE => [
				self::STREAMER_VAR => "certificate",
				self::STREAMER_ARRAY => SYNC_RESOLVERECIPIENTS_CERTIFICATE,
				self::STREAMER_PROP => self::STREAMER_TYPE_NO_CONTAINER,
			],
			SYNC_RESOLVERECIPIENTS_MINICERTIFICATE => [
				self::STREAMER_VAR => "minicertificate",
				self::STREAMER_ARRAY => SYNC_RESOLVERECIPIENTS_MINICERTIFICATE,
				self::STREAMER_PROP => self::STREAMER_TYPE_NO_CONTAINER,
			],
		];

		parent::__construct($mapping);
	}
}
