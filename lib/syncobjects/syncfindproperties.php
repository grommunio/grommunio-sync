<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2022 grommunio GmbH
 *
 * WBXML mail entities that can be parsed directly (as a stream) from WBXML.
 * It is automatically decoded according to $mapping and the Sync WBXML
 * mappings.
 */

class SyncFindProperties extends SyncObject {
	// AS 16.1 props
	public $subject;
	public $datereceived;
	public $displayto;
	public $displaycc;
	public $displaybcc;
	public $importance;
	public $read;
	public $isdraft;
	public $preview;
	public $hasattachments;
	public $from;

	public function __construct() {
		$mapping = [
			SYNC_POOMMAIL_SUBJECT => [
				self::STREAMER_VAR => "subject",
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMMAIL_DATERECEIVED => [
				self::STREAMER_VAR => "datereceived",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES,
				self::STREAMER_RONOTIFY => true,
			],
			SYNC_POOMMAIL_DISPLAYTO => [self::STREAMER_VAR => "displayto"],
			SYNC_FIND_DISPLAYCC => [self::STREAMER_VAR => "displaycc"],
			SYNC_FIND_DISPLAYBCC => [self::STREAMER_VAR => "displaybcc"],
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
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1], 
				],
				self::STREAMER_RONOTIFY => true,
				self::STREAMER_VALUEMAP => [
					0 => "No",
					1 => "Yes",
				],
			],
			SYNC_POOMMAIL2_ISDRAFT => [self::STREAMER_VAR => "isdraft",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1], 
				],
				self::STREAMER_RONOTIFY => true,
				self::STREAMER_VALUEMAP => [
					0 => "No",
					1 => "Yes",
				],
			],
			SYNC_FIND_PREVIEW => [self::STREAMER_VAR => "preview"],
			SYNC_FIND_HASATTACHMENTS => [self::STREAMER_VAR => "hasattachments",
				self::STREAMER_CHECKS => [
					self::STREAMER_CHECK_ONEVALUEOF => [0, 1], 
				],
				self::STREAMER_RONOTIFY => true,
				self::STREAMER_VALUEMAP => [
					0 => "No",
					1 => "Yes",
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
		];

		parent::__construct($mapping);
	}

	static public function GetObjectFromSyncMail($mail) {
		$fp = new SyncFindProperties();
		$fp->subject = $mail->subject;
		$fp->datereceived = $mail->datereceived;
		$fp->displayto = $mail->displayto;
		$fp->displaycc = $mail->Displaycc;
		$fp->displaybcc = $mail->Displaybcc;
		$fp->importance = $mail->importance;
		$fp->read = $mail->read;
		// TODO: fix me
		$fp->isdraft = 0;
		$fp->preview = stream_get_contents($mail->asbody->data, 254);
		$fp->hasattachments = empty($mail->attachments) ? 0:1;
		$fp->from = $mail->from;

		return $fp;
	}
}
