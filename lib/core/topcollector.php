<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * Available everywhere to collect data which could be displayed in
 * grammm-sync-top the 'persistent' flag should be used with care, so
 * there is not too much information
 */

class TopCollector extends InterProcessData {
    const ENABLEDAT = "grammm-sync:topenabledat";
    const TOPDATA = "grammm-sync:topdata";
    const ENABLED_CACHETIME = 5; // how often in seconds to check the ipc provider if it has data for the TopCollector

    protected $preserved;
    protected $latest;
    private $disabled;
    private $checkEnabledTime;
    private $enabled;

    /**
     * Constructor
     *
     * @access public
     */
    public function __construct() {
        // initialize super parameters
        $this->allocate = 2097152; // 2 MB
        $this->type = 20;
        parent::__construct();

        // initialize params
        $this->initializeParams();

        $this->preserved = array();
        // static vars come from the parent class
        $this->latest = array(  "pid"       => self::$pid,
                                "ip"        => Request::GetRemoteAddr(),
                                "user"      => self::$user,
                                "start"     => self::$start,
                                "devtype"   => Request::GetDeviceType(),
                                "devid"     => self::$devid,
                                "devagent"  => Request::GetUserAgent(),
                                "command"   => Request::GetCommandCode(),
                                "ended"     => 0,
                                "push"      => false,
                        );
        $this->disabled = !!(defined('TOPCOLLECTOR_DISABLED') && constant('TOPCOLLECTOR_DISABLED') === true);
        $this->checkEnabledTime = time() - self::ENABLED_CACHETIME - 1;
        $this->AnnounceInformation("initializing");
    }

    /**
     * Destructor
     * indicates that the process is shutting down
     *
     * @access public
     */
    public function __destruct() {
        $this->AnnounceInformation("OK", false, true);
    }

    /**
     * Advices all other processes that they should start/stop
     * collecting data. The data saved is a timestamp. It has to be
     * reactivated every couple of seconds
     *
     * @param boolean   $stop       (opt) default false (do collect)
     *
     * @access public
     * @return boolean  indicating if it was set to collect before
     */
    public function CollectData($stop = false) {
        $wasEnabled = ($this->hasData(self::ENABLEDAT)) ? $this->getData(self::ENABLEDAT) : false;

        $time = time();
        if ($stop === true) $time = 0;

        if (! $this->setData($time, self::ENABLEDAT))
            return false;

        return $wasEnabled;
    }

    /**
     * Announces a string to the TopCollector
     *
     * @param string    $info
     * @param boolean   $preserve       info should be displayed when process terminates
     * @param boolean   $terminating    indicates if the process is terminating
     *
     * @access public
     * @return boolean
     */
    public function AnnounceInformation($addinfo, $preserve = false, $terminating = false) {
        if ($this->disabled) {
            return true;
        }

        $this->latest["addinfo"] = $addinfo;
        $this->latest["update"] = time();

        if ($terminating) {
            $this->latest["ended"] = time();
            foreach ($this->preserved as $p)
                $this->latest["addinfo"] .= " : ".$p;
        }

        if ($preserve)
            $this->preserved[] = $addinfo;

        if ($this->isEnabled()) {
            // use the pid as subkey
            $ok = $this->setDeviceUserData(self::TOPDATA, $this->latest, self::$devid, self::$user, self::$pid);
            if (!$ok) {
                ZLog::Write(LOGLEVEL_WARN, "TopCollector::AnnounceInformation(): could not write to redis. grammm-sync top will not display this data.");
                return false;
            }
        }
        return true;
    }

    /**
     * Returns all available top data
     *
     * @access public
     * @return array
     */
    public function ReadLatest() {
        return $this->getAllDeviceUserData(self::TOPDATA);
    }

    /**
     * Cleans up data collected so far
     *
     * @param boolean   $all        (optional) if set all data independently from the age is removed
     *
     * @access public
     * @return boolean  status
     */
    public function ClearLatest($all = false) {
        // it's ok when doing this every 10 sec
        if ($all == false && time() % 10 != 0 )
            return true;

        if ($all == true) {
            $this->getRedis()->delKey(self::TOPDATA);
        }
        else {
            foreach ($this->getRawDeviceUserData(self::TOPDATA) as $compKey => $rawline) {
                $line = json_decode($rawline, true);
                // remove everything which terminated for 20 secs or is not updated for more than 120 secs
                if (($line["ended"] != 0 && time() - $line["ended"] > 20) ||
                    time() - $line["update"] > 120) {
                        $this->getRedis()->get()->hDel(self::TOPDATA, $compKey);
                }
            }
        }

        return true;
    }

    /**
     * Sets a different UserAgent for this connection
     *
     * @param string    $agent
     *
     * @access public
     * @return boolean
     */
    public function SetUserAgent($agent) {
        $this->latest["devagent"] = $agent;
        return true;
    }

    /**
     * Marks this process as push connection
     *
     * @param string    $agent
     *
     * @access public
     * @return boolean
     */
    public function SetAsPushConnection() {
        $this->latest["push"] = true;
        return true;
    }

    /**
     * Reinitializes the IPC data.
     *
     * @access public
     * @return boolean
     */
    public function ReInitIPC() {
        $status = parent::ReInitIPC();
        return $status;
        if (!status) {
            $this->getRedis()->delKey(self::TOPDATA);
        }
        return $status;
    }

    /**
     * Indicates if top data should be saved or not
     * Returns true for 10 seconds after the latest CollectData()
     * SHOULD only be called with locked mutex!
     *
     * @access private
     * @return boolean
     */
    private function isEnabled() {
        $time = time();
        if ($this->checkEnabledTime + self::ENABLED_CACHETIME < $time) {
            $isEnabled = ($this->hasData(self::ENABLEDAT)) ? $this->getData(self::ENABLEDAT) : false;
            $this->enabled = ($isEnabled !== false && ($isEnabled +300) > $time);
            $this->checkEnabledTime = $time;
        }
        return $this->enabled;
    }


}
