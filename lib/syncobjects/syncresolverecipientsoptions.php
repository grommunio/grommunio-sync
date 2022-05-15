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

class SyncResolveRecipientsOptions extends SyncObject {
    public $certificateretrieval;
    public $maxcertificates;
    public $maxambiguousrecipients;
    public $availability;
    public $picture;

    public function __construct() {
        $mapping = array (
            SYNC_RESOLVERECIPIENTS_CERTIFICATERETRIEVAL     => array (  self::STREAMER_VAR      => "certificateretrieval"),
            SYNC_RESOLVERECIPIENTS_MAXCERTIFICATES          => array (  self::STREAMER_VAR      => "maxcertificates"),
            SYNC_RESOLVERECIPIENTS_MAXAMBIGUOUSRECIPIENTS   => array (  self::STREAMER_VAR      => "maxambiguousrecipients"),

            SYNC_RESOLVERECIPIENTS_AVAILABILITY             => array (  self::STREAMER_VAR      => "availability",
                                                                        self::STREAMER_TYPE     => "SyncResolveRecipientsAvailability"),

            SYNC_RESOLVERECIPIENTS_PICTURE                  => array (  self::STREAMER_VAR      => "picture",
                                                                        self::STREAMER_TYPE     => "SyncResolveRecipientsPicture"),
        );

        parent::__construct($mapping);
    }

}
