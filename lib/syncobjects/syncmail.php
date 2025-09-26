<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML mail entities that can be parsed directly (as a stream) from WBXML.
 * It is automatically decoded according to $mapping and the Sync WBXML
 * mappings.
 */

class SyncMail extends SyncObject {
	public $to;
	public $cc;
	public $from;
	public $subject;
	public $threadtopic;
	public $datereceived;
	public $displayto;
	public $importance;
	public $read;
	public $attachments;
	public $mimetruncated;
	public $mimedata;
	public $mimesize;
	public $bodytruncated;
	public $bodysize;
	public $body;
	public $messageclass;
	public $meetingrequest;
	public $reply_to;

	// AS 2.5 prop
	public $internetcpid;

	// AS 12.0 props
	public $asbody;
	public $asattachments;
	public $flag;
	public $contentclass;
	public $nativebodytype;

	// AS 14.0 props
	public $umcallerid;
	public $umusernotes;
	public $conversationid;
	public $conversationindex;
	public $lastverbexecuted; // possible values unknown, reply to sender, reply to all, forward
	public $lastverbexectime;
	public $receivedasbcc;
	public $sender;
	public $categories;

	// AS 14.1 props
	public $rightsManagementLicense;
	public $asbodypart;

	// AS 16.0 props
	public $isdraft;
	public $bcc;
	public $send;

	// hidden properties for FIND Command
	public $Displaycc;
	public $Displaybcc;
	public $ParentSourceKey;

	public function __construct() {
		$mapping = [
			SYNC_POOMMAIL_TO => [
				self::STREAMER_VAR => "to",
				self::STREAMER_TYPE => self::STREAMER_TYPE_COMMA_SEPARATED,
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_LENGTHMAX => 32768,
					self::STREAMER_CHECK_EMAIL => "",
				],
			],
			SYNC_POOMMAIL_CC => [
				self::STREAMER_VAR => "cc",
				self::STREAMER_TYPE => self::STREAMER_TYPE_COMMA_SEPARATED,
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_LENGTHMAX => 32768,
					self::STREAMER_CHECK_EMAIL => "",
				],
			],
			SYNC_POOMMAIL_FROM => [
				self::STREAMER_VAR => "from",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_LENGTHMAX => 32768,
					self::STREAMER_CHECK_EMAIL => "",
				],
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMMAIL_SUBJECT => [
				self::STREAMER_VAR => "subject",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMMAIL_THREADTOPIC => [
				self::STREAMER_VAR => "threadtopic",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMMAIL_DATERECEIVED => [
				self::STREAMER_VAR => "datereceived",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMMAIL_DISPLAYTO => [self::STREAMER_VAR => "displayto"],
			// Importance values
			// 0 = Low
			// 1 = Normal
			// 2 = High
			// even the default value 1 is optional, the native android client 2.2 interprets a non-existing value as 0 (low)
			SYNC_POOMMAIL_IMPORTANCE => [
				self::STREAMER_VAR => "importance",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETONE,
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2],
				],
			],
			SYNC_POOMMAIL_READ => [self::STREAMER_VAR => "read",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1], ],
				self::STREAMER_RONOTIFY => true,
				self::STREAMER_VALUEMAP => [
					0 => "No",
					1 => "Yes",
				],
			],
			SYNC_POOMMAIL_ATTACHMENTS => [
				self::STREAMER_VAR => "attachments",
				self::STREAMER_TYPE => "SyncAttachment",
				self::STREAMER_ARRAY => SYNC_POOMMAIL_ATTACHMENT,
			],
			SYNC_POOMMAIL_MIMETRUNCATED => [
				self::STREAMER_VAR => "mimetruncated",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ZEROORONE => self::STREAMER_CHECK_SETZERO],
			],
			SYNC_POOMMAIL_MIMEDATA => [
				self::STREAMER_VAR => "mimedata",
				self::STREAMER_TYPE => self::STREAMER_TYPE_STREAM_ASPLAIN,
			],
			SYNC_POOMMAIL_MIMESIZE => [
				self::STREAMER_VAR => "mimesize",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_CMPHIGHER => -1],
			],
			SYNC_POOMMAIL_BODYTRUNCATED => [
				self::STREAMER_VAR => "bodytruncated",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ZEROORONE => self::STREAMER_CHECK_SETZERO],
			],
			SYNC_POOMMAIL_BODYSIZE => [
				self::STREAMER_VAR => "bodysize",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_CMPHIGHER => -1],
			],
			SYNC_POOMMAIL_BODY => [self::STREAMER_VAR => "body"],
			SYNC_POOMMAIL_MESSAGECLASS => [self::STREAMER_VAR => "messageclass"],
			SYNC_POOMMAIL_MEETINGREQUEST => [
				self::STREAMER_VAR => "meetingrequest",
				self::STREAMER_TYPE => "SyncMeetingRequest",
			],
			SYNC_POOMMAIL_REPLY_TO => [
				self::STREAMER_VAR => "reply_to",
				self::STREAMER_TYPE => self::STREAMER_TYPE_SEMICOLON_SEPARATED,
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_EMAIL => ""],
			],
		];

		if (Request::GetProtocolVersion() >= 2.5) {
			$mapping[SYNC_POOMMAIL_INTERNETCPID] = [self::STREAMER_VAR => "internetcpid"];
		}

		if (Request::GetProtocolVersion() >= 12.0) {
			$mapping[SYNC_AIRSYNCBASE_BODY] = [
				self::STREAMER_VAR => "asbody",
				self::STREAMER_TYPE => "SyncBaseBody",
			];
			$mapping[SYNC_AIRSYNCBASE_ATTACHMENTS] = [
				self::STREAMER_VAR => "asattachments",
				// Different tags can be used to encapsulate the SyncBaseAttachmentSubtypes depending on its usecase
				self::STREAMER_ARRAY => [
					SYNC_AIRSYNCBASE_ATTACHMENT => "SyncBaseAttachment",
					SYNC_AIRSYNCBASE_ADD => "SyncBaseAttachmentAdd",
					SYNC_AIRSYNCBASE_DELETE => "SyncBaseAttachmentDelete",
				],
			];
			$mapping[SYNC_POOMMAIL_CONTENTCLASS] = [
				self::STREAMER_VAR => "contentclass",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [DEFAULT_EMAIL_CONTENTCLASS, DEFAULT_CALENDAR_CONTENTCLASS]],
			];
			$mapping[SYNC_POOMMAIL_FLAG] = [
				self::STREAMER_VAR => "flag",
				self::STREAMER_TYPE => "SyncMailFlags",
				self::STREAMER_PROP => self::STREAMER_TYPE_SEND_EMPTY,
				self::STREAMER_RONOTIFY => true,
			];
			$mapping[SYNC_AIRSYNCBASE_NATIVEBODYTYPE] = [self::STREAMER_VAR => "nativebodytype"];

			// unset these properties because airsyncbase body and attachments will be used instead
			unset($mapping[SYNC_POOMMAIL_BODY], $mapping[SYNC_POOMMAIL_BODYTRUNCATED], $mapping[SYNC_POOMMAIL_ATTACHMENTS]);
		}

		if (Request::GetProtocolVersion() >= 14.0) {
			$mapping[SYNC_POOMMAIL2_UMCALLERID] = [self::STREAMER_VAR => "umcallerid"];
			$mapping[SYNC_POOMMAIL2_UMUSERNOTES] = [self::STREAMER_VAR => "umusernotes"];
			$mapping[SYNC_POOMMAIL2_CONVERSATIONID] = [self::STREAMER_VAR => "conversationid"];
			$mapping[SYNC_POOMMAIL2_CONVERSATIONINDEX] = [self::STREAMER_VAR => "conversationindex"];
			$mapping[SYNC_POOMMAIL2_LASTVERBEXECUTED] = [self::STREAMER_VAR => "lastverbexecuted"];
			$mapping[SYNC_POOMMAIL2_LASTVERBEXECUTIONTIME] = [
				self::STREAMER_VAR => "lastverbexectime",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
			];
			$mapping[SYNC_POOMMAIL2_RECEIVEDASBCC] = [self::STREAMER_VAR => "receivedasbcc"];
			$mapping[SYNC_POOMMAIL2_SENDER] = [self::STREAMER_VAR => "sender"];
			$mapping[SYNC_POOMMAIL_CATEGORIES] = [
				self::STREAMER_VAR => "categories",
				self::STREAMER_ARRAY => SYNC_POOMMAIL_CATEGORY,
				self::STREAMER_RONOTIFY => true,
			];
			// TODO bodypart, accountid
		}

		if (Request::GetProtocolVersion() >= 14.1) {
			$mapping[SYNC_RIGHTSMANAGEMENT_LICENSE] = [
				self::STREAMER_VAR => "rightsManagementLicense",
				self::STREAMER_TYPE => "SyncRightsManagementLicense",
			];
			$mapping[SYNC_AIRSYNCBASE_BODYPART] = [
				self::STREAMER_VAR => "asbodypart",
				self::STREAMER_TYPE => "SyncBaseBodyPart",
			];
		}

		if (Request::GetProtocolVersion() >= 16.0) {
			$mapping[SYNC_POOMMAIL2_ISDRAFT] = [
				self::STREAMER_VAR => "isdraft",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1],
				],
				self::STREAMER_RONOTIFY => true,
				self::STREAMER_VALUEMAP => [
					0 => "No",
					1 => "Yes",
				],
			];
			$mapping[SYNC_POOMMAIL2_BCC] = [
				self::STREAMER_VAR => "bcc",
				self::STREAMER_TYPE => self::STREAMER_TYPE_COMMA_SEPARATED,
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_LENGTHMAX => 32768,
					self::STREAMER_CHECK_EMAIL => "",
				],
			];
			$mapping[SYNC_POOMMAIL2_SEND] = [
				self::STREAMER_VAR => "send",
			];
		}

		// hidden property for the FIND command result
		$mapping[SYNC_POOMMAIL_IGNORE_DISPLAYCC] = [
			self::STREAMER_VAR => "Displaycc",
			self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
		];
		$mapping[SYNC_POOMMAIL_IGNORE_DISPLAYBCC] = [
			self::STREAMER_VAR => "Displaybcc",
			self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
		];
		$mapping[SYNC_POOMMAIL_IGNORE_PARENTSOURCEKEY] = [
			self::STREAMER_VAR => "ParentSourceKey",
			self::STREAMER_TYPE => self::STREAMER_TYPE_IGNORE,
		];
		parent::__construct($mapping);
	}
}

class SyncMailResponse extends SyncMail {
	use ResponseTrait;
}
