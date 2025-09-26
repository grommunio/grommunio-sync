<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML AirSyncBase body entities that can be parsed directly (as a stream)
 * from WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncBaseBody extends SyncObject {
	public $type; // Possible types are plain text, html, rtf and mime
	public $estimatedDataSize;
	public $truncated;
	public $data;
	public $preview;

	public function __construct() {
		$mapping = [
			SYNC_AIRSYNCBASE_TYPE => [
				self::STREAMER_VAR => "type",
			],
			SYNC_AIRSYNCBASE_ESTIMATEDDATASIZE => [
				self::STREAMER_VAR => "estimatedDataSize",
				// when stripping private we set the body to self::STRIP_PRIVATE_SUBSTITUTE, so the size needs to be its length
				self::STREAMER_PRIVATE => strlen(self::STRIP_PRIVATE_SUBSTITUTE),
			],
			SYNC_AIRSYNCBASE_TRUNCATED => [self::STREAMER_VAR => "truncated"],
			SYNC_AIRSYNCBASE_DATA => [
				self::STREAMER_VAR => "data",
				self::STREAMER_TYPE => self::STREAMER_TYPE_STREAM_ASPLAIN,
				self::STREAMER_PROP => self::STREAMER_TYPE_MULTIPART,
				self::STREAMER_RONOTIFY => true,
				// replace the body with self::STRIP_PRIVATE_SUBSTITUTE when stripping private
				self::STREAMER_PRIVATE => StringStreamWrapper::Open(self::STRIP_PRIVATE_SUBSTITUTE),
			],
		];
		if (Request::GetProtocolVersion() >= 14.0) {
			$mapping[SYNC_AIRSYNCBASE_PREVIEW] = [
				self::STREAMER_VAR => "preview",
				self::STREAMER_PRIVATE => self::STRIP_PRIVATE_SUBSTITUTE,
			];
		}

		parent::__construct($mapping);

		// Indicates that this SyncObject supports the private flag and stripping of private data.
		$this->supportsPrivateStripping = true;
	}
}
