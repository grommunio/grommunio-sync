<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2013,2015-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * WBXML appointment entities that can be parsed directly (as a stream) from
 * WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncResolveRecipientsAvailability extends SyncObject {
    public $starttime;
    public $endtime;
    public $status;
    public $mergedfreebusy;

    public function __construct() {
        $mapping = array ();

        if (Request::GetProtocolVersion() >= 14.0) {
            $mapping[SYNC_RESOLVERECIPIENTS_STARTTIME]      = array (  self::STREAMER_VAR      => "starttime");
            $mapping[SYNC_RESOLVERECIPIENTS_ENDTIME]        = array (  self::STREAMER_VAR      => "endtime");
            $mapping[SYNC_RESOLVERECIPIENTS_STATUS]         = array (  self::STREAMER_VAR      => "status");
            $mapping[SYNC_RESOLVERECIPIENTS_MERGEDFREEBUSY] = array (  self::STREAMER_VAR      => "mergedfreebusy");
        }

        parent::__construct($mapping);
    }

}
