<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2021 grommunio GmbH
 *
 * Bundled Provisioning code.
 */

class ProvisioningManager extends InterProcessData {
	public const KEY_POLICYKEY = "policykey";
	public const KEY_POLICYHASH = "policyhash";
	public const KEY_UPDATETIME = "updatetime";

	private $policies = [];
	private $policyKey = ASDevice::UNDEFINED;
	private $policyHash = ASDevice::UNDEFINED;
	private $updatetime = 0;
	private $loadtime = 0;
	private $typePolicyCacheId = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// initialize super parameters
		$this->allocate = 0;
		$this->type = "grommunio-sync:provisioningcache";
		parent::__construct();
		// initialize params
		$this->initializeParams();

		$this->typePolicyCacheId = sprintf("grommunio-sync:policycache-%s", self::$user);

		// Remote wipe requested ?
		// If there is an entry in the provisioningcache for this user+device we assume that there is **NO** remote wipe requested.
		// If a device is to be remotewipe'd, the entry from the provisioningcache will be removed by the admin api.
		// This will trigger a provisioning operation and retrieve the status via GetProvisioningWipeStatus().

		// get provisioning data from redis
		$p = $this->getData($this->typePolicyCacheId);
		if (!empty($p)) {
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
				$policies = [];
			}
			$this->policies = $policies;
			// cache policies for 24h
			$this->setData($this->policies, $this->typePolicyCacheId, 3600 * 24);
		}

		// get policykey and hash
		$this->loadPolicyCache();
	}

	private function loadPolicyCache() {
		if ($this->loadtime + 29 > time()) {
			return;
		}
		// get provisioning data from redis
		$d = $this->getDeviceUserData($this->type, self::$devid, self::$user);
		if (!empty($d)) {
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
		$p = [];
		$p[self::KEY_POLICYKEY] = $this->policyKey;
		$p[self::KEY_POLICYHASH] = $this->policyHash;
		$p[self::KEY_UPDATETIME] = time();

		return $this->setDeviceUserData($this->type, $p, self::$devid, self::$user);
	}

	/**
	 * Checks if the sent policykey matches the latest policykey
	 * saved for the device.
	 *
	 * @param string $policykey
	 * @param bool   $noDebug       (opt) by default, debug message is shown
	 * @param bool   $checkPolicies (opt) by default check if the provisioning policies changed
	 *
	 * @return bool
	 */
	public function ProvisioningRequired($policykey, $noDebug = false, $checkPolicies = true) {
		// get latest policykey and hash
		$this->loadPolicyCache();

		// check if policiykey matches
		$p = (($policykey !== ASDevice::UNDEFINED && $policykey != $this->policyKey) || $this->policyKey == ASDevice::UNDEFINED);

		if (!$noDebug || $p) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("ProvisioningManager->ProvisioningRequired('%s') saved device key '%s': %s", $policykey, $this->policyKey, Utils::PrintAsString($p)));
		}

		if ($checkPolicies) {
			$policyHash = $this->GetProvisioningObject()->GetPolicyHash();
			if ($this->policyHash !== ASDevice::UNDEFINED && $this->policyHash != $policyHash) {
				$p = true;
				SLog::Write(LOGLEVEL_INFO, sprintf("ProvisioningManager->ProvisioningRequired(): saved policy hash '%s' changed to '%s'. Provisioning required.", $this->policyHash, $policyHash));
			}
			elseif (!$noDebug) {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("ProvisioningManager->ProvisioningRequired() saved policy hash '%s' matches", $policyHash));
			}
		}

		return $p;
	}

	/**
	 * Generates a new Policykey.
	 *
	 * @return int
	 */
	public function GenerateProvisioningPolicyKey() {
		return mt_rand(100000000, 999999999);
	}

	/**
	 * Attributes a provisioned policykey to a device.
	 *
	 * @param int $policykey
	 *
	 * @return bool status
	 */
	public function SetProvisioningPolicyKey($policykey) {
		SLog::Write(LOGLEVEL_DEBUG, sprintf("ProvisioningManager->SetPolicyKey('%s')", $policykey));
		$this->policyKey = $policykey;
		$this->updatePolicyCache();

		// tell the Admin API that the policies were successfully deployed
		return $this->SetProvisioningWipeStatus(SYNC_PROVISION_RWSTATUS_OK);
	}

	/**
	 * Builds a Provisioning SyncObject with policies.
	 *
	 * @param bool $logPolicies optional, determines if the policies and values should be logged. Default: false
	 *
	 * @return SyncProvisioning
	 */
	public function GetProvisioningObject($logPolicies = false) {
		return SyncProvisioning::GetObjectWithPolicies($this->policies, $logPolicies);
	}

	/**
	 * Returns the status of the remote wipe policy.
	 *
	 * @return int returns the current status of the device - SYNC_PROVISION_RWSTATUS_*
	 */
	public function GetProvisioningWipeStatus() {
		$status = SYNC_PROVISION_RWSTATUS_NA;

		// retrieve the WIPE STATUS from the Admin API
		$api_response = file_get_contents(ADMIN_API_WIPE_ENDPOINT . self::$user . "?devices=" . self::$devid);
		if ($api_response) {
			$data = json_decode($api_response, true);
			if (isset($data['data'][self::$devid]["status"])) {
				$status = $data['data'][self::$devid]["status"];
				// reset status to pending if it was already executed
				if ($status >= SYNC_PROVISION_RWSTATUS_PENDING) {
					if ($status < SYNC_PROVISION_RWSTATUS_PENDING_ACCOUNT_ONLY) {
						$status = SYNC_PROVISION_RWSTATUS_PENDING;
						SLog::Write(LOGLEVEL_INFO, sprintf("ProvisioningManager->GetProvisioningWipeStatus(): REMOTE WIPE due for user '%s' on device '%s' - status: '%s'", self::$user, self::$devid, $status));
					}
					else {
						$status = SYNC_PROVISION_RWSTATUS_PENDING_ACCOUNT_ONLY;
						SLog::Write(LOGLEVEL_INFO, sprintf("ProvisioningManager->GetProvisioningWipeStatus(): ACCOUNT ONLY REMOTE WIPE due for user '%s' on device '%s' - status: '%s'", self::$user, self::$devid, $status));
					}
				}
				else {
					SLog::Write(LOGLEVEL_INFO, sprintf("ProvisioningManager->GetProvisioningWipeStatus(): no remote wipe pending - status: '%s'", $status));
				}
			}
		}

		return $status;
	}

	/**
	 * Updates the status of the remote wipe.
	 *
	 * @param int $status - SYNC_PROVISION_RWSTATUS_*
	 *
	 * @return bool could fail if trying to update status to a wipe status which was not requested before
	 */
	public function SetProvisioningWipeStatus($status) {
		SLog::Write(LOGLEVEL_DEBUG, sprintf("ProvisioningManager->SetProvisioningWipeStatus() set to '%d'", $status));
		$opts = ['http' => [
			'method' => 'POST',
			'header' => 'Content-Type: application/json',
			'ignore_errors' => true,
			'content' => json_encode(
				[
					'remoteIP' => Request::GetRemoteAddr(),
					'status' => $status,
					'time' => time(),
				]
			),
		],
		];
		$ret = file_get_contents(ADMIN_API_WIPE_ENDPOINT . self::$user . "?devices=" . self::$devid, false, stream_context_create($opts));
		SLog::Write(LOGLEVEL_DEBUG, sprintf("ProvisioningManager->SetProvisioningWipeStatus() admin API response: %s", trim(Utils::PrintAsString($ret))));

		return strpos($http_response_header[0], "201") !== false;
	}

	/**
	 * Saves the policy hash and name in device's state.
	 *
	 * @param SyncProvisioning $provisioning
	 */
	public function SavePolicyHash($provisioning) {
		// save policies' hash
		$this->policyHash = $provisioning->GetPolicyHash();
		$this->updatePolicyCache();

		SLog::Write(LOGLEVEL_DEBUG, sprintf("ProvisioningManager->SavePolicyHash(): Set policy with hash: %s", $this->policyHash));
	}
}
