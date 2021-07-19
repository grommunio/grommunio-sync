<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * WBXML appointment entities that can be parsed directly (as a stream) from
 * WBXML. It is automatically decoded according to $mapping and the Sync WBXML
 * mappings.
 */

class SyncValidateCert extends SyncObject {
    public $certificatechain;
    public $certificates;
    public $checkCRL;
    public $Status;

    public function __construct() {
        $mapping = array (
            SYNC_VALIDATECERT_CERTIFICATECHAIN  => array (  self::STREAMER_VAR      => "certificatechain",
                                                            self::STREAMER_ARRAY    => SYNC_VALIDATECERT_CERTIFICATE),

            SYNC_VALIDATECERT_CERTIFICATES      => array (  self::STREAMER_VAR      => "certificates",
                                                            self::STREAMER_ARRAY    => SYNC_VALIDATECERT_CERTIFICATE),

            SYNC_VALIDATECERT_CHECKCRL          => array (  self::STREAMER_VAR      => "checkCRL"),

            SYNC_SETTINGS_PROP_STATUS           => array (  self::STREAMER_VAR      => "Status",
                                                            self::STREAMER_TYPE     => self::STREAMER_TYPE_IGNORE)
        );

        parent::__construct($mapping);
    }

}
