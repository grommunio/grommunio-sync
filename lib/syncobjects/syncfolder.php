<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML folder entities that can be parsed directly (as a stream) from WBXML.
 * It is automatically decoded according to $mapping and the Sync WBXML
 * mappings.
 */

class SyncFolder extends SyncObject {
	public $serverid;
	public $parentid;
	public $displayname;
	public $type;
	public $Store;
	public $NoBackendFolder;
	public $BackendId;
	public $Flags;
	public $TypeReal;

	public function __construct() {
		$mapping = [
			SYNC_FOLDERHIERARCHY_SERVERENTRYID => [
				self::STREAMER_VAR => "serverid",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_REQUIRED => false],
			],
			SYNC_FOLDERHIERARCHY_PARENTID => [
				self::STREAMER_VAR => "parentid",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETZERO],
			],
			SYNC_FOLDERHIERARCHY_DISPLAYNAME => [
				self::STREAMER_VAR => "displayname",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_REQUIRED => "Unknown"],
			],
			SYNC_FOLDERHIERARCHY_TYPE => [self::STREAMER_VAR => "type",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => 18,
					self::STREAMER_CHECK_CMPHIGHER => 0,
					self::STREAMER_CHECK_CMPLOWER => 20,
				],
			],
			SYNC_FOLDERHIERARCHY_IGNORE_STORE => [
				self::STREAMER_VAR => "Store",
				self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
			],
			SYNC_FOLDERHIERARCHY_IGNORE_NOBCKENDFLD => [
				self::STREAMER_VAR => "NoBackendFolder",
				self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
			],
			SYNC_FOLDERHIERARCHY_IGNORE_BACKENDID => [
				self::STREAMER_VAR => "BackendId",
				self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
			],
			SYNC_FOLDERHIERARCHY_IGNORE_FLAGS => [
				self::STREAMER_VAR => "Flags",
				self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
			],
			SYNC_FOLDERHIERARCHY_IGNORE_TYPEREAL => [
				self::STREAMER_VAR => "TypeReal",
				self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
			],
		];

		parent::__construct($mapping);
	}

	/**
	 * Returns a SyncFolder object with the serverid and optional parentid set.
	 *
	 * @param string $serverid
	 * @param string $parentid
	 *
	 * @return SyncFolder object
	 */
	public static function GetObject($serverid, $parentid = false) {
		$folder = new SyncFolder();
		$folder->serverid = $serverid;
		$folder->parentid = $parentid;

		return $folder;
	}
}
