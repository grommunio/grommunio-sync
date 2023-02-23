<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML folder entities that can be parsed directly (as a stream) from WBXML.
 * It is automatically decoded according to $mapping and the Sync WBXML
 * mappings.
 */

class SyncMeetingRequest extends SyncObject {
	public $alldayevent;
	public $starttime;
	public $dtstamp;
	public $endtime;
	public $instancetype;
	public $location;
	public $organizer;
	public $recurrenceid;
	public $reminder;
	public $responserequested;
	public $recurrences;
	public $sensitivity;
	public $busystatus;
	public $timezone;
	public $globalobjid;
	public $meetingmessagetype;
	public $disallownewtimeproposal;

	public function __construct() {
		$mapping = [
			SYNC_POOMMAIL_ALLDAYEVENT => [
				self::STREAMER_VAR => "alldayevent",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ZEROORONE => self::STREAMER_CHECK_SETZERO],
			],
			SYNC_POOMMAIL_STARTTIME => [
				self::STREAMER_VAR => "starttime",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETZERO,
					self::STREAMER_CHECK_CMPLOWER => SYNC_POOMMAIL_ENDTIME,
				],
			],
			SYNC_POOMMAIL_DTSTAMP => [
				self::STREAMER_VAR => "dtstamp",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETZERO],
			],
			SYNC_POOMMAIL_ENDTIME => [
				self::STREAMER_VAR => "endtime",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETONE,
					self::STREAMER_CHECK_CMPHIGHER => SYNC_POOMMAIL_STARTTIME,
				],
			],
			// Instancetype values
			// 0 = single appointment
			// 1 = master recurring appointment
			// 2 = single instance of recurring appointment
			// 3 = exception of recurring appointment
			SYNC_POOMMAIL_INSTANCETYPE => [
				self::STREAMER_VAR => "instancetype",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETZERO,
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2, 3],
				],
			],
			SYNC_POOMMAIL_LOCATION => [self::STREAMER_VAR => "location"],
			SYNC_POOMMAIL_ORGANIZER => [
				self::STREAMER_VAR => "organizer",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETEMPTY],
			],
			SYNC_POOMMAIL_RECURRENCEID => [
				self::STREAMER_VAR => "recurrenceid",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
			],
			SYNC_POOMMAIL_REMINDER => [
				self::STREAMER_VAR => "reminder",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_CMPHIGHER => -1],
			],
			SYNC_POOMMAIL_RESPONSEREQUESTED => [self::STREAMER_VAR => "responserequested"],
			SYNC_POOMMAIL_RECURRENCES => [
				self::STREAMER_VAR => "recurrences",
				self::STREAMER_TYPE => "SyncMeetingRequestRecurrence",
				self::STREAMER_ARRAY => SYNC_POOMMAIL_RECURRENCE,
			],
			// Sensitivity values
			// 0 = Normal
			// 1 = Personal
			// 2 = Private
			// 3 = Confident
			SYNC_POOMMAIL_SENSITIVITY => [
				self::STREAMER_VAR => "sensitivity",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETZERO,
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2, 3],
				],
			],
			// Busystatus values
			// 0 = Free
			// 1 = Tentative
			// 2 = Busy
			// 3 = Out of office
			// 4 = Working Elsewhere
			SYNC_POOMMAIL_BUSYSTATUS => [
				self::STREAMER_VAR => "busystatus",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETTWO,
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2, 3, 4],
				],
			],
			SYNC_POOMMAIL_TIMEZONE => [
				self::STREAMER_VAR => "timezone",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => base64_encode(pack("la64vvvvvvvv" . "la64vvvvvvvv" . "l", 0, "", 0, 0, 0, 0, 0, 0, 0, 0, 0, "", 0, 0, 0, 0, 0, 0, 0, 0, 0)),
				],
			],
			SYNC_POOMMAIL_GLOBALOBJID => [self::STREAMER_VAR => "globalobjid"],
		];

		if (Request::GetProtocolVersion() >= 14.0) {
			$mapping[SYNC_POOMMAIL_DISALLOWNEWTIMEPROPOSAL] = [
				self::STREAMER_VAR => "disallownewtimeproposal",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETZERO,
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1],
				],
			];
		}

		if (Request::GetProtocolVersion() >= 14.1) {
			// MeetingMessageType values
			// 0 = A silent update was performed, or the message type is unspecified.
			// 1 = Initial meeting request.
			// 2 = Full update.
			// 3 = Informational update.
			// 4 = Outdated. A newer meeting request or meeting update was received after this message.
			// 5 = Identifies the delegator's copy of the meeting request.
			// 6 = Identifies that the meeting request has been delegated and the meeting request cannot be responded to.
			$mapping[SYNC_POOMMAIL2_MEETINGMESSAGETYPE] = [
				self::STREAMER_VAR => "meetingmessagetype",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETZERO,
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2, 3, 4, 5, 6],
				],
			];
		}

		parent::__construct($mapping);
	}

	/**
	 * Method checks if the object has the minimum of required parameters
	 * and fulfills semantic dependencies.
	 *
	 * This overloads the general check() with special checks to be executed
	 *
	 * @param bool $logAsDebug (opt) default is false, so messages are logged in WARN log level
	 *
	 * @return bool
	 */
	public function Check($logAsDebug = false) {
		if (isset($this->busystatus) && $this->busystatus == 0xFFFFFFFF) {
			SLog::Write(LOGLEVEL_WARN, "SyncMeetingRequest->Check(): rewriting busystatus -1 (0xFFFFFFFF) to fbBusy (2).");
			$this->busystatus = fbBusy;
		}

		return parent::Check($logAsDebug);
	}
}
