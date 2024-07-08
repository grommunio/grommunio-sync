<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML task entities that can be parsed directly (as a stream) from WBXML.
 * It is automatically decoded according to $mapping and the Sync WBXML
 * mappings.
 */

class SyncTask extends SyncObject {
	public $body;
	public $complete;
	public $datecompleted;
	public $duedate;
	public $utcduedate;
	public $importance;
	public $recurrence;
	public $regenerate;
	public $deadoccur;
	public $reminderset;
	public $remindertime;
	public $sensitivity;
	public $startdate;
	public $utcstartdate;
	public $subject;
	public $rtf;
	public $categories;

	public function __construct() {
		$mapping = [
			SYNC_POOMTASKS_BODY => [
				self::STREAMER_VAR => "body",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_COMPLETE => [
				self::STREAMER_VAR => "complete",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETZERO,
					self::STREAMER_CHECK_ZEROORONE => self::STREAMER_CHECK_SETZERO,
				],
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_DATECOMPLETED => [
				self::STREAMER_VAR => "datecompleted",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_DUEDATE => [
				self::STREAMER_VAR => "duedate",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_UTCDUEDATE => [
				self::STREAMER_VAR => "utcduedate",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			// Importance values
			// 0 = Low
			// 1 = Normal
			// 2 = High
			// even the default value 1 is optional, the native android client 2.2 interprets a non-existing value as 0 (low)
			SYNC_POOMTASKS_IMPORTANCE => [self::STREAMER_VAR => "importance",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETONE,
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2],
				],
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_RECURRENCE => [
				self::STREAMER_VAR => "recurrence",
				self::STREAMER_TYPE => "SyncTaskRecurrence",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_REGENERATE => [
				self::STREAMER_VAR => "regenerate",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_DEADOCCUR => [
				self::STREAMER_VAR => "deadoccur",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_REMINDERSET => [
				self::STREAMER_VAR => "reminderset",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETZERO,
					self::STREAMER_CHECK_ZEROORONE => self::STREAMER_CHECK_SETZERO,
				],
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_REMINDERTIME => [
				self::STREAMER_VAR => "remindertime",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			// Sensitivity values
			// 0 = Normal
			// 1 = Personal
			// 2 = Private
			// 3 = Confident
			SYNC_POOMTASKS_SENSITIVITY => [
				self::STREAMER_VAR => "sensitivity",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2, 3]],
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_STARTDATE => [
				self::STREAMER_VAR => "startdate",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_UTCSTARTDATE => [
				self::STREAMER_VAR => "utcstartdate",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_SUBJECT => [
				self::STREAMER_VAR => "subject",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMTASKS_CATEGORIES => [
				self::STREAMER_VAR => "categories",
				self::STREAMER_ARRAY => SYNC_POOMTASKS_CATEGORY,
				self::STREAMER_RONOTIFY => true,
			],
		];

		if (Request::GetProtocolVersion() >= 12.0) {
			$mapping[SYNC_AIRSYNCBASE_BODY] = [
				self::STREAMER_VAR => "asbody",
				self::STREAMER_TYPE => "SyncBaseBody",
				self::STREAMER_RONOTIFY => true,
			];

			// unset these properties because airsyncbase body and attachments will be used instead
			unset($mapping[SYNC_POOMTASKS_BODY]);
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
		$ret = parent::Check($logAsDebug);

		// semantic checks general "turn off switch"
		if (defined("DO_SEMANTIC_CHECKS") && DO_SEMANTIC_CHECKS === false) {
			return $ret;
		}

		if (!$ret) {
			return false;
		}

		if (isset($this->startdate, $this->duedate) && $this->duedate < $this->startdate) {
			SLog::Write(LOGLEVEL_WARN, sprintf("SyncObject->Check(): Unmet condition in object from type %s: parameter 'startdate' is HIGHER than 'duedate'. Check failed!", get_class($this)));

			return false;
		}

		if (isset($this->utcstartdate, $this->utcduedate) && $this->utcduedate < $this->utcstartdate) {
			SLog::Write(LOGLEVEL_WARN, sprintf("SyncObject->Check(): Unmet condition in object from type %s: parameter 'utcstartdate' is HIGHER than 'utcduedate'. Check failed!", get_class($this)));

			return false;
		}

		if (isset($this->duedate) && $this->duedate != Utils::getDayStartOfTimestamp($this->duedate)) {
			$this->duedate = Utils::getDayStartOfTimestamp($this->duedate);
			SLog::Write(LOGLEVEL_DEBUG, "Set the due time to the start of the day");
			if (isset($this->startdate) && $this->duedate < $this->startdate) {
				$this->startdate = Utils::getDayStartOfTimestamp($this->startdate);
				SLog::Write(LOGLEVEL_DEBUG, "Set the start date to the start of the day");
			}
		}

		return true;
	}
}

class SyncTaskResponse extends SyncTask {
	use ResponseTrait;
}
