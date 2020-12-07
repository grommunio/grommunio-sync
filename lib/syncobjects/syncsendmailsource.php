<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * WBXML send mail source entities that can be parsed directly (as a stream)
 * from WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncSendMailSource extends SyncObject {
    public $folderid;
    public $itemid;
    public $longid;
    public $instanceid;

    function __construct() {
        $mapping = array (
                    SYNC_COMPOSEMAIL_FOLDERID                             => array (  self::STREAMER_VAR      => "folderid"),
                    SYNC_COMPOSEMAIL_ITEMID                               => array (  self::STREAMER_VAR      => "itemid"),
                    SYNC_COMPOSEMAIL_LONGID                               => array (  self::STREAMER_VAR      => "longid"),
                    SYNC_COMPOSEMAIL_INSTANCEID                           => array (  self::STREAMER_VAR      => "instanceid"),
        );

        parent::__construct($mapping);
    }

}
