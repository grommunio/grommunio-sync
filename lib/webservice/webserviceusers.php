<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * Device remote administration tasks used over webservice related to
 * grammm-sync users.
 */
include ('lib/utils/zpushadmin.php');

class WebserviceUsers {

    /**
     * Returns a list of all known devices
     *
     * @access public
     * @return array
     */
    public function ListDevices() {
        return ZPushAdmin::ListDevices(false);
    }

    /**
     * Returns a list of all known devices of the users
     *
     * @access public
     * @return array
     */
    public function ListDevicesAndUsers() {
        $devices = ZPushAdmin::ListDevices(false);
        $output = array();

        ZLog::Write(LOGLEVEL_INFO, sprintf("WebserviceUsers::ListDevicesAndUsers(): found %d devices", count($devices)));
        ZPush::GetTopCollector()->AnnounceInformation(sprintf("Retrieved details of %d devices and getting users", count($devices)), true);

        foreach ($devices as $devid)
            $output[$devid] = ZPushAdmin::ListUsers($devid);

        return $output;
    }

    /**
     * Returns a list of all known devices with users and when they synchronized for the first time
     *
     * @access public
     * @return array
     */
    public function ListDevicesDetails() {
        $devices = ZPushAdmin::ListDevices(false);
        $output = array();

        ZLog::Write(LOGLEVEL_INFO, sprintf("WebserviceUsers::ListLastSync(): found %d devices", count($devices)));
        ZPush::GetTopCollector()->AnnounceInformation(sprintf("Retrieved details of %d devices and getting users", count($devices)), true);

        foreach ($devices as $deviceId) {
            $output[$deviceId] = array();
            $users = ZPushAdmin::ListUsers($deviceId);
            foreach ($users as $user) {
                $output[$deviceId][$user] = ZPushAdmin::GetDeviceDetails($deviceId, $user);
            }
        }


        return $output;
    }
}
