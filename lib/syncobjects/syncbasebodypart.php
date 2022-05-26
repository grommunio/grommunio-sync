<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2017 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML AirSyncBase body part entities that can be parsed directly (as a
 * stream) from WBXML. It is automatically decoded according to $mapping
 * and the Sync WBXML mappings.
 */

class SyncBaseBodyPart extends SyncObject {
    public $status;
    public $type; // Should be html (2)
    public $estimatedDataSize;
    public $truncated;
    public $data;
    public $preview;

    function __construct() {
        $mapping = array(
                    SYNC_AIRSYNCBASE_STATUS                             => array (  self::STREAMER_VAR        => "status"),
                    SYNC_AIRSYNCBASE_TYPE                               => array (  self::STREAMER_VAR        => "type"),
                    SYNC_AIRSYNCBASE_ESTIMATEDDATASIZE                  => array (  self::STREAMER_VAR        => "estimatedDataSize",
                                                                                    self::STREAMER_PRIVATE    => strlen(self::STRIP_PRIVATE_SUBSTITUTE)),          // when stripping private we set the body to self::STRIP_PRIVATE_SUBSTITUTE, so the size needs to be its length
                    SYNC_AIRSYNCBASE_TRUNCATED                          => array (  self::STREAMER_VAR        => "truncated"),
                    SYNC_AIRSYNCBASE_DATA                               => array (  self::STREAMER_VAR        => "data",
                                                                                    self::STREAMER_TYPE       => self::STREAMER_TYPE_STREAM_ASPLAIN,
                                                                                    self::STREAMER_PROP       => self::STREAMER_TYPE_MULTIPART,
                                                                                    self::STREAMER_RONOTIFY   => true,
                                                                                    self::STREAMER_PRIVATE    => StringStreamWrapper::Open(self::STRIP_PRIVATE_SUBSTITUTE)),       // replace the body with self::STRIP_PRIVATE_SUBSTITUTE when stripping private
                    SYNC_AIRSYNCBASE_PREVIEW                            => array (  self::STREAMER_VAR        => "preview",
                                                                                    self::STREAMER_PRIVATE    => self::STRIP_PRIVATE_SUBSTITUTE)
                );

        parent::__construct($mapping);

        // Indicates that this SyncObject supports the private flag and stripping of private data.
        $this->supportsPrivateStripping = true;
    }
}
