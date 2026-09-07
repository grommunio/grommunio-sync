<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * WBXML forwardee entities of a SmartForward of a calendar item that can
 * be parsed directly (as a stream) from WBXML. It is automatically decoded
 * according to $mapping and the Sync WBXML mappings.
 */

class SyncForwardee extends SyncObject {
	public $name;
	public $email;

	public function __construct() {
		$mapping = [
			SYNC_COMPOSEMAIL_NAME => [self::STREAMER_VAR => "name"],
			SYNC_COMPOSEMAIL_EMAIL => [self::STREAMER_VAR => "email"],
		];

		parent::__construct($mapping);
	}
}
