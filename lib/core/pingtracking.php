<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 */

class PingTracking extends InterProcessData {

    /**
     * Constructor
     *
     * @access public
     */
    public function __construct() {
        // initialize super parameters
        $this->allocate = 0;
        $this->type = "grommunio-sync:pingtracking";
        parent::__construct();

        $this->initPing();
    }

    /**
     * Destructor
     * Used to remove the current ping data from shared memory
     *
     * @access public
     */
    public function __destruct() {
        return $this->setDeviceUserData($this->type, array("pid:".self::$pid), self::$devid, self::$user, $subkey=-1, $doCas="deletekeys");
    }

    /**
     * Initialized the current request
     *
     * @access public
     * @return boolean
     */
    protected function initPing() {
        // initialize params
        $this->initializeParams();
        // need microtime as connections sometimes start at the same second
        self::$start = microtime(true);
        $pingtracking = array("pid:".self::$pid => self::$start);
        return $this->setDeviceUserData($this->type, $pingtracking, self::$devid, self::$user, $subkey=-1, $doCas="merge");
    }

    /**
     * Checks if there are newer ping requests for the same device & user so
     * the current process could be terminated
     *
     * @access public
     * @return boolean      true if the current process is obsolete
     */
    public function DoForcePingTimeout() {
        $pings = $this->getDeviceUserData($this->type, self::$devid, self::$user);
        // check if there is another (and newer) active ping connection
        if (count($pings) > 1) {
            foreach ($pings as $pid=>$starttime)
                if ($starttime > self::$start)
                    return true;
        }

        return false;
    }
}
