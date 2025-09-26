<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML appointment reccurence entities that can be parsed directly (as a
 * stream) from WBXML. It is automatically decoded according to $mapping and
 * the Sync WBXML mappings.
 */

class SyncRecurrence extends SyncObject {
	public $type;
	public $until;
	public $occurrences;
	public $interval;
	public $dayofweek;
	public $dayofmonth;
	public $weekofmonth;
	public $monthofyear;
	public $calendartype;
	public $firstdayofweek;

	public function __construct() {
		$mapping = [
			// Recurrence type
			// 0 = Recurs daily
			// 1 = Recurs weekly
			// 2 = Recurs monthly
			// 3 = Recurs monthly on the nth day
			// 5 = Recurs yearly
			// 6 = Recurs yearly on the nth day
			SYNC_POOMCAL_TYPE => [
				self::STREAMER_VAR => "type",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETZERO,
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2, 3, 5, 6],
				],
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCAL_UNTIL => [
				self::STREAMER_VAR => "until",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCAL_OCCURRENCES => [
				self::STREAMER_VAR => "occurrences",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_CMPHIGHER => 1,
					self::STREAMER_CHECK_CMPLOWER => 999,
				],
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMCAL_INTERVAL => [
				self::STREAMER_VAR => "interval",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_CMPHIGHER => 1,
					self::STREAMER_CHECK_CMPLOWER => 999,
				],
				self::STREAMER_RONOTIFY => true,
			],
			// DayOfWeek values
			//   1 = Sunday
			//   2 = Monday
			//   4 = Tuesday
			//   8 = Wednesday
			//  16 = Thursday
			//  32 = Friday
			//  62 = Weekdays  // not in spec: daily weekday recurrence
			//  64 = Saturday
			// 127 = The last day of the month. Value valid only in monthly or yearly recurrences.
			// As this is a bitmask, actually all values 0 > x < 128 are allowed
			SYNC_POOMCAL_DAYOFWEEK => [
				self::STREAMER_VAR => "dayofweek",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_CMPHIGHER => 0,
					self::STREAMER_CHECK_CMPLOWER => 128,
				],
				self::STREAMER_RONOTIFY => true,
			],
			// DayOfMonth values
			// 1-31 representing the day
			SYNC_POOMCAL_DAYOFMONTH => [
				self::STREAMER_VAR => "dayofmonth",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_CMPHIGHER => 0,
					self::STREAMER_CHECK_CMPLOWER => 32,
				],
				self::STREAMER_RONOTIFY => true,
			],
			// WeekOfMonth
			// 1-4 = Y st/nd/rd/th week of month
			// 5 = last week of month
			SYNC_POOMCAL_WEEKOFMONTH => [
				self::STREAMER_VAR => "weekofmonth",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [1, 2, 3, 4, 5]],
				self::STREAMER_RONOTIFY => true,
			],
			// MonthOfYear
			// 1-12 representing the month
			SYNC_POOMCAL_MONTHOFYEAR => [
				self::STREAMER_VAR => "monthofyear",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]],
				self::STREAMER_RONOTIFY => true,
			],
		];

		if (Request::GetProtocolVersion() >= 14.0) {
			$mapping[SYNC_POOMCAL_CALENDARTYPE] = [
				self::STREAMER_VAR => "calendartype",
				self::STREAMER_RONOTIFY => true, ];
		}

		if (Request::GetProtocolVersion() >= 14.1) {
			// First day of the calendar week for recurrence.
			// FirstDayOfWeek values:
			//   0 = Sunday
			//   1 = Monday
			//   2 = Tuesday
			//   3 = Wednesday
			//   4 = Thursday
			//   5 = Friday
			//   6 = Saturday
			$mapping[SYNC_POOMCAL_FIRSTDAYOFWEEK] = [
				self::STREAMER_VAR => "firstdayofweek",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2, 3, 4, 5, 6]],
				self::STREAMER_RONOTIFY => true,
			];
		}

		parent::__construct($mapping);

		// Indicates that this SyncObject supports the private flag and stripping of private data.
		// There is nothing concrete to be stripped here, but as it's part of an appointment it supports it.
		$this->supportsPrivateStripping = true;
	}
}
