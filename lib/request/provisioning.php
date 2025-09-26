<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Provides the PROVISIONING command
 */

class Provisioning extends RequestProcessor {
	/**
	 * Handles the Provisioning command.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public function Handle($commandCode) {
		$status = SYNC_PROVISION_STATUS_SUCCESS;
		$policystatus = SYNC_PROVISION_POLICYSTATUS_SUCCESS;

		$rwstatus = GSync::GetProvisioningManager()->GetProvisioningWipeStatus();
		$rwstatusWiped = false;
		$deviceInfoSet = false;
		$wipeRequest = !($rwstatus < SYNC_PROVISION_RWSTATUS_PENDING);

		// if this is a regular provisioning require that an authenticated remote user
		if (!$wipeRequest) {
			SLog::Write(LOGLEVEL_DEBUG, "RequestProcessor::HandleProvision(): Forcing delayed Authentication");
			self::Authenticate();
		}

		$phase2 = true;

		if (!self::$decoder->getElementStartTag(SYNC_PROVISION_PROVISION)) {
			return false;
		}

		// Loop through Provision request tags. Possible are:
		// - Remote Wipe
		// - DeviceInformation
		// - Policies
		// Each of them should only be once per request.
		WBXMLDecoder::ResetInWhile("provisioningMain");
		while (WBXMLDecoder::InWhile("provisioningMain")) {
			$requestName = "";
			if (self::$decoder->getElementStartTag(SYNC_PROVISION_REMOTEWIPE)) {
				$requestName = SYNC_PROVISION_REMOTEWIPE;
			}
			if (self::$decoder->getElementStartTag(SYNC_PROVISION_ACCOUNTONLYREMOTEWIPE)) {
				$requestName = SYNC_PROVISION_ACCOUNTONLYREMOTEWIPE;
			}
			if (self::$decoder->getElementStartTag(SYNC_PROVISION_POLICIES)) {
				$requestName = SYNC_PROVISION_POLICIES;
			}
			if (self::$decoder->getElementStartTag(SYNC_SETTINGS_DEVICEINFORMATION)) {
				$requestName = SYNC_SETTINGS_DEVICEINFORMATION;
			}

			if (!$requestName) {
				break;
			}

			// set is available for OOF, device password and device information
			switch ($requestName) {
				case SYNC_PROVISION_REMOTEWIPE:
				case SYNC_PROVISION_ACCOUNTONLYREMOTEWIPE:
					if (!self::$decoder->getElementStartTag(SYNC_PROVISION_STATUS)) {
						return false;
					}

					$instatus = self::$decoder->getElementContent();

					if (!self::$decoder->getElementEndTag()) {
						return false;
					}

					if (!self::$decoder->getElementEndTag()) {
						return false;
					}

					$phase2 = false;
					$rwstatusWiped = true;
					// TODO check - do it after while(1) finished?
					break;

				case SYNC_PROVISION_POLICIES:
					if (!self::$decoder->getElementStartTag(SYNC_PROVISION_POLICY)) {
						return false;
					}

					if (!self::$decoder->getElementStartTag(SYNC_PROVISION_POLICYTYPE)) {
						return false;
					}

					$policytype = self::$decoder->getElementContent();
					if ($policytype != 'MS-WAP-Provisioning-XML' && $policytype != 'MS-EAS-Provisioning-WBXML') {
						$status = SYNC_PROVISION_STATUS_SERVERERROR;
					}
					if (!self::$decoder->getElementEndTag()) { // policytype
						return false;
					}

					if (self::$decoder->getElementStartTag(SYNC_PROVISION_POLICYKEY)) {
						$devpolicykey = self::$decoder->getElementContent();

						if (!self::$decoder->getElementEndTag()) {
							return false;
						}

						if (!self::$decoder->getElementStartTag(SYNC_PROVISION_STATUS)) {
							return false;
						}

						$instatus = self::$decoder->getElementContent();

						if (!self::$decoder->getElementEndTag()) {
							return false;
						}

						$phase2 = false;
					}

					if (!self::$decoder->getElementEndTag()) { // policy
						return false;
					}

					if (!self::$decoder->getElementEndTag()) { // policies
						return false;
					}
					break;

				case SYNC_SETTINGS_DEVICEINFORMATION:
					// AS14.1 and later clients pass Device Information on the initial Provision request
					if (!self::$decoder->getElementStartTag(SYNC_SETTINGS_SET)) {
						return false;
					}
					$deviceInfoSet = true;
					$deviceinformation = new SyncDeviceInformation();
					$deviceinformation->Decode(self::$decoder);
					$deviceinformation->Status = SYNC_SETTINGSSTATUS_SUCCESS;
					if (!$wipeRequest) {
						// for this operation the device manager is available
						GSync::GetDeviceManager()->SaveDeviceInformation($deviceinformation);
					}
					else {
						SLog::Write(LOGLEVEL_DEBUG, "Ignoring incoming device information as WIPE is due.");
					}
					if (!self::$decoder->getElementEndTag()) {  // SYNC_SETTINGS_SET
						return false;
					}
					if (!self::$decoder->getElementEndTag()) {  // SYNC_SETTINGS_DEVICEINFORMATION
						return false;
					}
					break;

				default:
					// TODO: a special status code needed?
					SLog::Write(LOGLEVEL_WARN, sprintf("This property ('%s') is not allowed to be used in a provision request", $requestName));
			}
		}

		if (!self::$decoder->getElementEndTag()) { // provision
			return false;
		}

		if (PROVISIONING !== true) {
			SLog::Write(LOGLEVEL_INFO, "No policies deployed to device");
			$policystatus = SYNC_PROVISION_POLICYSTATUS_NOPOLICY;
		}

		self::$encoder->StartWBXML();

		// just create a temporary key (i.e. iPhone OS4 Beta does not like policykey 0 in response)
		$policykey = GSync::GetProvisioningManager()->GenerateProvisioningPolicyKey();

		self::$encoder->startTag(SYNC_PROVISION_PROVISION);

		self::$encoder->startTag(SYNC_PROVISION_STATUS);
		self::$encoder->content($status);
		self::$encoder->endTag();

		if ($deviceInfoSet) {
			self::$encoder->startTag(SYNC_SETTINGS_DEVICEINFORMATION);
			self::$encoder->startTag(SYNC_SETTINGS_STATUS);
			self::$encoder->content($deviceinformation->Status);
			self::$encoder->endTag(); // SYNC_SETTINGS_STATUS
			self::$encoder->endTag(); // SYNC_SETTINGS_DEVICEINFORMATION
		}

		self::$encoder->startTag(SYNC_PROVISION_POLICIES);
		self::$encoder->startTag(SYNC_PROVISION_POLICY);

		if (isset($policytype)) {
			self::$encoder->startTag(SYNC_PROVISION_POLICYTYPE);
			self::$encoder->content($policytype);
			self::$encoder->endTag();
		}

		self::$encoder->startTag(SYNC_PROVISION_STATUS);
		self::$encoder->content($policystatus);
		self::$encoder->endTag();

		self::$encoder->startTag(SYNC_PROVISION_POLICYKEY);
		self::$encoder->content($policykey);
		self::$encoder->endTag();

		if ($phase2 && $policystatus === SYNC_PROVISION_POLICYSTATUS_SUCCESS) {
			self::$encoder->startTag(SYNC_PROVISION_DATA);
			if ($policytype == 'MS-WAP-Provisioning-XML') {
				self::$encoder->content('<wap-provisioningdoc><characteristic type="SecurityPolicy"><parm name="4131" value="1"/><parm name="4133" value="1"/></characteristic></wap-provisioningdoc>');
			}
			elseif ($policytype == 'MS-EAS-Provisioning-WBXML') {
				self::$encoder->startTag(SYNC_PROVISION_EASPROVISIONDOC);

				// get the provisioning object and log the loaded policy values
				$prov = GSync::GetProvisioningManager()->GetProvisioningObject(true);
				if (!$prov->Check()) {
					throw new FatalException("Invalid policies!");
				}

				GSync::GetProvisioningManager()->SavePolicyHash($prov);
				$prov->Encode(self::$encoder);
				self::$encoder->endTag();
			}
			else {
				SLog::Write(LOGLEVEL_WARN, "Wrong policy type");
				self::$topCollector->AnnounceInformation("Policytype not supported", true);

				return false;
			}
			self::$topCollector->AnnounceInformation("Updated provisioning", true);

			self::$encoder->endTag(); // data
		}
		self::$encoder->endTag(); // policy
		self::$encoder->endTag(); // policies

		// set the new final policy key in the provisioning manager
		if (!$phase2 && !$wipeRequest) {
			GSync::GetProvisioningManager()->SetProvisioningPolicyKey($policykey);
			self::$topCollector->AnnounceInformation("Policies deployed", true);
		}

		// wipe data if a higher RWSTATUS is requested
		if ($rwstatus > SYNC_PROVISION_RWSTATUS_OK && $policystatus === SYNC_PROVISION_POLICYSTATUS_SUCCESS) {
			if ($rwstatus <= SYNC_PROVISION_RWSTATUS_WIPED) {
				self::$encoder->startTag(SYNC_PROVISION_REMOTEWIPE, false, true);
				GSync::GetProvisioningManager()->SetProvisioningWipeStatus(($rwstatusWiped) ? SYNC_PROVISION_RWSTATUS_WIPED : SYNC_PROVISION_RWSTATUS_REQUESTED);
				self::$topCollector->AnnounceInformation(sprintf("Remote wipe %s", ($rwstatusWiped) ? "executed" : "requested"), true);
			}
			else {
				self::$encoder->startTag(SYNC_PROVISION_ACCOUNTONLYREMOTEWIPE, false, true);
				GSync::GetProvisioningManager()->SetProvisioningWipeStatus(($rwstatusWiped) ? SYNC_PROVISION_RWSTATUS_WIPED_ACCOUNT_ONLY : SYNC_PROVISION_RWSTATUS_REQUESTED_ACCOUNT_ONLY);
				self::$topCollector->AnnounceInformation(sprintf("Account Only Remote wipe %s", ($rwstatusWiped) ? "executed" : "requested"), true);
			}
		}

		self::$encoder->endTag(); // provision

		return true;
	}
}
