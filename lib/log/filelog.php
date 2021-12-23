<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Logging functionalities
 */

class FileLog extends Log {

    /**
     * @var string|bool
     */
    private $log_to_user_file = false;

    /**
     * Constructor
     */
    public function __construct() {
    }

    /**
     * Get the log user file.
     *
     * @access private
     * @return string
     */
    private function getLogToUserFile() {
        if ($this->log_to_user_file === false) {
            if (in_array(strtolower($this->GetDevid()), ['','validate'])) {
                $this->setLogToUserFile(preg_replace('/[^a-z0-9]/', '_', strtolower($this->GetAuthUser())) . '.log');
            }
            else {
                $this->setLogToUserFile(
                        preg_replace('/[^a-z0-9]/', '_', strtolower($this->GetAuthUser())) .'-'.
                        (($this->GetAuthUser() != $this->GetUser()) ? preg_replace('/[^a-z0-9]/', '_', strtolower($this->GetUser())) .'-' : '') .
                        preg_replace('/[^a-z0-9]/', '_', strtolower($this->GetDevid())) .
                        '.log'
                        );
            }
        }
        return $this->log_to_user_file;
    }

    /**
     * Set user log-file relative to log directory.
     *
     * @param string $value
     *
     * @access private
     * @return void
     */
    private function setLogToUserFile($value) {
        $this->log_to_user_file = $value;
    }

    /**
     * Returns the string to be logged.
     *
     * @param int       $loglevel
     * @param string    $message
     * @param boolean   $includeUserDevice  puts username and device in the string, default: true
     *
     * @access public
     * @return string
     */
    public function BuildLogString($loglevel, $message, $includeUserDevice = true) {
        $log = Utils::GetFormattedTime() .' ['. str_pad($this->GetPid(),5," ",STR_PAD_LEFT) .'] '. $this->GetLogLevelString($loglevel, $loglevel >= LOGLEVEL_INFO);

        if ($includeUserDevice) {
            // when the users differ, we need to log both
            if (strcasecmp($this->GetAuthUser(), $this->GetUser()) == 0) {
                $log .= ' ['. $this->GetUser() .']';
            }
            else {
                $log .= ' ['. $this->GetAuthUser() . Request::IMPERSONATE_DELIM . $this->GetUser() .']';
            }
        }
        if ($includeUserDevice && (LOGLEVEL >= LOGLEVEL_DEVICEID || (LOGUSERLEVEL >= LOGLEVEL_DEVICEID && $this->IsAuthUserInSpecialLogUsers()))) {
            $log .= ' ['. $this->GetDevid() .']';
        }
        $log .= ' ' . $message;
        return $log;
    }

    //
    // Implementation of Log
    //

    /**
     * Writes a log message to the general log.
     *
     * @param int $loglevel
     * @param string $message
     *
     * @access protected
     * @return void
     */
    protected function Write($loglevel, $message) {
        $data = $this->BuildLogString($loglevel, $message) . PHP_EOL;
        @file_put_contents(LOGFILE, $data, FILE_APPEND);
    }

    /**
     * Writes a log message to the user specific log.
     * @param int $loglevel
     * @param string $message
     *
     * @access public
     * @return void
     */
    public function WriteForUser($loglevel, $message) {
        $data = $this->BuildLogString($loglevel, $message, false) . PHP_EOL;
        @file_put_contents(LOGFILEDIR . $this->getLogToUserFile(), $data, FILE_APPEND);
    }

    /**
     * This function is used as an event for log implementer.
     * It happens when the a call to the Log function is finished.
     *
     * @access protected
     * @return void
     */
    protected function afterLog($loglevel, $message) {
        if ($loglevel & (LOGLEVEL_FATAL | LOGLEVEL_ERROR | LOGLEVEL_WARN)) {
            $data = $this->BuildLogString($loglevel, $message) . PHP_EOL;
            @file_put_contents(LOGERRORFILE, $data, FILE_APPEND);
        }
    }
}
