<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML contact entities that can be parsed directly (as a stream) from WBXML.
 * It is automatically decoded according to $mapping and the Sync WBXML
 * mappings.
 */

class SyncContact extends SyncObject {
	public $anniversary;
	public $assistantname;
	public $assistnamephonenumber;
	public $birthday;
	public $body;
	public $bodysize;
	public $bodytruncated;
	public $business2phonenumber;
	public $businesscity;
	public $businesscountry;
	public $businesspostalcode;
	public $businessstate;
	public $businessstreet;
	public $businessfaxnumber;
	public $businessphonenumber;
	public $carphonenumber;
	public $children;
	public $companyname;
	public $department;
	public $email1address;
	public $email2address;
	public $email3address;
	public $fileas;
	public $firstname;
	public $home2phonenumber;
	public $homecity;
	public $homecountry;
	public $homepostalcode;
	public $homestate;
	public $homestreet;
	public $homefaxnumber;
	public $homephonenumber;
	public $jobtitle;
	public $lastname;
	public $middlename;
	public $mobilephonenumber;
	public $officelocation;
	public $othercity;
	public $othercountry;
	public $otherpostalcode;
	public $otherstate;
	public $otherstreet;
	public $pagernumber;
	public $radiophonenumber;
	public $spouse;
	public $suffix;
	public $title;
	public $webpage;
	public $yomicompanyname;
	public $yomifirstname;
	public $yomilastname;
	public $rtf;
	public $picture;
	public $categories;

	// AS 2.5 props
	public $customerid;
	public $governmentid;
	public $imaddress;
	public $imaddress2;
	public $imaddress3;
	public $managername;
	public $companymainphone;
	public $accountname;
	public $nickname;
	public $mms;

	// AS 12.0 props
	public $asbody;

	public function __construct() {
		$mapping = [
			SYNC_POOMCONTACTS_ANNIVERSARY => [
				self::STREAMER_VAR => "anniversary",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_ASSISTANTNAME => [
				self::STREAMER_VAR => "assistantname",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_ASSISTNAMEPHONENUMBER => [
				self::STREAMER_VAR => "assistnamephonenumber",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_BIRTHDAY => [
				self::STREAMER_VAR => "birthday",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_BODY => [
				self::STREAMER_VAR => "body",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_BODYSIZE => [
				self::STREAMER_VAR => "bodysize",
			],
			SYNC_POOMCONTACTS_BODYTRUNCATED => [
				self::STREAMER_VAR => "bodytruncated",
			],
			SYNC_POOMCONTACTS_BUSINESS2PHONENUMBER => [
				self::STREAMER_VAR => "business2phonenumber",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_BUSINESSCITY => [
				self::STREAMER_VAR => "businesscity",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_BUSINESSCOUNTRY => [
				self::STREAMER_VAR => "businesscountry",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_BUSINESSPOSTALCODE => [
				self::STREAMER_VAR => "businesspostalcode",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_BUSINESSSTATE => [
				self::STREAMER_VAR => "businessstate",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_BUSINESSSTREET => [
				self::STREAMER_VAR => "businessstreet",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_BUSINESSFAXNUMBER => [
				self::STREAMER_VAR => "businessfaxnumber",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_BUSINESSPHONENUMBER => [
				self::STREAMER_VAR => "businessphonenumber",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_CARPHONENUMBER => [
				self::STREAMER_VAR => "carphonenumber",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_CHILDREN => [
				self::STREAMER_VAR => "children",
				self::STREAMER_ARRAY => SYNC_POOMCONTACTS_CHILD,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_COMPANYNAME => [
				self::STREAMER_VAR => "companyname",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_DEPARTMENT => [
				self::STREAMER_VAR => "department",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_EMAIL1ADDRESS => [
				self::STREAMER_VAR => "email1address",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_EMAIL2ADDRESS => [
				self::STREAMER_VAR => "email2address",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_EMAIL3ADDRESS => [
				self::STREAMER_VAR => "email3address",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_FILEAS => [
				self::STREAMER_VAR => "fileas",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_FIRSTNAME => [
				self::STREAMER_VAR => "firstname",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_HOME2PHONENUMBER => [
				self::STREAMER_VAR => "home2phonenumber",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_HOMECITY => [
				self::STREAMER_VAR => "homecity",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_HOMECOUNTRY => [
				self::STREAMER_VAR => "homecountry",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_HOMEPOSTALCODE => [
				self::STREAMER_VAR => "homepostalcode",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_HOMESTATE => [
				self::STREAMER_VAR => "homestate",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_HOMESTREET => [
				self::STREAMER_VAR => "homestreet",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_HOMEFAXNUMBER => [
				self::STREAMER_VAR => "homefaxnumber",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_HOMEPHONENUMBER => [
				self::STREAMER_VAR => "homephonenumber",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_JOBTITLE => [
				self::STREAMER_VAR => "jobtitle",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_LASTNAME => [
				self::STREAMER_VAR => "lastname",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_MIDDLENAME => [
				self::STREAMER_VAR => "middlename",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_MOBILEPHONENUMBER => [
				self::STREAMER_VAR => "mobilephonenumber",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_OFFICELOCATION => [
				self::STREAMER_VAR => "officelocation",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_OTHERCITY => [
				self::STREAMER_VAR => "othercity",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_OTHERCOUNTRY => [
				self::STREAMER_VAR => "othercountry",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_OTHERPOSTALCODE => [
				self::STREAMER_VAR => "otherpostalcode",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_OTHERSTATE => [
				self::STREAMER_VAR => "otherstate",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_OTHERSTREET => [
				self::STREAMER_VAR => "otherstreet",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_PAGERNUMBER => [
				self::STREAMER_VAR => "pagernumber",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_RADIOPHONENUMBER => [
				self::STREAMER_VAR => "radiophonenumber",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_SPOUSE => [
				self::STREAMER_VAR => "spouse",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_SUFFIX => [
				self::STREAMER_VAR => "suffix",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_TITLE => [
				self::STREAMER_VAR => "title",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_WEBPAGE => [
				self::STREAMER_VAR => "webpage",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_YOMICOMPANYNAME => [
				self::STREAMER_VAR => "yomicompanyname",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_YOMIFIRSTNAME => [
				self::STREAMER_VAR => "yomifirstname",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_YOMILASTNAME => [
				self::STREAMER_VAR => "yomilastname",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_RTF => [
				self::STREAMER_VAR => "rtf",
			],
			SYNC_POOMCONTACTS_PICTURE => [
				self::STREAMER_VAR => "picture",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_LENGTHMAX => SYNC_CONTACTS_MAXPICTURESIZE],
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCONTACTS_CATEGORIES => [
				self::STREAMER_VAR => "categories",
				self::STREAMER_ARRAY => SYNC_POOMCONTACTS_CATEGORY,
				self::STREAMER_RONOTIFY => true,
			],
		];

		if (Request::GetProtocolVersion() >= 2.5) {
			$mapping[SYNC_POOMCONTACTS2_CUSTOMERID] = [
				self::STREAMER_VAR => "customerid",
				self::STREAMER_RONOTIFY => true,
			];
			$mapping[SYNC_POOMCONTACTS2_GOVERNMENTID] = [
				self::STREAMER_VAR => "governmentid",
				self::STREAMER_RONOTIFY => true,
			];
			$mapping[SYNC_POOMCONTACTS2_IMADDRESS] = [
				self::STREAMER_VAR => "imaddress",
				self::STREAMER_RONOTIFY => true,
			];
			$mapping[SYNC_POOMCONTACTS2_IMADDRESS2] = [
				self::STREAMER_VAR => "imaddress2",
				self::STREAMER_RONOTIFY => true,
			];
			$mapping[SYNC_POOMCONTACTS2_IMADDRESS3] = [
				self::STREAMER_VAR => "imaddress3",
				self::STREAMER_RONOTIFY => true,
			];
			$mapping[SYNC_POOMCONTACTS2_MANAGERNAME] = [
				self::STREAMER_VAR => "managername",
				self::STREAMER_RONOTIFY => true,
			];
			$mapping[SYNC_POOMCONTACTS2_COMPANYMAINPHONE] = [
				self::STREAMER_VAR => "companymainphone",
				self::STREAMER_RONOTIFY => true,
			];
			$mapping[SYNC_POOMCONTACTS2_ACCOUNTNAME] = [
				self::STREAMER_VAR => "accountname",
				self::STREAMER_RONOTIFY => true,
			];
			$mapping[SYNC_POOMCONTACTS2_NICKNAME] = [
				self::STREAMER_VAR => "nickname",
				self::STREAMER_RONOTIFY => true,
			];
			$mapping[SYNC_POOMCONTACTS2_MMS] = [
				self::STREAMER_VAR => "mms",
				self::STREAMER_RONOTIFY => true,
			];
		}

		if (Request::GetProtocolVersion() >= 12.0) {
			$mapping[SYNC_AIRSYNCBASE_BODY] = [
				self::STREAMER_VAR => "asbody",
				self::STREAMER_TYPE => "SyncBaseBody",
				self::STREAMER_RONOTIFY => true,
			];

			// unset these properties because airsyncbase body and attachments will be used instead
			unset($mapping[SYNC_POOMCONTACTS_BODY], $mapping[SYNC_POOMCONTACTS_BODYTRUNCATED]);
		}

		parent::__construct($mapping);
	}
}
