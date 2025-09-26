<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML AS12+ provisionign entities that can be parsed directly (as a stream)
 * from WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncProvisioning extends SyncObject {
	// AS 12.0, 12.1 and 14.0 props
	public $devpwenabled;
	public $alphanumpwreq;
	public $reqstoragecardenc;
	public $pwrecoveryenabled;
	public $docbrowseenabled;
	public $attenabled;
	public $mindevpwlenngth;
	public $maxinacttimedevlock;
	public $maxdevpwfailedattempts;
	public $maxattsize;
	public $allowsimpledevpw;
	public $devpwexpiration;
	public $devpwhistory;

	// AS 12.1 and 14.0 props
	public $allowstoragecard;
	public $allowcam;
	public $reqdevenc;
	public $allowunsignedapps;
	public $allowunsigninstallpacks;
	public $mindevcomplexchars;
	public $allowwifi;
	public $allowtextmessaging;
	public $allowpopimapemail;
	public $allowbluetooth;
	public $allowirda;
	public $reqmansyncroam;
	public $allowdesktopsync;
	public $maxcalagefilter;
	public $allowhtmlemail;
	public $maxemailagefilter;
	public $maxemailbodytruncsize;
	public $maxemailhtmlbodytruncsize;
	public $reqsignedsmimemessages;
	public $reqencsmimemessages;
	public $reqsignedsmimealgorithm;
	public $reqencsmimealgorithm;
	public $allowsmimeencalgneg;
	public $allowsmimesoftcerts;
	public $allowbrowser;
	public $allowconsumeremail;
	public $allowremotedesk;
	public $allowinternetsharing;
	public $unapprovedinromapplist;
	public $approvedapplist;

	public function __construct() {
		$mapping = [
			SYNC_PROVISION_DEVPWENABLED => [
				self::STREAMER_VAR => "devpwenabled",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_PROVISION_ALPHANUMPWREQ => [
				self::STREAMER_VAR => "alphanumpwreq",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_PROVISION_PWRECOVERYENABLED => [
				self::STREAMER_VAR => "pwrecoveryenabled",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_PROVISION_DOCBROWSEENABLED => [self::STREAMER_VAR => "docbrowseenabled"],
			SYNC_PROVISION_ATTENABLED => [
				self::STREAMER_VAR => "attenabled",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_PROVISION_MINDEVPWLENGTH => [
				self::STREAMER_VAR => "mindevpwlenngth",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_CMPHIGHER => 0,
					self::STREAMER_CHECK_CMPLOWER => 17,
				],
			],
			SYNC_PROVISION_MAXINACTTIMEDEVLOCK => [self::STREAMER_VAR => "maxinacttimedevlock"],
			SYNC_PROVISION_MAXDEVPWFAILEDATTEMPTS => [
				self::STREAMER_VAR => "maxdevpwfailedattempts",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_CMPHIGHER => 3,
					self::STREAMER_CHECK_CMPLOWER => 17,
				],
			],
			SYNC_PROVISION_MAXATTSIZE => [
				self::STREAMER_VAR => "maxattsize",
				self::STREAMER_PROP => self::STREAMER_TYPE_SEND_EMPTY,
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_CMPHIGHER => -1],
			],
			SYNC_PROVISION_ALLOWSIMPLEDEVPW => [
				self::STREAMER_VAR => "allowsimpledevpw",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_PROVISION_DEVPWEXPIRATION => [
				self::STREAMER_VAR => "devpwexpiration",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_CMPHIGHER => -1],
			],
			SYNC_PROVISION_DEVPWHISTORY => [
				self::STREAMER_VAR => "devpwhistory",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_CMPHIGHER => -1],
			],
		];

		if (Request::GetProtocolVersion() >= 12.1) {
			$mapping += [
				SYNC_PROVISION_ALLOWSTORAGECARD => [
					self::STREAMER_VAR => "allowstoragecard",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_ALLOWCAM => [
					self::STREAMER_VAR => "allowcam",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_REQDEVENC => [
					self::STREAMER_VAR => "reqdevenc",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_ALLOWUNSIGNEDAPPS => [
					self::STREAMER_VAR => "allowunsignedapps",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_ALLOWUNSIGNEDINSTALLATIONPACKAGES => [
					self::STREAMER_VAR => "allowunsigninstallpacks",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_MINDEVPWCOMPLEXCHARS => [
					self::STREAMER_VAR => "mindevcomplexchars",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [1, 2, 3, 4]],
				],
				SYNC_PROVISION_ALLOWWIFI => [
					self::STREAMER_VAR => "allowwifi",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_ALLOWTEXTMESSAGING => [
					self::STREAMER_VAR => "allowtextmessaging",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_ALLOWPOPIMAPEMAIL => [
					self::STREAMER_VAR => "allowpopimapemail",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_ALLOWBLUETOOTH => [
					self::STREAMER_VAR => "allowbluetooth",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2]],
				],
				SYNC_PROVISION_ALLOWIRDA => [
					self::STREAMER_VAR => "allowirda",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_REQMANUALSYNCWHENROAM => [
					self::STREAMER_VAR => "reqmansyncroam",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_ALLOWDESKTOPSYNC => [
					self::STREAMER_VAR => "allowdesktopsync",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_MAXCALAGEFILTER => [
					self::STREAMER_VAR => "maxcalagefilter",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 4, 5, 6, 7]],
				],
				SYNC_PROVISION_ALLOWHTMLEMAIL => [
					self::STREAMER_VAR => "allowhtmlemail",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_MAXEMAILAGEFILTER => [
					self::STREAMER_VAR => "maxemailagefilter",
					self::STREAMER_CHECKS => [
						self::STREAMER_CHECK_CMPHIGHER => -1,
						self::STREAMER_CHECK_CMPLOWER => 6,
					],
				],
				SYNC_PROVISION_MAXEMAILBODYTRUNCSIZE => [
					self::STREAMER_VAR => "maxemailbodytruncsize",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_CMPHIGHER => -2],
				],
				SYNC_PROVISION_MAXEMAILHTMLBODYTRUNCSIZE => [
					self::STREAMER_VAR => "maxemailhtmlbodytruncsize",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_CMPHIGHER => -2],
				],
				SYNC_PROVISION_REQSIGNEDSMIMEMESSAGES => [
					self::STREAMER_VAR => "reqsignedsmimemessages",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_REQENCSMIMEMESSAGES => [
					self::STREAMER_VAR => "reqencsmimemessages",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_REQSIGNEDSMIMEALGORITHM => [
					self::STREAMER_VAR => "reqsignedsmimealgorithm",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_REQENCSMIMEALGORITHM => [
					self::STREAMER_VAR => "reqencsmimealgorithm",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2, 3, 4]],
				],
				SYNC_PROVISION_ALLOWSMIMEENCALGORITHNEG => [
					self::STREAMER_VAR => "allowsmimeencalgneg",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2]],
				],
				SYNC_PROVISION_ALLOWSMIMESOFTCERTS => [
					self::STREAMER_VAR => "allowsmimesoftcerts",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_ALLOWBROWSER => [
					self::STREAMER_VAR => "allowbrowser",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_ALLOWCONSUMEREMAIL => [
					self::STREAMER_VAR => "allowconsumeremail",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_ALLOWREMOTEDESKTOP => [
					self::STREAMER_VAR => "allowremotedesk",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_ALLOWINTERNETSHARING => [
					self::STREAMER_VAR => "allowinternetsharing",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
				SYNC_PROVISION_UNAPPROVEDINROMAPPLIST => [
					self::STREAMER_VAR => "unapprovedinromapplist",
					self::STREAMER_PROP => self::STREAMER_TYPE_SEND_EMPTY,
					self::STREAMER_ARRAY => SYNC_PROVISION_APPNAME,
				],  // TODO check
				SYNC_PROVISION_APPROVEDAPPLIST => [
					self::STREAMER_VAR => "approvedapplist",
					self::STREAMER_PROP => self::STREAMER_TYPE_SEND_EMPTY,
					self::STREAMER_ARRAY => SYNC_PROVISION_HASH,
				], // TODO check
				SYNC_PROVISION_REQSTORAGECARDENC => [
					self::STREAMER_VAR => "reqstoragecardenc",
					self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
				],
			];
		}

		parent::__construct($mapping);
	}

	/**
	 * Loads provisioning policies into a SyncProvisioning object.
	 *
	 * @param array $policies    array with policies' names and values
	 * @param bool  $logPolicies optional, determines if the policies and values should be logged. Default: false
	 */
	public function Load($policies = [], $logPolicies = false) {
		$this->LoadDefaultPolicies();

		$streamerVars = $this->GetStreamerVars();
		foreach ($policies as $p => $v) {
			if (!in_array($p, $streamerVars)) {
				SLog::Write(LOGLEVEL_INFO, sprintf("Policy '%s' not supported by the device, ignoring", $p));

				continue;
			}
			if ($logPolicies) {
				SLog::Write(LOGLEVEL_WBXML, sprintf("Policy '%s' enforced with: %s (%s)", $p, (is_array($v)) ? Utils::PrintAsString(implode(',', $v)) : Utils::PrintAsString($v), gettype($v)));
			}
			$this->{$p} = (is_array($v) && empty($v)) ? [] : $v;
		}
	}

	/**
	 * Loads default policies' values into a SyncProvisioning object.
	 */
	public function LoadDefaultPolicies() {
		// AS 12.0, 12.1 and 14.0 props
		$this->devpwenabled = 0;
		$this->alphanumpwreq = 0;
		$this->reqstoragecardenc = 0;
		$this->pwrecoveryenabled = 0;
		$this->attenabled = 1;
		$this->mindevpwlenngth = 4;
		$this->maxinacttimedevlock = 900;
		$this->maxdevpwfailedattempts = 8;
		$this->maxattsize = '';
		$this->allowsimpledevpw = 1;
		$this->devpwexpiration = 0;
		$this->devpwhistory = 0;

		// AS 12.1 and 14.0 props
		$this->allowstoragecard = 1;
		$this->allowcam = 1;
		$this->reqdevenc = 0;
		$this->allowunsignedapps = 1;
		$this->allowunsigninstallpacks = 1;
		$this->mindevcomplexchars = 3;
		$this->allowwifi = 1;
		$this->allowtextmessaging = 1;
		$this->allowpopimapemail = 1;
		$this->allowbluetooth = 2;
		$this->allowirda = 1;
		$this->reqmansyncroam = 0;
		$this->allowdesktopsync = 1;
		$this->maxcalagefilter = 0;
		$this->allowhtmlemail = 1;
		$this->maxemailagefilter = 0;
		$this->maxemailbodytruncsize = -1;
		$this->maxemailhtmlbodytruncsize = -1;
		$this->reqsignedsmimemessages = 0;
		$this->reqencsmimemessages = 0;
		$this->reqsignedsmimealgorithm = 0;
		$this->reqencsmimealgorithm = 0;
		$this->allowsmimeencalgneg = 2;
		$this->allowsmimesoftcerts = 1;
		$this->allowbrowser = 1;
		$this->allowconsumeremail = 1;
		$this->allowremotedesk = 1;
		$this->allowinternetsharing = 1;
		$this->unapprovedinromapplist = [];
		$this->approvedapplist = [];
	}

	/**
	 * Returns the policy hash.
	 *
	 * @return string
	 */
	public function GetPolicyHash() {
		$data = ksort($this->jsonSerialize()['data']);

		return md5(serialize($data));
	}

	/**
	 * Returns the SyncProvisioning instance.
	 *
	 * @param array $policies    array with policies' names and values
	 * @param bool  $logPolicies optional, determines if the policies and values should be logged. Default: false
	 *
	 * @return SyncProvisioning
	 */
	public static function GetObjectWithPolicies($policies = [], $logPolicies = false) {
		$p = new SyncProvisioning();
		$p->Load($policies, $logPolicies);

		return $p;
	}
}
