<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * Implements a simple mutex using InterProcessData
 */

class SimpleMutex extends InterProcessData {
    /**
     * Constructor
     */
    public function __construct() {
        // initialize super parameters
        $this->allocate = 64;
        $this->type = 5173;
        parent::__construct();

        if (!$this->IsActive()) {
            ZLog::Write(LOGLEVEL_ERROR, "SimpleMutex not available as InterProcessData is not available. This is not recommended on duty systems and may result in corrupt user/device linking.");
        }
    }

    /**
     * Blocks the mutex.
     * Method blocks until mutex is available!
     * ATTENTION: make sure that you *always* release a blocked mutex!
     *
     * @access public
     * @return boolean
     */
    public function Block() {
        if ($this->IsActive())
            return $this->blockMutex();

        ZLog::Write(LOGLEVEL_WARN, "Could not enter mutex as InterProcessData is not available. This is not recommended on duty systems and may result in corrupt user/device linking!");
        return true;
    }

    /**
     * Releases the mutex
     * After the release other processes are able to block the mutex themselves.
     *
     * @access public
     * @return boolean
     */
    public function Release() {
        if ($this->IsActive())
            return $this->releaseMutex();

        return true;
    }
}
