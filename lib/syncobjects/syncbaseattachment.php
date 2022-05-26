<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML AirSyncBase attachment entities that can be parsed directly (as a
 * stream) from WBXML. It is automatically decoded according to $mapping
 * and the Sync WBXML mappings.
 */

class SyncBaseAttachment extends SyncObject {
    public $displayname;
    public $filereference;
    public $method;
    public $estimatedDataSize;
    public $contentid;
    public $contentlocation;
    public $isinline;

    function __construct() {
        $mapping = array(
                    SYNC_AIRSYNCBASE_DISPLAYNAME                        => array (self::STREAMER_VAR        => "displayname"),
                    SYNC_AIRSYNCBASE_FILEREFERENCE                      => array (self::STREAMER_VAR        => "filereference"),
                    SYNC_AIRSYNCBASE_METHOD                             => array (self::STREAMER_VAR        => "method"),
                    SYNC_AIRSYNCBASE_ESTIMATEDDATASIZE                  => array (self::STREAMER_VAR        => "estimatedDataSize"),
                    SYNC_AIRSYNCBASE_CONTENTID                          => array (self::STREAMER_VAR        => "contentid"),
                    SYNC_AIRSYNCBASE_CONTENTLOCATION                    => array (self::STREAMER_VAR        => "contentlocation"),
                    SYNC_AIRSYNCBASE_ISINLINE                           => array (self::STREAMER_VAR        => "isinline"),
        );

        parent::__construct($mapping);
    }
}
