<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML AirSyncBase body entities that can be parsed directly (as a stream)
 * from WBXML. It is automatically decoded according to $mapping and the Sync
 * WBXML mappings.
 */

class SyncMailFlags extends SyncObject {
	public $subject;
	public $flagstatus;
	public $flagtype; // Possible types are clear, complete, active
	public $datecompleted;
	public $completetime;
	public $startdate;
	public $duedate;
	public $utcstartdate;
	public $utcduedate;
	public $reminderset;
	public $remindertime;
	public $ordinaldate;
	public $subordinaldate;

	public function __construct() {
		$mapping = [
			SYNC_POOMTASKS_SUBJECT => [
				self::STREAMER_VAR => "subject",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMMAIL_FLAGSTATUS => [
				self::STREAMER_VAR => "flagstatus",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMMAIL_FLAGTYPE => [
				self::STREAMER_VAR => "flagtype",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_DATECOMPLETED => [
				self::STREAMER_VAR => "datecompleted",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMMAIL_COMPLETETIME => [
				self::STREAMER_VAR => "completetime",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_STARTDATE => [
				self::STREAMER_VAR => "startdate",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_DUEDATE => [
				self::STREAMER_VAR => "duedate",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_UTCSTARTDATE => [
				self::STREAMER_VAR => "utcstartdate",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_UTCDUEDATE => [
				self::STREAMER_VAR => "utcduedate",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_REMINDERSET => [
				self::STREAMER_VAR => "reminderset",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_REMINDERTIME => [
				self::STREAMER_VAR => "remindertime",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_ORDINALDATE => [
				self::STREAMER_VAR => "ordinaldate",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_SUBORDINALDATE => [
				self::STREAMER_VAR => "subordinaldate",
				self::STREAMER_RONOTIFY => true,
			],
		];

		parent::__construct($mapping);
	}
}
