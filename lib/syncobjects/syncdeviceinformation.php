<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * WBXML appointment entities that can be parsed directly (as a stream) from
 * WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncDeviceInformation extends SyncObject {
    public $model;
    public $imei;
    public $friendlyname;
    public $os;
    public $oslanguage;
    public $phonenumber;
    public $useragent; //12.1 &14.0
    public $mobileoperator; //14.0
    public $enableoutboundsms; //14.0
    public $Status;

    public function __construct() {
        $mapping = array (
            SYNC_SETTINGS_MODEL                         => array (  self::STREAMER_VAR      => "model"),
            SYNC_SETTINGS_IMEI                          => array (  self::STREAMER_VAR      => "imei"),
            SYNC_SETTINGS_FRIENDLYNAME                  => array (  self::STREAMER_VAR      => "friendlyname"),
            SYNC_SETTINGS_OS                            => array (  self::STREAMER_VAR      => "os"),
            SYNC_SETTINGS_OSLANGUAGE                    => array (  self::STREAMER_VAR      => "oslanguage"),
            SYNC_SETTINGS_PHONENUMBER                   => array (  self::STREAMER_VAR      => "phonenumber"),

            SYNC_SETTINGS_PROP_STATUS                   => array (  self::STREAMER_VAR      => "Status",
                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_IGNORE)
        );

        if (Request::GetProtocolVersion() >= 12.1) {
            $mapping[SYNC_SETTINGS_USERAGENT]           = array (   self::STREAMER_VAR       => "useragent");
        }

        if (Request::GetProtocolVersion() >= 14.0) {
            $mapping[SYNC_SETTINGS_MOBILEOPERATOR]      = array (   self::STREAMER_VAR       => "mobileoperator");
            $mapping[SYNC_SETTINGS_ENABLEOUTBOUNDSMS]   = array (   self::STREAMER_VAR       => "enableoutboundsms");
        }

        parent::__construct($mapping);
    }
}
