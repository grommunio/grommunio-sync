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

class SyncResolveRecipient extends SyncObject {
    public $type;
    public $displayname;
    public $emailaddress;
    public $availability;
    public $certificates;
    public $picture;
    public $id;

    public function __construct() {
        $mapping = array (
            SYNC_RESOLVERECIPIENTS_TYPE                     => array (  self::STREAMER_VAR      => "type"),
            SYNC_RESOLVERECIPIENTS_DISPLAYNAME              => array (  self::STREAMER_VAR      => "displayname"),
            SYNC_RESOLVERECIPIENTS_EMAILADDRESS             => array (  self::STREAMER_VAR      => "emailaddress"),

            SYNC_RESOLVERECIPIENTS_CERTIFICATES             => array (  self::STREAMER_VAR      => "certificates",
                                                                        self::STREAMER_TYPE     => "SyncResolveRecipientsCertificates")
        );

        if (Request::GetProtocolVersion() >= 14.0) {
            $mapping[SYNC_RESOLVERECIPIENTS_AVAILABILITY]   = array (  self::STREAMER_VAR      => "availability",
                                                                       self::STREAMER_TYPE     => "SyncResolveRecipientsAvailability");
        }

        if (Request::GetProtocolVersion() >= 14.1) {
            $mapping[SYNC_RESOLVERECIPIENTS_PICTURE]        = array (  self::STREAMER_VAR      => "picture",
                                                                       self::STREAMER_TYPE     => "SyncResolveRecipientsPicture");
        }

        parent::__construct($mapping);
    }

}
