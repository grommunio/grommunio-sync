<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2013,2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2025 grommunio GmbH
 */

/**
 * MAPI utility functions.
 */
class MAPIUtils {
	/**
	 * Create a MAPI restriction to use within an email folder which will
	 * return all messages since since $timestamp.
	 *
	 * @param long $timestamp Timestamp since when to include messages
	 *
	 * @return array
	 */
	public static function GetEmailRestriction($timestamp) {
		// ATTENTION: ON CHANGING THIS RESTRICTION, MAPIUtils::IsInEmailSyncInterval() also needs to be changed
		return [
			RES_PROPERTY,
			[
				RELOP => RELOP_GE,
				ULPROPTAG => PR_MESSAGE_DELIVERY_TIME,
				VALUE => $timestamp,
			],
		];
	}

	/**
	 * Create a MAPI restriction to use in the calendar which will
	 * return all future calendar items, plus those since $timestamp.
	 *
	 * @param MAPIStore $store     the MAPI store
	 * @param long      $timestamp Timestamp since when to include messages
	 *
	 * @return array
	 */
	// TODO getting named properties
	public static function GetCalendarRestriction($store, $timestamp) {
		// This is our viewing window
		$start = $timestamp;
		$end = 0x7FFFFFFF; // infinite end

		$props = MAPIMapping::GetAppointmentProperties();
		$props = getPropIdsFromStrings($store, $props);

		// ATTENTION: ON CHANGING THIS RESTRICTION, MAPIUtils::IsInCalendarSyncInterval() also needs to be changed
		return [
			RES_OR,
			[
				// OR
				// item.end > window.start && item.start < window.end
				[
					RES_AND,
					[
						[
							RES_PROPERTY,
							[
								RELOP => RELOP_LE,
								ULPROPTAG => $props["starttime"],
								VALUE => $end,
							],
						],
						[
							RES_PROPERTY,
							[
								RELOP => RELOP_GE,
								ULPROPTAG => $props["endtime"],
								VALUE => $start,
							],
						],
					],
				],
				// OR
				[
					RES_OR,
					[
						// OR
						// (EXIST(recurrence_enddate_property) && item[isRecurring] == true && recurrence_enddate_property >= start)
						[
							RES_AND,
							[
								[
									RES_EXIST,
									[ULPROPTAG => $props["recurrenceend"],
									],
								],
								[
									RES_PROPERTY,
									[
										RELOP => RELOP_EQ,
										ULPROPTAG => $props["isrecurring"],
										VALUE => true,
									],
								],
								[
									RES_PROPERTY,
									[
										RELOP => RELOP_GE,
										ULPROPTAG => $props["recurrenceend"],
										VALUE => $start,
									],
								],
							],
						],
						// OR
						// (!EXIST(recurrence_enddate_property) && item[isRecurring] == true && item[start] <= end)
						[
							RES_AND,
							[
								[
									RES_NOT,
									[
										[
											RES_EXIST,
											[ULPROPTAG => $props["recurrenceend"],
											],
										],
									],
								],
								[
									RES_PROPERTY,
									[
										RELOP => RELOP_LE,
										ULPROPTAG => $props["starttime"],
										VALUE => $end,
									],
								],
								[
									RES_PROPERTY,
									[
										RELOP => RELOP_EQ,
										ULPROPTAG => $props["isrecurring"],
										VALUE => true,
									],
								],
							],
						],
					],
				], // EXISTS OR
			],
		];        // global OR
	}

	/**
	 * Create a MAPI restriction in order to check if a contact has a picture.
	 *
	 * @return array
	 */
	public static function GetContactPicRestriction() {
		return [
			RES_PROPERTY,
			[
				RELOP => RELOP_EQ,
				ULPROPTAG => mapi_prop_tag(PT_BOOLEAN, 0x7FFF),
				VALUE => true,
			],
		];
	}

	/**
	 * Create a MAPI restriction for search.
	 *
	 * @param string $query
	 *
	 * @return array
	 */
	public static function GetSearchRestriction($query) {
		return [
			RES_AND,
			[
				[
					RES_OR,
					[
						[
							RES_CONTENT,
							[
								FUZZYLEVEL => FL_SUBSTRING | FL_IGNORECASE,
								ULPROPTAG => PR_DISPLAY_NAME,
								VALUE => $query,
							],
						],
						[
							RES_CONTENT,
							[
								FUZZYLEVEL => FL_SUBSTRING | FL_IGNORECASE,
								ULPROPTAG => PR_ACCOUNT,
								VALUE => $query,
							],
						],
						[
							RES_CONTENT,
							[
								FUZZYLEVEL => FL_SUBSTRING | FL_IGNORECASE,
								ULPROPTAG => PR_SMTP_ADDRESS,
								VALUE => $query,
							],
						],
					], // RES_OR
				],
				[
					RES_OR,
					[
						[
							RES_PROPERTY,
							[
								RELOP => RELOP_EQ,
								ULPROPTAG => PR_OBJECT_TYPE,
								VALUE => MAPI_MAILUSER,
							],
						],
						[
							RES_PROPERTY,
							[
								RELOP => RELOP_EQ,
								ULPROPTAG => PR_OBJECT_TYPE,
								VALUE => MAPI_DISTLIST,
							],
						],
					],
				], // RES_OR
			], // RES_AND
		];
	}

	/**
	 * Create a MAPI restriction for a certain email address.
	 *
	 * @param MAPIStore $store the MAPI store
	 * @param mixed     $email
	 *
	 * @return array
	 */
	public static function GetEmailAddressRestriction($store, $email) {
		$props = MAPIMapping::GetContactProperties();
		$props = getPropIdsFromStrings($store, $props);

		return [
			RES_OR,
			[
				[
					RES_PROPERTY,
					[
						RELOP => RELOP_EQ,
						ULPROPTAG => $props['emailaddress1'],
						VALUE => [$props['emailaddress1'] => $email],
					],
				],
				[
					RES_PROPERTY,
					[
						RELOP => RELOP_EQ,
						ULPROPTAG => $props['emailaddress2'],
						VALUE => [$props['emailaddress2'] => $email],
					],
				],
				[
					RES_PROPERTY,
					[
						RELOP => RELOP_EQ,
						ULPROPTAG => $props['emailaddress3'],
						VALUE => [$props['emailaddress3'] => $email],
					],
				],
			],
		];
	}

	/**
	 * Create a MAPI restriction for a certain folder type.
	 *
	 * @param string $foldertype folder type for restriction
	 *
	 * @return array
	 */
	public static function GetFolderTypeRestriction($foldertype) {
		return [
			RES_PROPERTY,
			[
				RELOP => RELOP_EQ,
				ULPROPTAG => PR_CONTAINER_CLASS,
				VALUE => [PR_CONTAINER_CLASS => $foldertype],
			],
		];
	}

	/**
	 * Returns subfolders of given type for a folder or false if there are none.
	 *
	 * @param MAPIFolder $folder
	 * @param string     $type
	 *
	 * @return bool|MAPITable
	 */
	public static function GetSubfoldersForType($folder, $type) {
		$subfolders = mapi_folder_gethierarchytable($folder, CONVENIENT_DEPTH);
		mapi_table_restrict($subfolders, MAPIUtils::GetFolderTypeRestriction($type));
		if (mapi_table_getrowcount($subfolders) > 0) {
			return mapi_table_queryallrows($subfolders, [PR_ENTRYID]);
		}

		return false;
	}

	/**
	 * Checks if mapimessage is inside the synchronization interval
	 * also defined by MAPIUtils::GetEmailRestriction().
	 *
	 * @param MAPIStore   $store       mapi store
	 * @param MAPIMessage $mapimessage the mapi message to be checked
	 * @param long        $timestamp   the lower time limit
	 *
	 * @return bool
	 */
	public static function IsInEmailSyncInterval($store, $mapimessage, $timestamp) {
		$p = mapi_getprops($mapimessage, [PR_MESSAGE_DELIVERY_TIME]);

		if (isset($p[PR_MESSAGE_DELIVERY_TIME]) && $p[PR_MESSAGE_DELIVERY_TIME] >= $timestamp) {
			SLog::Write(LOGLEVEL_DEBUG, "MAPIUtils->IsInEmailSyncInterval: Message is in the synchronization interval");

			return true;
		}

		SLog::Write(LOGLEVEL_WARN, "MAPIUtils->IsInEmailSyncInterval: Message is OUTSIDE the synchronization interval");

		return false;
	}

	/**
	 * Checks if mapimessage is inside the synchronization interval
	 * also defined by MAPIUtils::GetCalendarRestriction().
	 *
	 * @param MAPIStore   $store       mapi store
	 * @param MAPIMessage $mapimessage the mapi message to be checked
	 * @param long        $timestamp   the lower time limit
	 *
	 * @return bool
	 */
	public static function IsInCalendarSyncInterval($store, $mapimessage, $timestamp) {
		// This is our viewing window
		$start = $timestamp;
		$end = 0x7FFFFFFF; // infinite end

		$props = MAPIMapping::GetAppointmentProperties();
		$props = getPropIdsFromStrings($store, $props);

		$p = mapi_getprops($mapimessage, [$props["starttime"], $props["endtime"], $props["recurrenceend"], $props["isrecurring"], $props["recurrenceend"]]);

		if (
			(
				isset($p[$props["endtime"]], $p[$props["starttime"]]) &&
				// item.end > window.start && item.start < window.end
				$p[$props["endtime"]] > $start && $p[$props["starttime"]] < $end
			) ||
			(
				isset($p[$props["isrecurring"]], $p[$props["recurrenceend"]]) &&
					// (EXIST(recurrence_enddate_property) && item[isRecurring] == true && recurrence_enddate_property >= start)
					$p[$props["isrecurring"]] == true && $p[$props["recurrenceend"]] >= $start
			) ||
			(
				isset($p[$props["isrecurring"]], $p[$props["starttime"]]) &&
					// (!EXIST(recurrence_enddate_property) && item[isRecurring] == true && item[start] <= end)
					!isset($p[$props["recurrenceend"]]) && $p[$props["isrecurring"]] == true && $p[$props["starttime"]] <= $end
			)
		) {
			SLog::Write(LOGLEVEL_DEBUG, "MAPIUtils->IsInCalendarSyncInterval: Message is in the synchronization interval");

			return true;
		}

		SLog::Write(LOGLEVEL_WARN, "MAPIUtils->IsInCalendarSyncInterval: Message is OUTSIDE the synchronization interval");

		return false;
	}

	/**
	 * Checks if mapimessage is in a shared folder and private.
	 *
	 * @param string      $folderid    binary folderid of the message
	 * @param MAPIMessage $mapimessage the mapi message to be checked
	 *
	 * @return bool
	 */
	public static function IsMessageSharedAndPrivate($folderid, $mapimessage) {
		$sensitivity = mapi_getprops($mapimessage, [PR_SENSITIVITY]);
		if (isset($sensitivity[PR_SENSITIVITY]) && $sensitivity[PR_SENSITIVITY] >= SENSITIVITY_PRIVATE) {
			$hexFolderid = bin2hex($folderid);
			$shortId = GSync::GetDeviceManager()->GetFolderIdForBackendId($hexFolderid);
			if (Utils::GetFolderOriginFromId($shortId) == DeviceManager::FLD_ORIGIN_IMPERSONATED) {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIUtils->IsMessageSharedAndPrivate(): Message is in impersonated store '%s' and marked as private", GSync::GetBackend()->GetImpersonatedUser()));

				return true;
			}
			$sharedUser = GSync::GetAdditionalSyncFolderStore($hexFolderid);
			if (Utils::GetFolderOriginFromId($shortId) != DeviceManager::FLD_ORIGIN_USER && $sharedUser != false && $sharedUser != 'SYSTEM') {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIUtils->IsMessageSharedAndPrivate(): Message is in shared store '%s' and marked as private", $sharedUser));

				return true;
			}
		}

		return false;
	}

	/**
	 * Reads data of large properties from a stream.
	 *
	 * @param MAPIMessage $message
	 * @param long        $prop
	 *
	 * @return string
	 */
	public static function readPropStream($message, $prop) {
		$stream = mapi_openproperty($message, $prop, IID_IStream, 0, 0);
		$ret = mapi_last_hresult();
		if ($ret == MAPI_E_NOT_FOUND) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIUtils->readPropStream: property 0x%08X not found. It is either empty or not set. It will be ignored.", $prop));

			return "";
		}
		if ($ret) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("MAPIUtils->readPropStream error opening stream: 0x%08X", $ret));

			return "";
		}
		$data = "";
		$string = "";
		while (1) {
			$data = mapi_stream_read($stream, 1024);
			if (strlen($data) == 0) {
				break;
			}
			$string .= $data;
		}

		return $string;
	}

	/**
	 * Checks if a store supports properties containing unicode characters.
	 *
	 * @param MAPIStore $store
	 */
	public static function IsUnicodeStore($store) {
		$supportmask = mapi_getprops($store, [PR_STORE_SUPPORT_MASK]);
		if (isset($supportmask[PR_STORE_SUPPORT_MASK]) && ($supportmask[PR_STORE_SUPPORT_MASK] & STORE_UNICODE_OK)) {
			SLog::Write(LOGLEVEL_DEBUG, "Store supports properties containing Unicode characters.");
			define('STORE_INTERNET_CPID', INTERNET_CPID_UTF8);
		}
	}

	/**
	 * Returns the MAPI PR_CONTAINER_CLASS string for an ActiveSync Foldertype.
	 *
	 * @param int $foldertype
	 *
	 * @return string
	 */
	public static function GetContainerClassFromFolderType($foldertype) {
		return match ((int) $foldertype) {
			SYNC_FOLDER_TYPE_TASK, SYNC_FOLDER_TYPE_USER_TASK => "IPF.Task",
			SYNC_FOLDER_TYPE_APPOINTMENT, SYNC_FOLDER_TYPE_USER_APPOINTMENT => "IPF.Appointment",
			SYNC_FOLDER_TYPE_CONTACT, SYNC_FOLDER_TYPE_USER_CONTACT => "IPF.Contact",
			SYNC_FOLDER_TYPE_NOTE, SYNC_FOLDER_TYPE_USER_NOTE => "IPF.StickyNote",
			SYNC_FOLDER_TYPE_JOURNAL, SYNC_FOLDER_TYPE_USER_JOURNAL => "IPF.Journal",
			default => "IPF.Note",
		};
	}

	/**
	 * Returns the ActiveSync (USER) Foldertype from MAPI PR_CONTAINER_CLASS.
	 *
	 * @param mixed $class
	 *
	 * @return int
	 */
	public static function GetFolderTypeFromContainerClass($class) {
		if ($class == "IPF.Note") {
			return SYNC_FOLDER_TYPE_USER_MAIL;
		}
		if ($class == "IPF.Task") {
			return SYNC_FOLDER_TYPE_USER_TASK;
		}
		if ($class == "IPF.Appointment") {
			return SYNC_FOLDER_TYPE_USER_APPOINTMENT;
		}
		if ($class == "IPF.Contact") {
			return SYNC_FOLDER_TYPE_USER_CONTACT;
		}
		if ($class == "IPF.StickyNote") {
			return SYNC_FOLDER_TYPE_USER_NOTE;
		}
		if ($class == "IPF.Journal") {
			return SYNC_FOLDER_TYPE_USER_JOURNAL;
		}

		return SYNC_FOLDER_TYPE_OTHER;
	}

	/**
	 * Calculates the native body type of a message using available properties. Refer to oxbbody.
	 *
	 * @param array $messageprops
	 *
	 * @return int
	 */
	public static function GetNativeBodyType($messageprops) {
		// check if the properties are set and get the error code if needed
		if (!isset($messageprops[PR_BODY])) {
			$messageprops[PR_BODY] = self::GetError(PR_BODY, $messageprops);
		}
		if (!isset($messageprops[PR_RTF_COMPRESSED])) {
			$messageprops[PR_RTF_COMPRESSED] = self::GetError(PR_RTF_COMPRESSED, $messageprops);
		}
		if (!isset($messageprops[PR_HTML])) {
			$messageprops[PR_HTML] = self::GetError(PR_HTML, $messageprops);
		}
		if (!isset($messageprops[PR_RTF_IN_SYNC])) {
			$messageprops[PR_RTF_IN_SYNC] = self::GetError(PR_RTF_IN_SYNC, $messageprops);
		}

		if ( // 1
			($messageprops[PR_BODY] == MAPI_E_NOT_FOUND) &&
			($messageprops[PR_RTF_COMPRESSED] == MAPI_E_NOT_FOUND) &&
			($messageprops[PR_HTML] == MAPI_E_NOT_FOUND)) {
			return SYNC_BODYPREFERENCE_PLAIN;
		}
		if ( // 2
			($messageprops[PR_BODY] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_RTF_COMPRESSED] == MAPI_E_NOT_FOUND) &&
			($messageprops[PR_HTML] == MAPI_E_NOT_FOUND)) {
			return SYNC_BODYPREFERENCE_PLAIN;
		}
		if ( // 3
			($messageprops[PR_BODY] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_RTF_COMPRESSED] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_HTML] == MAPI_E_NOT_FOUND)) {
			return SYNC_BODYPREFERENCE_PLAIN;
		}
		if ( // 4
			($messageprops[PR_BODY] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_RTF_COMPRESSED] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_HTML] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			$messageprops[PR_RTF_IN_SYNC]) {
			return SYNC_BODYPREFERENCE_PLAIN;
		}
		if ( // 5
			($messageprops[PR_BODY] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_RTF_COMPRESSED] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_HTML] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			(!$messageprops[PR_RTF_IN_SYNC])) {
			return SYNC_BODYPREFERENCE_HTML;
		}
		if ( // 6
			($messageprops[PR_RTF_COMPRESSED] != MAPI_E_NOT_FOUND || $messageprops[PR_RTF_COMPRESSED] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_HTML] != MAPI_E_NOT_FOUND || $messageprops[PR_HTML] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			$messageprops[PR_RTF_IN_SYNC]) {
			return SYNC_BODYPREFERENCE_PLAIN;
		}
		if ( // 7
			($messageprops[PR_RTF_COMPRESSED] != MAPI_E_NOT_FOUND || $messageprops[PR_RTF_COMPRESSED] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_HTML] != MAPI_E_NOT_FOUND || $messageprops[PR_HTML] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			(!$messageprops[PR_RTF_IN_SYNC])) {
			return SYNC_BODYPREFERENCE_HTML;
		}
		if ( // 8
			($messageprops[PR_BODY] != MAPI_E_NOT_FOUND || $messageprops[PR_BODY] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_RTF_COMPRESSED] != MAPI_E_NOT_FOUND || $messageprops[PR_RTF_COMPRESSED] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			$messageprops[PR_RTF_IN_SYNC]) {
			return SYNC_BODYPREFERENCE_PLAIN;
		}
		if ( // 9.1
			($messageprops[PR_BODY] != MAPI_E_NOT_FOUND || $messageprops[PR_BODY] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_RTF_COMPRESSED] != MAPI_E_NOT_FOUND || $messageprops[PR_RTF_COMPRESSED] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			(!$messageprops[PR_RTF_IN_SYNC])) {
			return SYNC_BODYPREFERENCE_PLAIN;
		}
		if ( // 9.2
			($messageprops[PR_RTF_COMPRESSED] != MAPI_E_NOT_FOUND || $messageprops[PR_RTF_COMPRESSED] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_BODY] == MAPI_E_NOT_FOUND) &&
			($messageprops[PR_HTML] == MAPI_E_NOT_FOUND)) {
			return SYNC_BODYPREFERENCE_PLAIN;
		}
		if ( // 9.3
			($messageprops[PR_BODY] != MAPI_E_NOT_FOUND || $messageprops[PR_BODY] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_RTF_COMPRESSED] == MAPI_E_NOT_FOUND) &&
			($messageprops[PR_HTML] == MAPI_E_NOT_FOUND)) {
			return SYNC_BODYPREFERENCE_PLAIN;
		}
		if ( // 9.4
			($messageprops[PR_HTML] != MAPI_E_NOT_FOUND || $messageprops[PR_HTML] == MAPI_E_NOT_ENOUGH_MEMORY) &&
			($messageprops[PR_BODY] == MAPI_E_NOT_FOUND) &&
			($messageprops[PR_RTF_COMPRESSED] == MAPI_E_NOT_FOUND)) {
			return SYNC_BODYPREFERENCE_HTML;
		}

		// 10
		return SYNC_BODYPREFERENCE_PLAIN;
	}

	/**
	 * Returns the error code for a given property.
	 * Helper for MAPIUtils::GetNativeBodyType() function but also used in other places.
	 *
	 * @param int   $tag
	 * @param array $messageprops
	 *
	 * @return int (MAPI_ERROR_CODE)
	 */
	public static function GetError($tag, $messageprops) {
		$prBodyError = mapi_prop_tag(PT_ERROR, mapi_prop_id($tag));
		if (isset($messageprops[$prBodyError]) && mapi_is_error($messageprops[$prBodyError])) {
			if ($messageprops[$prBodyError] == MAPI_E_NOT_ENOUGH_MEMORY_32BIT ||
					$messageprops[$prBodyError] == MAPI_E_NOT_ENOUGH_MEMORY_64BIT) {
				return MAPI_E_NOT_ENOUGH_MEMORY;
			}
		}

		return MAPI_E_NOT_FOUND;
	}

	/**
	 * Function will be used to decode smime messages and convert it to normal messages.
	 *
	 * @param MAPISession    $session
	 * @param MAPIStore      $store
	 * @param MAPIAdressBook $addressBook
	 * @param mixed          $mapimessage
	 */
	public static function ParseSmime($session, $store, $addressBook, &$mapimessage) {
		$props = mapi_getprops($mapimessage, [
			PR_MESSAGE_CLASS,
			PR_SUBJECT,
			PR_MESSAGE_DELIVERY_TIME,
			PR_SENT_REPRESENTING_NAME,
			PR_SENT_REPRESENTING_ENTRYID,
			PR_SENT_REPRESENTING_SEARCH_KEY,
			PR_SENT_REPRESENTING_EMAIL_ADDRESS,
			PR_SENT_REPRESENTING_SMTP_ADDRESS,
			PR_SENT_REPRESENTING_ADDRTYPE,
			PR_CLIENT_SUBMIT_TIME,
			PR_MESSAGE_FLAGS,
		]);
		$read = $props[PR_MESSAGE_FLAGS] & MSGFLAG_READ;

		if (isset($props[PR_MESSAGE_CLASS]) && stripos((string) $props[PR_MESSAGE_CLASS], 'IPM.Note.SMIME.MultipartSigned') !== false) {
			// also copy recipients because they are lost after mapi_inetmapi_imtomapi
			$origRcptTable = mapi_message_getrecipienttable($mapimessage);
			$origRecipients = mapi_table_queryallrows($origRcptTable, [PR_ENTRYID, PR_SEARCH_KEY, PR_ROWID, PR_DISPLAY_NAME, PR_DISPLAY_TYPE, PR_DISPLAY_TYPE_EX, PR_ADDRTYPE, PR_EMAIL_ADDRESS, PR_SMTP_ADDRESS, PR_OBJECT_TYPE, PR_RECIPIENT_FLAGS, PR_RECIPIENT_TYPE, PR_RECIPIENT_TRACKSTATUS, PR_RECIPIENT_TRACKSTATUS_TIME, PR_RECIPIENT_PROPOSED, PR_RECIPIENT_PROPOSEDSTARTTIME, PR_RECIPIENT_PROPOSEDENDTIME, PR_CREATION_TIME]);

			// this is a signed message. decode it.
			$attachTable = mapi_message_getattachmenttable($mapimessage);
			$rows = mapi_table_queryallrows($attachTable, [PR_ATTACH_MIME_TAG, PR_ATTACH_NUM]);
			$attnum = false;

			foreach ($rows as $row) {
				if (isset($row[PR_ATTACH_MIME_TAG]) && $row[PR_ATTACH_MIME_TAG] == 'multipart/signed') {
					$attnum = $row[PR_ATTACH_NUM];
				}
			}

			if ($attnum !== false) {
				$att = mapi_message_openattach($mapimessage, $attnum);
				$data = mapi_openproperty($att, PR_ATTACH_DATA_BIN);
				mapi_message_deleteattach($mapimessage, $attnum);
				mapi_inetmapi_imtomapi($session, $store, $addressBook, $mapimessage, $data, ["parse_smime_signed" => 1]);
				SLog::Write(LOGLEVEL_DEBUG, "Convert a smime signed message to a normal message.");
			}
			$mprops = mapi_getprops($mapimessage, [PR_MESSAGE_FLAGS]);
			// Workaround for issue 13
			mapi_setprops($mapimessage, [
				PR_MESSAGE_CLASS => 'IPM.Note.SMIME.MultipartSigned',
				PR_SUBJECT => $props[PR_SUBJECT],
				PR_MESSAGE_DELIVERY_TIME => $props[PR_MESSAGE_DELIVERY_TIME],
				PR_SENT_REPRESENTING_NAME => $props[PR_SENT_REPRESENTING_NAME],
				PR_SENT_REPRESENTING_ENTRYID => $props[PR_SENT_REPRESENTING_ENTRYID],
				PR_SENT_REPRESENTING_SEARCH_KEY => $props[PR_SENT_REPRESENTING_SEARCH_KEY],
				PR_SENT_REPRESENTING_EMAIL_ADDRESS => $props[PR_SENT_REPRESENTING_EMAIL_ADDRESS] ?? '',
				PR_SENT_REPRESENTING_SMTP_ADDRESS => $props[PR_SENT_REPRESENTING_SMTP_ADDRESS] ?? '',
				PR_SENT_REPRESENTING_ADDRTYPE => $props[PR_SENT_REPRESENTING_ADDRTYPE] ?? 'SMTP',
				PR_CLIENT_SUBMIT_TIME => $props[PR_CLIENT_SUBMIT_TIME] ?? time(),
				// mark the message as read if the main message has read flag
				PR_MESSAGE_FLAGS => $read ? $mprops[PR_MESSAGE_FLAGS] | MSGFLAG_READ : $mprops[PR_MESSAGE_FLAGS],
			]);
			// readd recipients from original message
			$decapRcptTable = mapi_message_getrecipienttable($mapimessage);
			$decapRecipients = mapi_table_queryallrows($decapRcptTable, [PR_ENTRYID, PR_SEARCH_KEY, PR_ROWID, PR_DISPLAY_NAME]);
			if (empty($decapRecipients) && !empty($origRecipients)) {
				mapi_message_modifyrecipients($mapimessage, MODRECIP_ADD, $origRecipients);
			}
		}
		// TODO check if we need to do this for encrypted (and signed?) message as well
	}
}
