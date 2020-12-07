<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * WBXML ItemOperations attachment entities that can be parsed directly (as a
 * stream) from WBXML. It is automatically decoded according to $mapping and
 * the Sync WBXML mappings.
 */

class SyncItemOperationsAttachment extends SyncObject {
    public $contenttype;
    public $data;

    function __construct() {
        $mapping = array(
            SYNC_AIRSYNCBASE_CONTENTTYPE                        => array (  self::STREAMER_VAR      => "contenttype"),
            SYNC_ITEMOPERATIONS_DATA                            => array (  self::STREAMER_VAR      => "data",
                                                                            self::STREAMER_TYPE     => self::STREAMER_TYPE_STREAM_ASBASE64,
                                                                            self::STREAMER_PROP     => self::STREAMER_TYPE_MULTIPART),
        );

        parent::__construct($mapping);
    }
}
