<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2021 grammm GmbH
 *
 * Bundled Provisioning code.
 */

class ProvisioningManager extends InterProcessData {
    const KEY_POLICYKEY = "policykey";
    const KEY_POLICYHASH = "policyhash";
    const KEY_UPDATETIME = "updatetime";

    private $policies = array();
    private $policyKey = ASDevice::UNDEFINED;
    private $policyHash = ASDevice::UNDEFINED;
    private $updatetime = 0;
    private $loadtime = 0;

    private $deviceManager = false;
    
    /**
     * Constructor
     *
     * @access public
     */
    public function __construct() {
        // initialize super parameters
        $this->allocate = 0;
        $this->type = "grammm-sync:provisioningcache";
        parent::__construct();
        // initialize params
        $this->initializeParams();
        
        $this->typePolicyCacheId = sprintf("grammm-sync:policycache-%s", self::$user);

        // TODO: check wipe is requested

        // get provisioning data from redis
        $p = $this->getData($this->typePolicyCacheId);
        if (! empty($p)) {
            $this->policies = $p;
        }
        // no policies cached in redis, get policies from admin API
        else {
            $policies = false;
            $api_response = file_get_contents(ADMIN_API_POLICY_ENDPOINT . self::$user);
            if ($api_response) {
                $data = json_decode($api_response);
                if (isset($data->data)) {
                    $policies = $data->data;
                }
            }

            // failed to retrieve: use default "empty" policy
            if (!$policies) {
                // failed to retrieve: use default "empty" policy
                $this->policies = array();
            }
            $this->policies = $policies;
            // cache policies for 24h
            $this->setData($this->policies, $this->typePolicyCacheId, 3600*24);
        }

        // get policykey and hash
        $this->loadPolicyCache();
    }

    private function loadPolicyCache() {
        if ($this->loadtime+29 > time()) {
            return;
        }
        // get provisioning data from redis
        $d = $this->getDeviceUserData($this->type, self::$devid, self::$user);
        if (! empty($d)) {
            $this->policyKey = $d[self::KEY_POLICYKEY];
            $this->policyHash = $d[self::KEY_POLICYHASH];
            $this->updatetime = $d[self::KEY_UPDATETIME];
            $this->loadtime = time();
        }
        else {
            $this->policyKey = ASDevice::UNDEFINED;
            $this->policyHash = ASDevice::UNDEFINED;
            $this->updatetime = 0;
            $this->loadtime = time();
        }
    }

    private function updatePolicyCache() {
        $p = array();
        $p[self::KEY_POLICYKEY] = $this->policyKey;
        $p[self::KEY_POLICYHASH] = $this->policyHash;
        $p[self::KEY_UPDATETIME] = time();
        return $this->setDeviceUserData($this->type, $p, self::$devid, self::$user);
    }

    /**
     * Checks if the sent policykey matches the latest policykey
     * saved for the device
     *
     * @param string        $policykey
     * @param boolean       $noDebug        (opt) by default, debug message is shown
     * @param boolean       $checkPolicies  (opt) by default check if the provisioning policies changed
     *
     * @access public
     * @return boolean
     */
    public function ProvisioningRequired($policykey, $noDebug = false, $checkPolicies = true) {
        // get latest policykey and hash
        $this->loadPolicyCache();

        // check if policiykey matches
        $p = ( ($policykey !== ASDevice::UNDEFINED && $policykey != $this->policyKey) || $this->policyKey == ASDevice::UNDEFINED );

        if (!$noDebug || $p)
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ProvisioningManager->ProvisioningRequired('%s') saved device key '%s': %s", $policykey, $this->policyKey, Utils::PrintAsString($p)));

        if ($checkPolicies) {
            $policyHash = $this->GetProvisioningObject()->GetPolicyHash();
            if ($this->policyHash !== ASDevice::UNDEFINED && $this->policyHash != $policyHash) {
                $p = true;
                ZLog::Write(LOGLEVEL_INFO, sprintf("ProvisioningManager->ProvisioningRequired(): saved policy hash '%s' changed to '%s'. Provisioning required.", $this->policyHash, $policyHash));
            }
            elseif (!$noDebug) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("ProvisioningManager->ProvisioningRequired() saved policy hash '%s' matches", $policyHash));
            }
        }

        return $p;
    }


   /**
     * Generates a new Policykey
     *
     * @access public
     * @return int
     */
    public function GenerateProvisioningPolicyKey() {
        return mt_rand(100000000, 999999999);
    }

    /**
     * Attributes a provisioned policykey to a device
     *
     * @param int           $policykey
     *
     * @access public
     * @return boolean      status
     */
    public function SetProvisioningPolicyKey($policykey) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->SetPolicyKey('%s')", $policykey));
        $this->policyKey = $policykey;
        $this->updatePolicyCache();
        return true;
    }

    /**
     * Builds a Provisioning SyncObject with policies
     *
     * @param boolean   $logPolicies  optional, determines if the policies and values should be logged. Default: false
     *
     * @access public
     * @return SyncProvisioning
     */
    public function GetProvisioningObject($logPolicies = false) {
        return SyncProvisioning::GetObjectWithPolicies($this->policies, $logPolicies);
    }

    /**
     * Returns the status of the remote wipe policy
     *
     * @access public
     * @return int          returns the current status of the device - SYNC_PROVISION_RWSTATUS_*
     */
    public function GetProvisioningWipeStatus() {
        return SYNC_PROVISION_RWSTATUS_NA;
    }

    /**
     * Updates the status of the remote wipe
     *
     * @param int           $status - SYNC_PROVISION_RWSTATUS_*
     *
     * @access public
     * @return boolean      could fail if trying to update status to a wipe status which was not requested before
     */
    public function SetProvisioningWipeStatus($status) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ProvisioningManager->SetProvisioningWipeStatus() change from '%d' to '%d'", $this->wipeStatus, $status));

        if ($status > SYNC_PROVISION_RWSTATUS_OK && !($this->wipeStatus > SYNC_PROVISION_RWSTATUS_OK)) {
            ZLog::Write(LOGLEVEL_ERROR, "Not permitted to update remote wipe status to a higher value as remote wipe was not initiated!");
            return false;
        }

        // TODO: implement saving wipe status
        return true;
    }

    /**
     * Saves the policy hash and name in device's state.
     *
     * @param SyncProvisioning  $provisioning
     *
     * @access public
     * @return void
     */
    public function SavePolicyHash($provisioning) {
        // save policies' hash
        $this->policyHash = $provisioning->GetPolicyHash();
        $this->updatePolicyCache();
        
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ProvisioningManager->SavePolicyHash(): Set policy with hash: %s", $this->policyHash));
    }

}