<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML meeting request recurrence entities that can be parsed directly (as a
 * stream) from WBXML. It is automatically decoded according to $mapping and
 * the Sync WBXML mappings.
 */

class SyncMeetingRequestRecurrence extends SyncObject {
	public $type;
	public $until;
	public $occurrences;
	public $interval;
	public $dayofweek;
	public $dayofmonth;
	public $weekofmonth;
	public $monthofyear;
	public $calendartype;

	public function __construct() {
		$mapping = [
			// Recurrence type
			// 0 = Recurs daily
			// 1 = Recurs weekly
			// 2 = Recurs monthly
			// 3 = Recurs monthly on the nth day
			// 5 = Recurs yearly
			// 6 = Recurs yearly on the nth day
			SYNC_POOMMAIL_TYPE => [
				self::STREAMER_VAR => "type",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_REQUIRED => self::STREAMER_CHECK_SETZERO,
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1, 2, 3, 5, 6],
				],
			],
			SYNC_POOMMAIL_UNTIL => [
				self::STREAMER_VAR => "until",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE,
			],
			SYNC_POOMMAIL_OCCURRENCES => [
				self::STREAMER_VAR => "occurrences",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_CMPHIGHER => 1,
					self::STREAMER_CHECK_CMPLOWER => 999,
				],
			],
			SYNC_POOMMAIL_INTERVAL => [
				self::STREAMER_VAR => "interval",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_CMPHIGHER => 1,
					self::STREAMER_CHECK_CMPLOWER => 999,
				],
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
			SYNC_POOMMAIL_DAYOFWEEK => [
				self::STREAMER_VAR => "dayofweek",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_CMPHIGHER => 0,
					self::STREAMER_CHECK_CMPLOWER => 128,
				],
			],
			// DayOfMonth values
			// 1-31 representing the day
			SYNC_POOMMAIL_DAYOFMONTH => [
				self::STREAMER_VAR => "dayofmonth",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_CMPHIGHER => 0,
					self::STREAMER_CHECK_CMPLOWER => 32,
				],
			],
			// WeekOfMonth
			// 1-4 = Y st/nd/rd/th week of month
			// 5 = last week of month
			SYNC_POOMMAIL_WEEKOFMONTH => [
				self::STREAMER_VAR => "weekofmonth",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [1, 2, 3, 4, 5]],
			],
			// MonthOfYear
			// 1-12 representing the month
			SYNC_POOMMAIL_MONTHOFYEAR => [
				self::STREAMER_VAR => "monthofyear",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]],
			],
		];

		if (Request::GetProtocolVersion() >= 14.0) {
			$mapping[SYNC_POOMMAIL2_CALENDARTYPE] = [self::STREAMER_VAR => "calendartype"];
		}

		parent::__construct($mapping);
	}
}
