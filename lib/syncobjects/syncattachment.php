<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML mail attachment entities that can be parsed directly (as a stream)
 * from WBXML. It is automatically decoded according to $mapping and the
 * Sync WBXML mappings.
 */

class SyncAttachment extends SyncObject {
	public $attmethod;
	public $attsize;
	public $displayname;
	public $attname;
	public $attoid;
	public $attremoved;

	public function __construct() {
		$mapping = [
			SYNC_POOMMAIL_ATTMETHOD => [
				self::STREAMER_VAR => "attmethod",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMMAIL_ATTSIZE => [
				self::STREAMER_VAR => "attsize",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETZERO,
					self::STREAMER_CHECK_CMPHIGHER => -1,
				],
			],
			SYNC_POOMMAIL_DISPLAYNAME => [
				self::STREAMER_VAR => "displayname",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETEMPTY],
			],
			SYNC_POOMMAIL_ATTNAME => [
				self::STREAMER_VAR => "attname",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETEMPTY],
			],
			SYNC_POOMMAIL_ATTOID => [self::STREAMER_VAR => "attoid"],
			SYNC_POOMMAIL_ATTREMOVED => [
				self::STREAMER_VAR => "attremoved",
				self::STREAMER_RONOTIFY => true,
			],
		];

		parent::__construct($mapping);
	}
}
