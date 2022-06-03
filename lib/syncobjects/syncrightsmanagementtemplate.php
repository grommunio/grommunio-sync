<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2017 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML rights management template entities that can be parsed directly (as a
 * stream) from WBXML. It is automatically decoded according to $mapping and
 * the Sync WBXML mappings.
 */

class SyncRightsManagementTemplate extends SyncObject {
	public $description;
	public $id;
	public $name;

	public function __construct() {
		$mapping = [
			SYNC_RIGHTSMANAGEMENT_TEMPLATEDESCRIPTION => [
				self::STREAMER_VAR => "description",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_LENGTHMAX => 10240],
			],
			SYNC_RIGHTSMANAGEMENT_TEMPLATEID => [self::STREAMER_VAR => "id"],
			SYNC_RIGHTSMANAGEMENT_TEMPLATENAME => [
				self::STREAMER_VAR => "name",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_LENGTHMAX => 256],
			],
		];

		parent::__construct($mapping);
	}
}
