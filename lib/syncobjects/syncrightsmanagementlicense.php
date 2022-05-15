<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2017 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML rights management license entities that can be parsed directly (as a
 * stream) from WBXML. It is automatically decoded according to $mapping and
 * the Sync WBXML mappings.
 */

class SyncRightsManagementLicense extends SyncObject {

    public $contentExpiryDate;
    public $contentOwner;
    public $editAllowed;
    public $exportAllowed;
    public $extractAllowed;
    public $forwardAllowed;
    public $modifyRecipientsAllowed;
    public $owner;
    public $printAllowed;
    public $programmaticAccessAllowed;
    public $replyAllAllowed;
    public $replyAllowed;
    public $description;
    public $id;
    public $name;

    public function __construct() {
        $mapping = array (
            SYNC_RIGHTSMANAGEMENT_CONTENTEXPIRYDATE         => array (  self::STREAMER_VAR      => "contentExpiryDate",
                                                                        self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE),
            SYNC_RIGHTSMANAGEMENT_CONTENTOWNER              => array (  self::STREAMER_VAR      => "contentOwner",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_LENGTHMAX      => 320 )),
            SYNC_RIGHTSMANAGEMENT_EDITALLOWED               => array (  self::STREAMER_VAR      => "editAllowed",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_ONEVALUEOF => array(0,1) )),
            SYNC_RIGHTSMANAGEMENT_EXPORTALLOWED             => array (  self::STREAMER_VAR      => "exportAllowed",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_ONEVALUEOF => array(0,1) )),
            SYNC_RIGHTSMANAGEMENT_EXTRACTALLOWED            => array (  self::STREAMER_VAR      => "extractAllowed",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_ONEVALUEOF => array(0,1) )),
            SYNC_RIGHTSMANAGEMENT_FORWARDALLOWED            => array (  self::STREAMER_VAR      => "forwardAllowed",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_ONEVALUEOF => array(0,1) )),
            SYNC_RIGHTSMANAGEMENT_MODIFYRECIPIENTSALLOWED   => array (  self::STREAMER_VAR      => "modifyRecipientsAllowed",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_ONEVALUEOF => array(0,1) )),
            SYNC_RIGHTSMANAGEMENT_OWNER                     => array (  self::STREAMER_VAR      => "owner",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_ONEVALUEOF => array(0,1) )),
            SYNC_RIGHTSMANAGEMENT_PRINTALLOWED              => array (  self::STREAMER_VAR      => "printAllowed",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_ONEVALUEOF => array(0,1) )),
            SYNC_RIGHTSMANAGEMENT_PROGRAMMATICACCESSALLOWED => array (  self::STREAMER_VAR      => "programmaticAccessAllowed",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_ONEVALUEOF => array(0,1) )),
            SYNC_RIGHTSMANAGEMENT_REPLYALLALLOWED           => array (  self::STREAMER_VAR      => "replyAllAllowed",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_ONEVALUEOF => array(0,1) )),
            SYNC_RIGHTSMANAGEMENT_REPLYALLOWED              => array (  self::STREAMER_VAR      => "replyAllowed",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_ONEVALUEOF => array(0,1) )),
            SYNC_RIGHTSMANAGEMENT_TEMPLATEDESCRIPTION       => array (  self::STREAMER_VAR      => "description",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_LENGTHMAX      => 10240 )),
            SYNC_RIGHTSMANAGEMENT_TEMPLATEID                => array (  self::STREAMER_VAR      => "id"),
            SYNC_RIGHTSMANAGEMENT_TEMPLATENAME              => array (  self::STREAMER_VAR      => "name",
                                                                        self::STREAMER_CHECKS   => array( self::STREAMER_CHECK_LENGTHMAX      => 256 )),
        );

        parent::__construct($mapping);
    }
}
