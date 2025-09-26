<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2017 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML rights management license entities that can be parsed directly (as a
 * stream) from WBXML. It is automatically decoded according to $mapping and
 * the Sync WBXML mappings.
 */

class SyncRightsManagementLicense extends SyncObject {
	public $contentExpiryDate;
	public $contentOwner;
	public $editAllowed;
	public $exportAllowed;
	public $extractAllowed;
	public $forwardAllowed;
	public $modifyRecipientsAllowed;
	public $owner;
	public $printAllowed;
	public $programmaticAccessAllowed;
	public $replyAllAllowed;
	public $replyAllowed;
	public $description;
	public $id;
	public $name;

	public function __construct() {
		$mapping = [
			SYNC_RIGHTSMANAGEMENT_CONTENTEXPIRYDATE => [
				self::STREAMER_VAR => "contentExpiryDate",
				self::STREAMER_TYPE => self::STREAMER_TYPE_DATE,
			],
			SYNC_RIGHTSMANAGEMENT_CONTENTOWNER => [
				self::STREAMER_VAR => "contentOwner",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_LENGTHMAX => 320],
			],
			SYNC_RIGHTSMANAGEMENT_EDITALLOWED => [
				self::STREAMER_VAR => "editAllowed",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_RIGHTSMANAGEMENT_EXPORTALLOWED => [
				self::STREAMER_VAR => "exportAllowed",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_RIGHTSMANAGEMENT_EXTRACTALLOWED => [
				self::STREAMER_VAR => "extractAllowed",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_RIGHTSMANAGEMENT_FORWARDALLOWED => [
				self::STREAMER_VAR => "forwardAllowed",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_RIGHTSMANAGEMENT_MODIFYRECIPIENTSALLOWED => [
				self::STREAMER_VAR => "modifyRecipientsAllowed",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_RIGHTSMANAGEMENT_OWNER => [
				self::STREAMER_VAR => "owner",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_RIGHTSMANAGEMENT_PRINTALLOWED => [
				self::STREAMER_VAR => "printAllowed",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_RIGHTSMANAGEMENT_PROGRAMMATICACCESSALLOWED => [
				self::STREAMER_VAR => "programmaticAccessAllowed",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_RIGHTSMANAGEMENT_REPLYALLALLOWED => [
				self::STREAMER_VAR => "replyAllAllowed",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_RIGHTSMANAGEMENT_REPLYALLOWED => [
				self::STREAMER_VAR => "replyAllowed",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_ONEVALUEOF => [0, 1]],
			],
			SYNC_RIGHTSMANAGEMENT_TEMPLATEDESCRIPTION => [
				self::STREAMER_VAR => "description",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_LENGTHMAX => 10240],
			],
			SYNC_RIGHTSMANAGEMENT_TEMPLATEID => [self::STREAMER_VAR => "id"],
			SYNC_RIGHTSMANAGEMENT_TEMPLATENAME => [
				self::STREAMER_VAR => "name",
				self::STREAMER_CHECKS => [self::STREAMER_CHECK_LENGTHMAX => 256],
			],
		];

		parent::__construct($mapping);
	}
}
