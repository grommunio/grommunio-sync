<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * WBXML mail attachment entities that can be parsed directly (as a stream)
 * from WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncNote extends SyncObject {
    // Outlook transports note colors as categories
    static private $colors = array(
            0 => "Blue Category",
            1 => "Green Category",
            2 => "Red Category",
            3 => "Yellow Category",
            4 => "White Category",
        );

    // Purple and orange are not supported in PidLidNoteColor
    static private $unsupportedColors = array(
            "Purple Category",
            "Orange Category",
        );

    public $asbody;
    public $categories;
    public $lastmodified;
    public $messageclass;
    public $subject;
    public $Color;

    function __construct() {
        $mapping = array(
                    SYNC_AIRSYNCBASE_BODY                               => array (  self::STREAMER_VAR      => "asbody",
                                                                                    self::STREAMER_TYPE     => "SyncBaseBody",
                                                                                    self::STREAMER_RONOTIFY => true),

                    SYNC_NOTES_CATEGORIES                               => array (  self::STREAMER_VAR      => "categories",
                                                                                    self::STREAMER_ARRAY    => SYNC_NOTES_CATEGORY,
                                                                                    self::STREAMER_RONOTIFY => true),

                    SYNC_NOTES_LASTMODIFIEDDATE                         => array (  self::STREAMER_VAR      => "lastmodified",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE,
                                                                                    self::STREAMER_RONOTIFY => true),

                    SYNC_NOTES_MESSAGECLASS                             => array (  self::STREAMER_VAR      => "messageclass",
                                                                                    self::STREAMER_RONOTIFY => true),

                    SYNC_NOTES_SUBJECT                                  => array (  self::STREAMER_VAR      => "subject",
                                                                                    self::STREAMER_RONOTIFY => true),

                    SYNC_NOTES_IGNORE_COLOR                             => array (  self::STREAMER_VAR      => "Color",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_IGNORE),
                );

        parent::__construct($mapping);
    }

    /**
     * Sets the color index from a known category.
     *
     * @access public
     * @return void
     */
    public function SetColorFromCategory() {
        if (!empty($this->categories)) {
            $result = array_intersect($this->categories, array_values(self::$colors));
            if (empty($result)) {
                $result = array_intersect($this->categories, array_values(self::$unsupportedColors));
                if (!empty($result)) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("SyncNote->SetColorFromCategory(): unsupported color '%s', setting to color white", $result[0]));
                    $result = array("White Category");
                }
            }
            if (!empty($result)) {
                $this->Color = array_search($result[0], self::$colors);
            }
        }
        // unset or empty category means we have to reset the color to yellow
        else {
            $this->Color = 3;
        }
    }

    /**
     * Sets the category for a Color if color categories are not yet set.
     *
     * @access public
     * @return boolean
     */
    public function SetCategoryFromColor() {
        // is a color other than yellow set
        if (isset($this->Color) && $this->Color != 3 && $this->Color > -1 && $this->Color < 5) {
            // check existing categories - do not rewrite category if the category is already a supported or unsupported color
            if (!empty($this->categories)) {
                $insecUnsupp = array_intersect($this->categories, array_values(self::$unsupportedColors));
                $insecColors = array_intersect($this->categories, array_values(self::$colors));
                if (!empty($insecUnsupp) || !empty($insecColors)) {
                    return false;
                }
            }
            if(!isset($this->categories)) {
                $this->categories = array();
            }
            $this->categories[] = self::$colors[$this->Color];
            return true;
        }
    }
}
