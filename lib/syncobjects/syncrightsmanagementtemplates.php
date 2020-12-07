<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2017 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * WBXML rights management templates entities that can be parsed directly (as a
 * stream) from WBXML. It is automatically decoded according to $mapping and
 * the Sync WBXML mappings.
 */

class SyncRightsManagementTemplates extends SyncObject {

    public $rmtemplates;
    public $Status;

    public function __construct() {
        $mapping = array (
            SYNC_RIGHTSMANAGEMENT_TEMPLATES     => array (  self::STREAMER_VAR   => "rmtemplates",
                                                            self::STREAMER_TYPE  => "SyncRigtsManagementTemplate",
                                                            self::STREAMER_ARRAY => SYNC_RIGHTSMANAGEMENT_TEMPLATE,
                                                            self::STREAMER_PROP  => self::STREAMER_TYPE_SEND_EMPTY),

            SYNC_SETTINGS_PROP_STATUS           => array (  self::STREAMER_VAR      => "Status",
                                                            self::STREAMER_TYPE     => self::STREAMER_TYPE_IGNORE)
        );

        parent::__construct($mapping);
    }
}
