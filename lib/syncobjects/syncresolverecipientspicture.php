<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2013,2015-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML appointment entities that can be parsed directly (as a stream) from
 * WBXML. It is automatically decoded according to $mapping and the Sync WBXML
 * mappings.
 */

class SyncResolveRecipientsPicture extends SyncObject {
    public $maxsize;
    public $maxpictures;
    public $status;
    public $data;

    public function __construct() {
        $mapping = array ();

        if (Request::GetProtocolVersion() >= 14.1) {
            $mapping[SYNC_RESOLVERECIPIENTS_MAXSIZE]        = array (  self::STREAMER_VAR      => "maxsize");
            $mapping[SYNC_RESOLVERECIPIENTS_MAXPICTURES]    = array (  self::STREAMER_VAR      => "maxpictures");
            $mapping[SYNC_RESOLVERECIPIENTS_STATUS]         = array (  self::STREAMER_VAR      => "status");
            $mapping[SYNC_RESOLVERECIPIENTS_DATA]           = array (  self::STREAMER_VAR      => "data",
                                                                       self::STREAMER_TYPE     => self::STREAMER_TYPE_STREAM_ASBASE64,
                                                                     );
        }

        parent::__construct($mapping);
    }

}
