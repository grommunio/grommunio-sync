<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * WBXML appointment exception entities that can be parsed directly (as a
 * stream) from WBXML. It is automatically decoded according to $mapping
 * and the Sync WBXML mappings.
 */

class SyncAppointmentException extends SyncAppointment {
    public $deleted;
    public $exceptionstarttime;

    function __construct() {
        parent::__construct();

        $this->mapping += array(
                    SYNC_POOMCAL_DELETED                                => array (  self::STREAMER_VAR      => "deleted",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_ZEROORONE      => self::STREAMER_CHECK_SETZERO),
                                                                                    self::STREAMER_RONOTIFY => true),

                    SYNC_POOMCAL_EXCEPTIONSTARTTIME                     => array (  self::STREAMER_VAR      => "exceptionstarttime",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE,
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED       => self::STREAMER_CHECK_SETONE),
                                                                                    self::STREAMER_RONOTIFY => true),
                );

        // some parameters are not required in an exception, others are not allowed to be set in SyncAppointmentExceptions
        $this->mapping[SYNC_POOMCAL_TIMEZONE][self::STREAMER_CHECKS]        = array();
        $this->mapping[SYNC_POOMCAL_TIMEZONE][self::STREAMER_RONOTIFY]      = true;
        $this->mapping[SYNC_POOMCAL_DTSTAMP][self::STREAMER_CHECKS]         = array();
        $this->mapping[SYNC_POOMCAL_STARTTIME][self::STREAMER_CHECKS]       = array(self::STREAMER_CHECK_CMPLOWER   => SYNC_POOMCAL_ENDTIME);
        $this->mapping[SYNC_POOMCAL_STARTTIME][self::STREAMER_RONOTIFY]     = true;
        $this->mapping[SYNC_POOMCAL_SUBJECT][self::STREAMER_CHECKS]         = array();
        $this->mapping[SYNC_POOMCAL_SUBJECT][self::STREAMER_RONOTIFY]       = true;
        $this->mapping[SYNC_POOMCAL_ENDTIME][self::STREAMER_CHECKS]         = array(self::STREAMER_CHECK_CMPHIGHER  => SYNC_POOMCAL_STARTTIME);
        $this->mapping[SYNC_POOMCAL_ENDTIME][self::STREAMER_RONOTIFY]       = true;
        $this->mapping[SYNC_POOMCAL_BUSYSTATUS][self::STREAMER_CHECKS]      = array(self::STREAMER_CHECK_ONEVALUEOF => array(0,1,2,3,4) );
        $this->mapping[SYNC_POOMCAL_BUSYSTATUS][self::STREAMER_RONOTIFY]    = true;
        $this->mapping[SYNC_POOMCAL_REMINDER][self::STREAMER_CHECKS]        = array(self::STREAMER_CHECK_CMPHIGHER  => -1);
        $this->mapping[SYNC_POOMCAL_REMINDER][self::STREAMER_RONOTIFY]      = true;
        $this->mapping[SYNC_POOMCAL_EXCEPTIONS][self::STREAMER_CHECKS]      = array(self::STREAMER_CHECK_NOTALLOWED => true);

        // Indicates that this SyncObject supports the private flag and stripping of private data.
        // It behaves as a SyncAppointment.
        $this->supportsPrivateStripping = true;
    }
}
