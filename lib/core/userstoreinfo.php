<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2018 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Contains information about user and his store.
 */

class UserStoreInfo {
    private $foldercount;
    private $storesize;
    private $fullname;
    private $emailaddress;

    /**
     * Constructor
     *
     * @access public
     */
    public function __construct() {
        $this->foldercount = 0;
        $this->storesize = 0;
        $this->fullname = null;
        $this->emailaddress = null;
    }

    /**
     * Sets data for the user's store.
     *
     * @param int $foldercount
     * @param int $storesize
     * @param string $fullname
     * @param string $emailaddress
     *
     * @access public
     * @return void
     */
    public function SetData($foldercount, $storesize, $fullname, $emailaddress) {
        $this->foldercount = $foldercount;
        $this->storesize = $storesize;
        $this->fullname = $fullname;
        $this->emailaddress = $emailaddress;
    }

    /**
     * Returns the number of folders in user's store.
     *
     * @access public
     * @return int
     */
    public function GetFolderCount() {
        return $this->foldercount;
    }

    /**
     * Returns the user's store size in bytes.
     *
     * @access public
     * @return int
     */
    public function GetStoreSize() {
        return $this->storesize;
    }

    /**
     * Returns the fullname of the user.
     *
     * @access public
     * @return string
     */
    public function GetFullName() {
        return $this->fullname;
    }

    /**
     * Returns the email address of the user.
     *
     * @access public
     * @return string
     */
    public function GetEmailAddress() {
        return $this->emailaddress;
    }
}
