<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * WBXML mail attachment entities that can be parsed directly (as a stream)
 * from WBXML. It is automatically decoded according to $mapping and the
 * Sync WBXML mappings.
 */

class SyncAttachment extends SyncObject {
    public $attmethod;
    public $attsize;
    public $displayname;
    public $attname;
    public $attoid;
    public $attremoved;

    function __construct() {
        $mapping = array(
                    SYNC_POOMMAIL_ATTMETHOD                             => array (  self::STREAMER_VAR      => "attmethod",
                                                                                    self::STREAMER_RONOTIFY => true),
                    SYNC_POOMMAIL_ATTSIZE                               => array (  self::STREAMER_VAR      => "attsize",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED   => self::STREAMER_CHECK_SETZERO,
                                                                                                                        self::STREAMER_CHECK_CMPHIGHER  => -1 )),

                    SYNC_POOMMAIL_DISPLAYNAME                           => array (  self::STREAMER_VAR      => "displayname",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED   => self::STREAMER_CHECK_SETEMPTY)),

                    SYNC_POOMMAIL_ATTNAME                               => array (  self::STREAMER_VAR      => "attname",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED   => self::STREAMER_CHECK_SETEMPTY)),

                    SYNC_POOMMAIL_ATTOID                                => array (  self::STREAMER_VAR      => "attoid"),
                    SYNC_POOMMAIL_ATTREMOVED                            => array (  self::STREAMER_VAR      => "attremoved",
                                                                                    self::STREAMER_RONOTIFY => true),
                );

        parent::__construct($mapping);
    }
}
