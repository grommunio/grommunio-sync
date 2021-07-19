<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2017 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * WBXML account entities that can be parsed directly (as a stream) from WBXML.
 * It is automatically decoded according to $mapping and the Sync WBXML
 * mappings
 */

class SyncAccount extends SyncObject {
    public $accountid;
    public $accountname;
    public $userdisplayname;
    public $senddisabled;
    public $emailaddresses;

    public function __construct() {
        $mapping = array (
            SYNC_SETTINGS_ACCOUNTID                 => array (  self::STREAMER_VAR      => "accountid"),
            SYNC_SETTINGS_ACCOUNTNAME               => array (  self::STREAMER_VAR      => "accountname"),
            SYNC_SETTINGS_USERDISPLAYNAME           => array (  self::STREAMER_VAR      => "userdisplayname"),
            SYNC_SETTINGS_SENDDISABLED              => array (  self::STREAMER_VAR      => "senddisabled"),
            SYNC_SETTINGS_EMAILADDRESSES            => array (  self::STREAMER_VAR      => "emailaddresses",
                                                                self::STREAMER_TYPE     => "SyncEmailAddresses")
        );

        parent::__construct($mapping);
    }
}
