<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2025 grommunio GmbH
 */

class MAPIProvider {
	private $session;
	private $store;
	private $zRFC822;
	private $addressbook;
	private $storeProps;
	private $inboxProps;
	private $rootProps;
	private $specialFoldersData;

	/**
	 * Constructor of the MAPI Provider
	 * Almost all methods of this class require a MAPI session and/or store.
	 *
	 * @param resource $session
	 * @param resource $store
	 */
	public function __construct($session, $store) {
		$this->session = $session;
		$this->store = $store;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * GETTER
	 */

	/**
	 * Reads a message from MAPI
	 * Depending on the message class, a contact, appointment, task or email is read.
	 *
	 * @param mixed             $mapimessage
	 * @param ContentParameters $contentparameters
	 *
	 * @return SyncObject
	 */
	public function GetMessage($mapimessage, $contentparameters) {
		// Gets the Sync object from a MAPI object according to its message class

		$props = mapi_getprops($mapimessage, [PR_MESSAGE_CLASS]);
		if (isset($props[PR_MESSAGE_CLASS])) {
			$messageclass = $props[PR_MESSAGE_CLASS];
		}
		else {
			$messageclass = "IPM";
		}

		if (strpos($messageclass, "IPM.Contact") === 0) {
			return $this->getContact($mapimessage, $contentparameters);
		}
		if (strpos($messageclass, "IPM.Appointment") === 0) {
			return $this->getAppointment($mapimessage, $contentparameters);
		}
		if (strpos($messageclass, "IPM.Task") === 0 && strpos($messageclass, "IPM.TaskRequest") === false) {
			return $this->getTask($mapimessage, $contentparameters);
		}
		if (strpos($messageclass, "IPM.StickyNote") === 0) {
			return $this->getNote($mapimessage, $contentparameters);
		}

		return $this->getEmail($mapimessage, $contentparameters);
	}

	/**
	 * Reads a contact object from MAPI.
	 *
	 * @param mixed             $mapimessage
	 * @param ContentParameters $contentparameters
	 *
	 * @return SyncContact
	 */
	private function getContact($mapimessage, $contentparameters) {
		$message = new SyncContact();

		// Standard one-to-one mappings first
		$this->getPropsFromMAPI($message, $mapimessage, MAPIMapping::GetContactMapping());

		// Contact specific props
		$contactproperties = MAPIMapping::GetContactProperties();
		$messageprops = $this->getProps($mapimessage, $contactproperties);

		// set the body according to contentparameters and supported AS version
		$this->setMessageBody($mapimessage, $contentparameters, $message);

		// check the picture
		if (isset($messageprops[$contactproperties["haspic"]]) && $messageprops[$contactproperties["haspic"]]) {
			// Add attachments
			$attachtable = mapi_message_getattachmenttable($mapimessage);
			mapi_table_restrict($attachtable, MAPIUtils::GetContactPicRestriction());
			$rows = mapi_table_queryallrows($attachtable, [PR_ATTACH_NUM, PR_ATTACH_SIZE]);

			foreach ($rows as $row) {
				if (isset($row[PR_ATTACH_NUM])) {
					$mapiattach = mapi_message_openattach($mapimessage, $row[PR_ATTACH_NUM]);
					$message->picture = base64_encode(mapi_attach_openbin($mapiattach, PR_ATTACH_DATA_BIN));
				}
			}
		}

		return $message;
	}

	/**
	 * Reads a task object from MAPI.
	 *
	 * @param mixed             $mapimessage
	 * @param ContentParameters $contentparameters
	 *
	 * @return SyncTask
	 */
	private function getTask($mapimessage, $contentparameters) {
		$message = new SyncTask();

		// Standard one-to-one mappings first
		$this->getPropsFromMAPI($message, $mapimessage, MAPIMapping::GetTaskMapping());

		// Task specific props
		$taskproperties = MAPIMapping::GetTaskProperties();
		$messageprops = $this->getProps($mapimessage, $taskproperties);

		// set the body according to contentparameters and supported AS version
		$this->setMessageBody($mapimessage, $contentparameters, $message);

		// task with deadoccur is an occurrence of a recurring task and does not need to be handled as recurring
		// webaccess does not set deadoccur for the initial recurring task
		if (isset($messageprops[$taskproperties["isrecurringtag"]]) &&
			$messageprops[$taskproperties["isrecurringtag"]] &&
			(!isset($messageprops[$taskproperties["deadoccur"]]) ||
			(isset($messageprops[$taskproperties["deadoccur"]]) &&
			!$messageprops[$taskproperties["deadoccur"]]))) {
			// Process recurrence
			$message->recurrence = new SyncTaskRecurrence();
			$this->getRecurrence($mapimessage, $messageprops, $message, $message->recurrence, false, $taskproperties);
		}

		// when set the task to complete using the WebAccess, the dateComplete property is not set correctly
		if ($message->complete == 1 && !isset($message->datecompleted)) {
			$message->datecompleted = time();
		}

		// if no reminder is set, announce that to the mobile
		if (!isset($message->reminderset)) {
			$message->reminderset = 0;
		}

		return $message;
	}

	/**
	 * Reads an appointment object from MAPI.
	 *
	 * @param mixed             $mapimessage
	 * @param ContentParameters $contentparameters
	 *
	 * @return SyncAppointment
	 */
	private function getAppointment($mapimessage, $contentparameters) {
		$message = new SyncAppointment();

		// Standard one-to-one mappings first
		$this->getPropsFromMAPI($message, $mapimessage, MAPIMapping::GetAppointmentMapping());

		// Appointment specific props
		$appointmentprops = MAPIMapping::GetAppointmentProperties();
		$messageprops = $this->getProps($mapimessage, $appointmentprops);

		// set the body according to contentparameters and supported AS version
		$this->setMessageBody($mapimessage, $contentparameters, $message);

		// Set reminder time if reminderset is true
		if (isset($messageprops[$appointmentprops["reminderset"]]) && $messageprops[$appointmentprops["reminderset"]] == true) {
			if (!isset($messageprops[$appointmentprops["remindertime"]]) || $messageprops[$appointmentprops["remindertime"]] == 0x5AE980E1) {
				$message->reminder = 15;
			}
			else {
				$message->reminder = $messageprops[$appointmentprops["remindertime"]];
			}
		}

		if (!isset($message->uid)) {
			$message->uid = bin2hex($messageprops[$appointmentprops["sourcekey"]]);
		}
		else {
			// if no embedded vCal-Uid is found use hexed GOID
			$message->uid = getUidFromGoid($message->uid) ?? strtoupper(bin2hex($message->uid));
		}

		// Always set organizer information because some devices do not work properly without it
		if (isset($messageprops[$appointmentprops["representingentryid"]], $messageprops[$appointmentprops["representingname"]])) {
			$message->organizeremail = $this->getSMTPAddressFromEntryID($messageprops[$appointmentprops["representingentryid"]]);
			// if the email address can't be resolved, fall back to PR_SENT_REPRESENTING_SEARCH_KEY
			if ($message->organizeremail == "" && isset($messageprops[$appointmentprops["sentrepresentinsrchk"]])) {
				$message->organizeremail = $this->getEmailAddressFromSearchKey($messageprops[$appointmentprops["sentrepresentinsrchk"]]);
			}
			$message->organizername = $messageprops[$appointmentprops["representingname"]];
		}

		if (!empty($messageprops[$appointmentprops["timezonetag"]])) {
			$tz = $this->getTZFromMAPIBlob($messageprops[$appointmentprops["timezonetag"]]);
			$message->timezone = base64_encode(TimezoneUtil::GetSyncBlobFromTZ($tz));
		}
		elseif (!empty($messageprops[$appointmentprops["tzdefstart"]])) {
			$tzDefStart = TimezoneUtil::CreateTimezoneDefinitionObject($messageprops[$appointmentprops["tzdefstart"]]);
			$tz = TimezoneUtil::GetTzFromTimezoneDef($tzDefStart);
			$message->timezone = base64_encode(TimezoneUtil::GetSyncBlobFromTZ($tz));
		}
		elseif (!empty($messageprops[$appointmentprops["timezonedesc"]])) {
			// Windows uses UTC in timezone description in opposite to mstzones in TimezoneUtil which uses GMT
			$wintz = str_replace("UTC", "GMT", $messageprops[$appointmentprops["timezonedesc"]]);
			$tz = TimezoneUtil::GetFullTZFromTZName(TimezoneUtil::GetTZNameFromWinTZ($wintz));
			$message->timezone = base64_encode(TimezoneUtil::GetSyncBlobFromTZ($tz));
		}
		else {
			// set server default timezone (correct timezone should be configured!)
			$tz = TimezoneUtil::GetFullTZ();
		}

		if (isset($messageprops[$appointmentprops["isrecurring"]]) && $messageprops[$appointmentprops["isrecurring"]]) {
			// Process recurrence
			$message->recurrence = new SyncRecurrence();
			$this->getRecurrence($mapimessage, $messageprops, $message, $message->recurrence, $tz, $appointmentprops);

			if (empty($message->alldayevent)) {
				$message->timezone = base64_encode(TimezoneUtil::GetSyncBlobFromTZ($tz));
			}
		}

		// Do attendees
		$reciptable = mapi_message_getrecipienttable($mapimessage);
		// Only get first 256 recipients, to prevent possible load issues.
		$rows = mapi_table_queryrows($reciptable, [PR_DISPLAY_NAME, PR_EMAIL_ADDRESS, PR_SMTP_ADDRESS, PR_ADDRTYPE, PR_RECIPIENT_TRACKSTATUS, PR_RECIPIENT_TYPE, PR_SEARCH_KEY], 0, 256);

		// Exception: we do not synchronize appointments with more than 250 attendees
		if (count($rows) > 250) {
			$message->id = bin2hex($messageprops[$appointmentprops["sourcekey"]]);
			$mbe = new SyncObjectBrokenException("Appointment has too many attendees");
			$mbe->SetSyncObject($message);

			throw $mbe;
		}

		if (count($rows) > 0) {
			$message->attendees = [];
		}

		foreach ($rows as $row) {
			$attendee = new SyncAttendee();

			$attendee->name = $row[PR_DISPLAY_NAME];
			// smtp address is always a proper email address
			if (isset($row[PR_SMTP_ADDRESS])) {
				$attendee->email = $row[PR_SMTP_ADDRESS];
			}
			elseif (isset($row[PR_ADDRTYPE], $row[PR_EMAIL_ADDRESS])) {
				// if address type is SMTP, it's also a proper email address
				if ($row[PR_ADDRTYPE] == "SMTP") {
					$attendee->email = $row[PR_EMAIL_ADDRESS];
				}
				// if address type is ZARAFA, the PR_EMAIL_ADDRESS contains username
				elseif ($row[PR_ADDRTYPE] == "ZARAFA") {
					$userinfo = @nsp_getuserinfo($row[PR_EMAIL_ADDRESS]);
					if (is_array($userinfo) && isset($userinfo["primary_email"])) {
						$attendee->email = $userinfo["primary_email"];
					}
					// if the user was not found, do a fallback to PR_SEARCH_KEY
					elseif (isset($row[PR_SEARCH_KEY])) {
						$attendee->email = $this->getEmailAddressFromSearchKey($row[PR_SEARCH_KEY]);
					}
					else {
						SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIProvider->getAppointment: The attendee '%s' of type ZARAFA can not be resolved. Code: 0x%X", $row[PR_EMAIL_ADDRESS], mapi_last_hresult()));
					}
				}
			}

			// set attendee's status and type if they're available and if we are the organizer
			$storeprops = $this->GetStoreProps();
			if (isset($row[PR_RECIPIENT_TRACKSTATUS], $messageprops[$appointmentprops["representingentryid"]], $storeprops[PR_MAILBOX_OWNER_ENTRYID]) &&
					$messageprops[$appointmentprops["representingentryid"]] == $storeprops[PR_MAILBOX_OWNER_ENTRYID]) {
				$attendee->attendeestatus = $row[PR_RECIPIENT_TRACKSTATUS];
			}
			if (isset($row[PR_RECIPIENT_TYPE])) {
				$attendee->attendeetype = $row[PR_RECIPIENT_TYPE];
			}
			// Some attendees have no email or name (eg resources), and if you
			// don't send one of those fields, the phone will give an error ... so
			// we don't send it in that case.
			// also ignore the "attendee" if the email is equal to the organizers' email
			if (isset($attendee->name, $attendee->email) && $attendee->email != "" && (!isset($message->organizeremail) || (isset($message->organizeremail) && $attendee->email != $message->organizeremail))) {
				array_push($message->attendees, $attendee);
			}
		}

		// Status 0 = no meeting, status 1 = organizer, status 2/3/4/5 = tentative/accepted/declined/notresponded
		if (isset($messageprops[$appointmentprops["meetingstatus"]]) && $messageprops[$appointmentprops["meetingstatus"]] > 1) {
			if (!isset($message->attendees) || !is_array($message->attendees)) {
				$message->attendees = [];
			}
			// Work around iOS6 cancellation issue when there are no attendees for this meeting. Just add ourselves as the sole attendee.
			if (count($message->attendees) == 0) {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIProvider->getAppointment: adding ourself as an attendee for iOS6 workaround"));
				$attendee = new SyncAttendee();

				$meinfo = nsp_getuserinfo(Request::GetUserIdentifier());

				if (is_array($meinfo)) {
					$attendee->email = $meinfo["primary_email"];
					$attendee->name = $meinfo["fullname"];
					$attendee->attendeetype = MAPI_TO;

					array_push($message->attendees, $attendee);
				}
			}
			$message->responsetype = $messageprops[$appointmentprops["responsestatus"]];
		}

		// If it's an appointment which doesn't have any attendees, we have to make sure that
		// the user is the owner or it will not work properly with android devices
		if (isset($messageprops[$appointmentprops["meetingstatus"]]) && $messageprops[$appointmentprops["meetingstatus"]] == olNonMeeting && empty($message->attendees)) {
			$meinfo = nsp_getuserinfo(Request::GetUserIdentifier());

			if (is_array($meinfo)) {
				$message->organizeremail = $meinfo["primary_email"];
				$message->organizername = $meinfo["fullname"];
				SLog::Write(LOGLEVEL_DEBUG, "MAPIProvider->getAppointment(): setting ourself as the organizer for an appointment without attendees.");
			}
		}

		if (!isset($message->nativebodytype)) {
			$message->nativebodytype = MAPIUtils::GetNativeBodyType($messageprops);
		}
		elseif ($message->nativebodytype == SYNC_BODYPREFERENCE_UNDEFINED) {
			$nbt = MAPIUtils::GetNativeBodyType($messageprops);
			SLog::Write(LOGLEVEL_INFO, sprintf("MAPIProvider->getAppointment(): native body type is undefined. Set it to %d.", $nbt));
			$message->nativebodytype = $nbt;
		}

		// If the user is working from a location other than the office the busystatus should be interpreted as free.
		if (isset($message->busystatus) && $message->busystatus == fbWorkingElsewhere) {
			$message->busystatus = fbFree;
		}

		// If the busystatus has the value of -1, we should be interpreted as tentative (1)
		if (isset($message->busystatus) && $message->busystatus == -1) {
			$message->busystatus = fbTentative;
		}

		// All-day events might appear as 24h (or multiple of it) long when they start not exactly at midnight (+/- bias of the timezone)
		if (isset($message->alldayevent) && $message->alldayevent) {
			// Adjust all day events for the appointments timezone
			$duration = $message->endtime - $message->starttime;
			// AS pre 16: time in local timezone - convert if it isn't on midnight
			if (Request::GetProtocolVersion() < 16.0) {
				$localStartTime = localtime($message->starttime, 1);
				if ($localStartTime['tm_hour'] || $localStartTime['tm_min']) {
					SLog::Write(LOGLEVEL_DEBUG, "MAPIProvider->getAppointment(): all-day event starting not midnight - convert to local time");
					$serverTz = TimezoneUtil::GetFullTZ();
					$message->starttime = $this->getGMTTimeByTZ($this->getLocaltimeByTZ($message->starttime, $tz), $serverTz);
					if (!$message->timezone) {
						$message->timezone = base64_encode(TimezoneUtil::GetSyncBlobFromTZ($tz));
					}
				}
			}
			else {
				// AS 16: apply timezone as this MUST result in midnight (to be sent to the client)
				$message->starttime = $this->getLocaltimeByTZ($message->starttime, $tz);
			}
			$message->endtime = $message->starttime + $duration;
			if (Request::GetProtocolVersion() >= 16.0) {
				// no timezone information should be sent
				unset($message->timezone);
			}
		}

		// Add attachments to message for AS 16.0 and higher
		if (Request::GetProtocolVersion() >= 16.0) {
			// add attachments
			$entryid = bin2hex($messageprops[$appointmentprops["entryid"]]);
			$parentSourcekey = bin2hex($messageprops[$appointmentprops["parentsourcekey"]]);
			$this->setAttachment($mapimessage, $message, $entryid, $parentSourcekey);
			// add location
			$message->location2 = new SyncLocation();
			$this->getASlocation($mapimessage, $message->location2, $appointmentprops);
		}

		return $message;
	}

	/**
	 * Reads recurrence information from MAPI.
	 *
	 * @param mixed      $mapimessage
	 * @param array      $recurprops
	 * @param SyncObject &$syncMessage     the message
	 * @param SyncObject &$syncRecurrence  the  recurrence message
	 * @param array      $tz               timezone information
	 * @param array      $appointmentprops property definitions
	 */
	private function getRecurrence($mapimessage, $recurprops, &$syncMessage, &$syncRecurrence, $tz, $appointmentprops) {
		if ($syncRecurrence instanceof SyncTaskRecurrence) {
			$recurrence = new TaskRecurrence($this->store, $mapimessage);
		}
		else {
			$recurrence = new Recurrence($this->store, $mapimessage);
		}

		switch ($recurrence->recur["type"]) {
			case 10: // daily
				switch ($recurrence->recur["subtype"]) {
					default:
						$syncRecurrence->type = 0;
						break;

					case 1:
						$syncRecurrence->type = 0;
						$syncRecurrence->dayofweek = 62; // mon-fri
						$syncRecurrence->interval = 1;
						break;
				}
				break;

			case 11: // weekly
				$syncRecurrence->type = 1;
				break;

			case 12: // monthly
				switch ($recurrence->recur["subtype"]) {
					default:
						$syncRecurrence->type = 2;
						break;

					case 3:
						$syncRecurrence->type = 3;
						break;
				}
				break;

			case 13: // yearly
				switch ($recurrence->recur["subtype"]) {
					default:
						$syncRecurrence->type = 4;
						break;

					case 2:
						$syncRecurrence->type = 5;
						break;

					case 3:
						$syncRecurrence->type = 6;
						break;
				}
		}

		// Termination
		switch ($recurrence->recur["term"]) {
			case 0x21:
				$syncRecurrence->until = $recurrence->recur["end"];
				// fixes Mantis #350 : recur-end does not consider timezones - use ClipEnd if available
				if (isset($recurprops[$recurrence->proptags["enddate_recurring"]])) {
					$syncRecurrence->until = $recurprops[$recurrence->proptags["enddate_recurring"]];
				}
				// add one day (minus 1 sec) to the end time to make sure the last occurrence is covered
				$syncRecurrence->until += 86399;
				break;

			case 0x22:
				$syncRecurrence->occurrences = $recurrence->recur["numoccur"];
				break;

			case 0x23:
				// never ends
				break;
		}

		// Correct 'alldayevent' because outlook fails to set it on recurring items of 24 hours or longer
		if (isset($recurrence->recur["endocc"], $recurrence->recur["startocc"]) && ($recurrence->recur["endocc"] - $recurrence->recur["startocc"] >= 1440)) {
			$syncMessage->alldayevent = true;
		}

		// Interval is different according to the type/subtype
		switch ($recurrence->recur["type"]) {
			case 10:
				if ($recurrence->recur["subtype"] == 0) {
					$syncRecurrence->interval = (int) ($recurrence->recur["everyn"] / 1440);
				}  // minutes
				break;

			case 11:
			case 12:
				$syncRecurrence->interval = $recurrence->recur["everyn"];
				break; // months / weeks

			case 13:
				$syncRecurrence->interval = (int) ($recurrence->recur["everyn"] / 12);
				break; // months
		}

		if (isset($recurrence->recur["weekdays"])) {
			$syncRecurrence->dayofweek = $recurrence->recur["weekdays"];
		} // bitmask of days (1 == sunday, 128 == saturday
		if (isset($recurrence->recur["nday"])) {
			$syncRecurrence->weekofmonth = $recurrence->recur["nday"];
		} // N'th {DAY} of {X} (0-5)
		if (isset($recurrence->recur["month"])) {
			$syncRecurrence->monthofyear = (int) ($recurrence->recur["month"] / (60 * 24 * 29)) + 1;
		} // works ok due to rounding. see also $monthminutes below (1-12)
		if (isset($recurrence->recur["monthday"])) {
			$syncRecurrence->dayofmonth = $recurrence->recur["monthday"];
		} // day of month (1-31)

		// All changed exceptions are appointments within the 'exceptions' array. They contain the same items as a normal appointment
		foreach ($recurrence->recur["changed_occurrences"] as $change) {
			$exception = new SyncAppointmentException();

			// start, end, basedate, subject, remind_before, reminderset, location, busystatus, alldayevent, label
			if (isset($change["start"])) {
				$exception->starttime = $this->getGMTTimeByTZ($change["start"], $tz);
			}
			if (isset($change["end"])) {
				$exception->endtime = $this->getGMTTimeByTZ($change["end"], $tz);
			}
			if (isset($change["basedate"])) {
				// depending on the AS version the streamer is going to send the correct value
				$exception->exceptionstarttime = $exception->instanceid = $this->getGMTTimeByTZ($this->getDayStartOfTimestamp($change["basedate"]) + $recurrence->recur["startocc"] * 60, $tz);

				// open body because getting only property might not work because of memory limit
				$exceptionatt = $recurrence->getExceptionAttachment($change["basedate"]);
				if ($exceptionatt) {
					$exceptionobj = mapi_attach_openobj($exceptionatt, 0);
					$this->setMessageBodyForType($exceptionobj, SYNC_BODYPREFERENCE_PLAIN, $exception);
					if (Request::GetProtocolVersion() >= 16.0) {
						// add attachment
						$data = mapi_message_getprops($mapimessage, [PR_ENTRYID, PR_PARENT_SOURCE_KEY]);
						$this->setAttachment($exceptionobj, $exception, bin2hex($data[PR_ENTRYID]), bin2hex($data[PR_PARENT_SOURCE_KEY]), bin2hex($change["basedate"]));
						// add location
						$exception->location2 = new SyncLocation();
						$this->getASlocation($exceptionobj, $exception->location2, $appointmentprops);
					}
				}
			}
			if (isset($change["subject"])) {
				$exception->subject = $change["subject"];
			}
			if (isset($change["reminder_before"]) && $change["reminder_before"]) {
				$exception->reminder = $change["remind_before"];
			}
			if (isset($change["location"])) {
				$exception->location = $change["location"];
			}
			if (isset($change["busystatus"])) {
				$exception->busystatus = $change["busystatus"];
			}
			if (isset($change["alldayevent"])) {
				$exception->alldayevent = $change["alldayevent"];
			}

			// set some data from the original appointment
			if (isset($syncMessage->uid)) {
				$exception->uid = $syncMessage->uid;
			}
			if (isset($syncMessage->organizername)) {
				$exception->organizername = $syncMessage->organizername;
			}
			if (isset($syncMessage->organizeremail)) {
				$exception->organizeremail = $syncMessage->organizeremail;
			}

			if (!isset($syncMessage->exceptions)) {
				$syncMessage->exceptions = [];
			}

			// If the user is working from a location other than the office the busystatus should be interpreted as free.
			if (isset($exception->busystatus) && $exception->busystatus == fbWorkingElsewhere) {
				$exception->busystatus = fbFree;
			}

			// If the busystatus has the value of -1, we should be interpreted as tentative (1)
			if (isset($exception->busystatus) && $exception->busystatus == -1) {
				$exception->busystatus = fbTentative;
			}

			// if an exception lasts 24 hours and the series are an allday events, set also the exception to allday event,
			// otherwise it will be a 24 hour long event on some mobiles.
			if (isset($exception->starttime, $exception->endtime) && ($exception->endtime - $exception->starttime == 86400) && $syncMessage->alldayevent) {
				$exception->alldayevent = 1;
			}
			array_push($syncMessage->exceptions, $exception);
		}

		// Deleted appointments contain only the original date (basedate) and a 'deleted' tag
		foreach ($recurrence->recur["deleted_occurrences"] as $deleted) {
			$exception = new SyncAppointmentException();

			// depending on the AS version the streamer is going to send the correct value
			$exception->exceptionstarttime = $exception->instanceid = $this->getGMTTimeByTZ($this->getDayStartOfTimestamp($deleted) + $recurrence->recur["startocc"] * 60, $tz);
			$exception->deleted = "1";

			if (!isset($syncMessage->exceptions)) {
				$syncMessage->exceptions = [];
			}

			array_push($syncMessage->exceptions, $exception);
		}

		if (isset($syncMessage->complete) && $syncMessage->complete) {
			$syncRecurrence->complete = $syncMessage->complete;
		}
	}

	/**
	 * Reads an email object from MAPI.
	 *
	 * @param mixed             $mapimessage
	 * @param ContentParameters $contentparameters
	 *
	 * @return SyncEmail
	 */
	private function getEmail($mapimessage, $contentparameters) {
		// FIXME: It should be properly fixed when refactoring.
		$bpReturnType = Utils::GetBodyPreferenceBestMatch($contentparameters->GetBodyPreference());
		if (($contentparameters->GetMimeSupport() == SYNC_MIMESUPPORT_NEVER) ||
				($key = array_search(SYNC_BODYPREFERENCE_MIME, $contentparameters->GetBodyPreference()) === false) ||
				$bpReturnType != SYNC_BODYPREFERENCE_MIME) {
			MAPIUtils::ParseSmime($this->session, $this->store, $this->getAddressbook(), $mapimessage);
		}

		$message = new SyncMail();

		$this->getPropsFromMAPI($message, $mapimessage, MAPIMapping::GetEmailMapping());

		$emailproperties = MAPIMapping::GetEmailProperties();
		$messageprops = $this->getProps($mapimessage, $emailproperties);

		if (isset($messageprops[PR_SOURCE_KEY])) {
			$sourcekey = $messageprops[PR_SOURCE_KEY];
		}
		else {
			$mbe = new SyncObjectBrokenException("The message doesn't have a sourcekey");
			$mbe->SetSyncObject($message);

			throw $mbe;
		}

		// set the body according to contentparameters and supported AS version
		$this->setMessageBody($mapimessage, $contentparameters, $message);

		$fromname = $fromaddr = "";

		if (isset($messageprops[$emailproperties["representingname"]])) {
			// remove encapsulating double quotes from the representingname
			$fromname = preg_replace('/^\"(.*)\"$/', "\${1}", $messageprops[$emailproperties["representingname"]]);
		}
		if (isset($messageprops[$emailproperties["representingsendersmtpaddress"]])) {
			$fromaddr = $messageprops[$emailproperties["representingsendersmtpaddress"]];
		}
		if ($fromaddr == "" && isset($messageprops[$emailproperties["representingentryid"]])) {
			$fromaddr = $this->getSMTPAddressFromEntryID($messageprops[$emailproperties["representingentryid"]]);
		}

		// if the email address can't be resolved, fall back to PR_SENT_REPRESENTING_SEARCH_KEY
		if ($fromaddr == "" && isset($messageprops[$emailproperties["representingsearchkey"]])) {
			$fromaddr = $this->getEmailAddressFromSearchKey($messageprops[$emailproperties["representingsearchkey"]]);
		}

		// if we couldn't still not get any $fromaddr, fall back to PR_SENDER_EMAIL_ADDRESS
		if ($fromaddr == "" && isset($messageprops[$emailproperties["senderemailaddress"]])) {
			$fromaddr = $messageprops[$emailproperties["senderemailaddress"]];
		}

		// there is some name, but no email address (e.g. mails from System Administrator) - use a generic invalid address
		if ($fromname != "" && $fromaddr == "") {
			$fromaddr = "invalid@invalid";
		}

		if ($fromname == $fromaddr) {
			$fromname = "";
		}

		if ($fromname) {
			$from = "\"" . $fromname . "\" <" . $fromaddr . ">";
		}
		else { // START CHANGED dw2412 HTC shows "error" if sender name is unknown
			$from = "\"" . $fromaddr . "\" <" . $fromaddr . ">";
		}
		// END CHANGED dw2412 HTC shows "error" if sender name is unknown

		$message->from = $from;

		// process Meeting Requests
		if (isset($message->messageclass) && strpos($message->messageclass, "IPM.Schedule.Meeting") === 0) {
			$message->meetingrequest = new SyncMeetingRequest();
			$this->getPropsFromMAPI($message->meetingrequest, $mapimessage, MAPIMapping::GetMeetingRequestMapping());

			$meetingrequestproperties = MAPIMapping::GetMeetingRequestProperties();
			$props = $this->getProps($mapimessage, $meetingrequestproperties);

			// Get the GOID
			if (isset($props[$meetingrequestproperties["goidtag"]])) {
				$message->meetingrequest->globalobjid = base64_encode($props[$meetingrequestproperties["goidtag"]]);
			}

			// Set Timezone
			if (isset($props[$meetingrequestproperties["timezonetag"]])) {
				$tz = $this->getTZFromMAPIBlob($props[$meetingrequestproperties["timezonetag"]]);
			}
			else {
				$tz = TimezoneUtil::GetFullTZ();
			}

			$message->meetingrequest->timezone = base64_encode(TimezoneUtil::GetSyncBlobFromTZ($tz));

			// send basedate if exception
			if (isset($props[$meetingrequestproperties["recReplTime"]]) ||
				(isset($props[$meetingrequestproperties["lidIsException"]]) && $props[$meetingrequestproperties["lidIsException"]] == true)) {
				if (isset($props[$meetingrequestproperties["recReplTime"]])) {
					$basedate = $props[$meetingrequestproperties["recReplTime"]];
					$message->meetingrequest->recurrenceid = $this->getGMTTimeByTZ($basedate, TimezoneUtil::GetGMTTz());
				}
				else {
					if (!isset($props[$meetingrequestproperties["goidtag"]]) || !isset($props[$meetingrequestproperties["recurStartTime"]]) || !isset($props[$meetingrequestproperties["timezonetag"]])) {
						SLog::Write(LOGLEVEL_WARN, "Missing property to set correct basedate for exception");
					}
					else {
						$basedate = Utils::ExtractBaseDate($props[$meetingrequestproperties["goidtag"]], $props[$meetingrequestproperties["recurStartTime"]]);
						$message->meetingrequest->recurrenceid = $this->getGMTTimeByTZ($basedate, $tz);
					}
				}
			}

			// Organizer is the sender
			if (strpos($message->messageclass, "IPM.Schedule.Meeting.Resp") === 0) {
				$message->meetingrequest->organizer = $message->to;
			}
			else {
				$message->meetingrequest->organizer = $message->from;
			}

			// Process recurrence
			if (isset($props[$meetingrequestproperties["isrecurringtag"]]) && $props[$meetingrequestproperties["isrecurringtag"]]) {
				$myrec = new SyncMeetingRequestRecurrence();
				// get recurrence -> put $message->meetingrequest as message so the 'alldayevent' is set correctly
				$this->getRecurrence($mapimessage, $props, $message->meetingrequest, $myrec, $tz, $meetingrequestproperties);
				$message->meetingrequest->recurrences = [$myrec];
			}

			// Force the 'alldayevent' in the object at all times. (non-existent == 0)
			if (!isset($message->meetingrequest->alldayevent) || $message->meetingrequest->alldayevent == "") {
				$message->meetingrequest->alldayevent = 0;
			}

			// Instancetype
			// 0 = single appointment
			// 1 = master recurring appointment
			// 2 = single instance of recurring appointment
			// 3 = exception of recurring appointment
			$message->meetingrequest->instancetype = 0;
			if (isset($props[$meetingrequestproperties["isrecurringtag"]]) && $props[$meetingrequestproperties["isrecurringtag"]] == 1) {
				$message->meetingrequest->instancetype = 1;
			}
			elseif ((!isset($props[$meetingrequestproperties["isrecurringtag"]]) || $props[$meetingrequestproperties["isrecurringtag"]] == 0) && isset($message->meetingrequest->recurrenceid)) {
				if (isset($props[$meetingrequestproperties["appSeqNr"]]) && $props[$meetingrequestproperties["appSeqNr"]] == 0) {
					$message->meetingrequest->instancetype = 2;
				}
				else {
					$message->meetingrequest->instancetype = 3;
				}
			}

			// Disable reminder if it is off
			if (!isset($props[$meetingrequestproperties["reminderset"]]) || $props[$meetingrequestproperties["reminderset"]] == false) {
				$message->meetingrequest->reminder = "";
			}
			// the property saves reminder in minutes, but we need it in secs
			else {
				// /set the default reminder time to seconds
				if ($props[$meetingrequestproperties["remindertime"]] == 0x5AE980E1) {
					$message->meetingrequest->reminder = 900;
				}
				else {
					$message->meetingrequest->reminder = $props[$meetingrequestproperties["remindertime"]] * 60;
				}
			}

			// Set sensitivity to 0 if missing
			if (!isset($message->meetingrequest->sensitivity)) {
				$message->meetingrequest->sensitivity = 0;
			}

			// If the user is working from a location other than the office the busystatus should be interpreted as free.
			if (isset($message->meetingrequest->busystatus) && $message->meetingrequest->busystatus == fbWorkingElsewhere) {
				$message->meetingrequest->busystatus = fbFree;
			}

			// If the busystatus has the value of -1, we should be interpreted as tentative (1)
			if (isset($message->meetingrequest->busystatus) && $message->meetingrequest->busystatus == -1) {
				$message->meetingrequest->busystatus = fbTentative;
			}

			// if a meeting request response hasn't been processed yet,
			// do it so that the attendee status is updated on the mobile
			if (!isset($messageprops[$emailproperties["processed"]])) {
				// check if we are not sending the MR so we can process it
				$cuser = GSync::GetBackend()->GetUserDetails(Request::GetUserIdentifier());
				if (isset($cuser["emailaddress"]) && $cuser["emailaddress"] != $fromaddr) {
					if (!isset($req)) {
						$req = new Meetingrequest($this->store, $mapimessage, $this->session);
					}
					if ($req->isMeetingRequest() && !$req->isLocalOrganiser() && !$req->isMeetingOutOfDate()) {
						$req->doAccept(true, false, false);
					}
					if ($req->isMeetingRequestResponse()) {
						$req->processMeetingRequestResponse();
					}
					if ($req->isMeetingCancellation()) {
						$req->processMeetingCancellation();
					}
				}
			}
			$message->contentclass = DEFAULT_CALENDAR_CONTENTCLASS;

			// MeetingMessageType values
			// 0 = A silent update was performed, or the message type is unspecified.
			// 1 = Initial meeting request.
			// 2 = Full update.
			// 3 = Informational update.
			// 4 = Outdated. A newer meeting request or meeting update was received after this message.
			// 5 = Identifies the delegator's copy of the meeting request.
			// 6 = Identifies that the meeting request has been delegated and the meeting request cannot be responded to.
			$message->meetingrequest->meetingmessagetype = mtgEmpty;

			if (isset($props[$meetingrequestproperties["meetingType"]])) {
				switch ($props[$meetingrequestproperties["meetingType"]]) {
					case mtgRequest:
						$message->meetingrequest->meetingmessagetype = 1;
						break;

					case mtgFull:
						$message->meetingrequest->meetingmessagetype = 2;
						break;

					case mtgInfo:
						$message->meetingrequest->meetingmessagetype = 3;
						break;

					case mtgOutOfDate:
						$message->meetingrequest->meetingmessagetype = 4;
						break;

					case mtgDelegatorCopy:
						$message->meetingrequest->meetingmessagetype = 5;
						break;
				}
			}
		}

		// Add attachments to message
		$entryid = bin2hex($messageprops[$emailproperties["entryid"]]);
		$parentSourcekey = bin2hex($messageprops[$emailproperties["parentsourcekey"]]);
		$this->setAttachment($mapimessage, $message, $entryid, $parentSourcekey);

		// Get To/Cc as SMTP addresses (this is different from displayto and displaycc because we are putting
		// in the SMTP addresses as well, while displayto and displaycc could just contain the display names
		$message->to = [];
		$message->cc = [];
		$message->bcc = [];

		$reciptable = mapi_message_getrecipienttable($mapimessage);
		$rows = mapi_table_queryallrows($reciptable, [PR_RECIPIENT_TYPE, PR_DISPLAY_NAME, PR_ADDRTYPE, PR_EMAIL_ADDRESS, PR_SMTP_ADDRESS, PR_ENTRYID, PR_SEARCH_KEY]);

		foreach ($rows as $row) {
			$address = "";
			$fulladdr = "";

			$addrtype = isset($row[PR_ADDRTYPE]) ? $row[PR_ADDRTYPE] : "";

			if (isset($row[PR_SMTP_ADDRESS])) {
				$address = $row[PR_SMTP_ADDRESS];
			}
			elseif ($addrtype == "SMTP" && isset($row[PR_EMAIL_ADDRESS])) {
				$address = $row[PR_EMAIL_ADDRESS];
			}
			elseif ($addrtype == "ZARAFA" && isset($row[PR_ENTRYID])) {
				$address = $this->getSMTPAddressFromEntryID($row[PR_ENTRYID]);
			}

			// if the user was not found, do a fallback to PR_SEARCH_KEY
			if (empty($address) && isset($row[PR_SEARCH_KEY])) {
				$address = $this->getEmailAddressFromSearchKey($row[PR_SEARCH_KEY]);
			}

			$name = isset($row[PR_DISPLAY_NAME]) ? $row[PR_DISPLAY_NAME] : "";

			if ($name == "" || $name == $address) {
				$fulladdr = $address;
			}
			else {
				if (substr($name, 0, 1) != '"' && substr($name, -1) != '"') {
					$fulladdr = "\"" . $name . "\" <" . $address . ">";
				}
				else {
					$fulladdr = $name . "<" . $address . ">";
				}
			}

			if ($row[PR_RECIPIENT_TYPE] == MAPI_TO) {
				array_push($message->to, $fulladdr);
			}
			elseif ($row[PR_RECIPIENT_TYPE] == MAPI_CC) {
				array_push($message->cc, $fulladdr);
			}
			elseif ($row[PR_RECIPIENT_TYPE] == MAPI_BCC) {
				array_push($message->bcc, $fulladdr);
			}
		}

		if (is_array($message->to) && !empty($message->to)) {
			$message->to = implode(", ", $message->to);
		}
		if (is_array($message->cc) && !empty($message->cc)) {
			$message->cc = implode(", ", $message->cc);
		}
		if (is_array($message->bcc) && !empty($message->bcc)) {
			$message->bcc = implode(", ", $message->bcc);
		}

		// without importance some mobiles assume "0" (low) - Mantis #439
		if (!isset($message->importance)) {
			$message->importance = IMPORTANCE_NORMAL;
		}

		if (!isset($message->internetcpid)) {
			$message->internetcpid = (defined('STORE_INTERNET_CPID')) ? constant('STORE_INTERNET_CPID') : INTERNET_CPID_WINDOWS1252;
		}
		$this->setFlag($mapimessage, $message);
		// TODO checkcontentclass
		if (!isset($message->contentclass)) {
			$message->contentclass = DEFAULT_EMAIL_CONTENTCLASS;
		}

		if (!isset($message->nativebodytype)) {
			$message->nativebodytype = MAPIUtils::GetNativeBodyType($messageprops);
		}
		elseif ($message->nativebodytype == SYNC_BODYPREFERENCE_UNDEFINED) {
			$nbt = MAPIUtils::GetNativeBodyType($messageprops);
			SLog::Write(LOGLEVEL_INFO, sprintf("MAPIProvider->getEmail(): native body type is undefined. Set it to %d.", $nbt));
			$message->nativebodytype = $nbt;
		}

		// reply, reply to all, forward flags
		if (isset($message->lastverbexecuted) && $message->lastverbexecuted) {
			$message->lastverbexecuted = Utils::GetLastVerbExecuted($message->lastverbexecuted);
		}

		if ($messageprops[$emailproperties["messageflags"]] & MSGFLAG_UNSENT) {
			$message->isdraft = true;
		}

		return $message;
	}

	/**
	 * Reads a note object from MAPI.
	 *
	 * @param mixed             $mapimessage
	 * @param ContentParameters $contentparameters
	 *
	 * @return SyncNote
	 */
	private function getNote($mapimessage, $contentparameters) {
		$message = new SyncNote();

		// Standard one-to-one mappings first
		$this->getPropsFromMAPI($message, $mapimessage, MAPIMapping::GetNoteMapping());

		// set the body according to contentparameters and supported AS version
		$this->setMessageBody($mapimessage, $contentparameters, $message);

		return $message;
	}

	/**
	 * Creates a SyncFolder from MAPI properties.
	 *
	 * @param mixed $folderprops
	 *
	 * @return SyncFolder
	 */
	public function GetFolder($folderprops) {
		$folder = new SyncFolder();

		$storeprops = $this->GetStoreProps();

		// For ZCP 7.0.x we need to retrieve more properties explicitly
		if (isset($folderprops[PR_SOURCE_KEY]) && !isset($folderprops[PR_ENTRYID]) && !isset($folderprops[PR_CONTAINER_CLASS])) {
			$entryid = mapi_msgstore_entryidfromsourcekey($this->store, $folderprops[PR_SOURCE_KEY]);
			$mapifolder = mapi_msgstore_openentry($this->store, $entryid);
			$folderprops = mapi_getprops($mapifolder, [PR_DISPLAY_NAME, PR_PARENT_ENTRYID, PR_ENTRYID, PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY, PR_CONTAINER_CLASS, PR_ATTR_HIDDEN, PR_EXTENDED_FOLDER_FLAGS]);
			SLog::Write(LOGLEVEL_DEBUG, "MAPIProvider->GetFolder(): received insufficient of data from ICS. Fetching required data.");
		}

		if (!isset(
			$folderprops[PR_DISPLAY_NAME],
			$folderprops[PR_PARENT_ENTRYID],
			$folderprops[PR_SOURCE_KEY],
			$folderprops[PR_ENTRYID],
			$folderprops[PR_PARENT_SOURCE_KEY],
			$storeprops[PR_IPM_SUBTREE_ENTRYID]
		)) {
			SLog::Write(LOGLEVEL_ERROR, "MAPIProvider->GetFolder(): invalid folder. Missing properties");

			return false;
		}

		// ignore hidden folders
		if (isset($folderprops[PR_ATTR_HIDDEN]) && $folderprops[PR_ATTR_HIDDEN] != false) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIProvider->GetFolder(): invalid folder '%s' as it is a hidden folder (PR_ATTR_HIDDEN)", $folderprops[PR_DISPLAY_NAME]));

			return false;
		}

		// ignore certain undesired folders, like "RSS Feeds", "Suggested contacts" and Journal
		if ((isset($folderprops[PR_CONTAINER_CLASS]) && (
			$folderprops[PR_CONTAINER_CLASS] == "IPF.Note.OutlookHomepage" || $folderprops[PR_CONTAINER_CLASS] == "IPF.Journal"
		)) ||
			in_array($folderprops[PR_ENTRYID], $this->getSpecialFoldersData())
		) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIProvider->GetFolder(): folder '%s' should not be synchronized", $folderprops[PR_DISPLAY_NAME]));

			return false;
		}

		$folder->BackendId = bin2hex($folderprops[PR_SOURCE_KEY]);
		$folderOrigin = DeviceManager::FLD_ORIGIN_USER;
		if (GSync::GetBackend()->GetImpersonatedUser()) {
			$folderOrigin = DeviceManager::FLD_ORIGIN_IMPERSONATED;
		}
		$folder->serverid = GSync::GetDeviceManager()->GetFolderIdForBackendId($folder->BackendId, true, $folderOrigin, $folderprops[PR_DISPLAY_NAME]);
		if ($folderprops[PR_PARENT_ENTRYID] == $storeprops[PR_IPM_SUBTREE_ENTRYID] || $folderprops[PR_PARENT_ENTRYID] == $storeprops[PR_IPM_PUBLIC_FOLDERS_ENTRYID]) {
			$folder->parentid = "0";
		}
		else {
			$folder->parentid = GSync::GetDeviceManager()->GetFolderIdForBackendId(bin2hex($folderprops[PR_PARENT_SOURCE_KEY]));
		}
		$folder->displayname = $folderprops[PR_DISPLAY_NAME];
		$folder->type = $this->GetFolderType($folderprops[PR_ENTRYID], isset($folderprops[PR_CONTAINER_CLASS]) ? $folderprops[PR_CONTAINER_CLASS] : false);

		return $folder;
	}

	/**
	 * Returns the foldertype for an entryid
	 * Gets the folder type by checking the default folders in MAPI.
	 *
	 * @param string $entryid
	 * @param string $class   (opt)
	 *
	 * @return long
	 */
	public function GetFolderType($entryid, $class = false) {
		$storeprops = $this->GetStoreProps();
		$inboxprops = $this->GetInboxProps();

		if ($entryid == $storeprops[PR_IPM_WASTEBASKET_ENTRYID]) {
			return SYNC_FOLDER_TYPE_WASTEBASKET;
		}
		if ($entryid == $storeprops[PR_IPM_SENTMAIL_ENTRYID]) {
			return SYNC_FOLDER_TYPE_SENTMAIL;
		}
		if ($entryid == $storeprops[PR_IPM_OUTBOX_ENTRYID]) {
			return SYNC_FOLDER_TYPE_OUTBOX;
		}

		// Public folders do not have inboxprops
		if (!empty($inboxprops)) {
			if ($entryid == $inboxprops[PR_ENTRYID]) {
				return SYNC_FOLDER_TYPE_INBOX;
			}
			if ($entryid == $inboxprops[PR_IPM_DRAFTS_ENTRYID]) {
				return SYNC_FOLDER_TYPE_DRAFTS;
			}
			if ($entryid == $inboxprops[PR_IPM_TASK_ENTRYID]) {
				return SYNC_FOLDER_TYPE_TASK;
			}
			if ($entryid == $inboxprops[PR_IPM_APPOINTMENT_ENTRYID]) {
				return SYNC_FOLDER_TYPE_APPOINTMENT;
			}
			if ($entryid == $inboxprops[PR_IPM_CONTACT_ENTRYID]) {
				return SYNC_FOLDER_TYPE_CONTACT;
			}
			if ($entryid == $inboxprops[PR_IPM_NOTE_ENTRYID]) {
				return SYNC_FOLDER_TYPE_NOTE;
			}
			if ($entryid == $inboxprops[PR_IPM_JOURNAL_ENTRYID]) {
				return SYNC_FOLDER_TYPE_JOURNAL;
			}
		}

		// user created folders
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
	 * Indicates if the entry id is a default MAPI folder.
	 *
	 * @param string $entryid
	 *
	 * @return bool
	 */
	public function IsMAPIDefaultFolder($entryid) {
		$msgstore_props = mapi_getprops($this->store, [PR_ENTRYID, PR_DISPLAY_NAME, PR_IPM_SUBTREE_ENTRYID, PR_IPM_OUTBOX_ENTRYID, PR_IPM_SENTMAIL_ENTRYID, PR_IPM_WASTEBASKET_ENTRYID, PR_MDB_PROVIDER, PR_IPM_PUBLIC_FOLDERS_ENTRYID, PR_IPM_FAVORITES_ENTRYID, PR_MAILBOX_OWNER_ENTRYID]);

		$inboxProps = [];
		$inbox = mapi_msgstore_getreceivefolder($this->store);
		if (!mapi_last_hresult()) {
			$inboxProps = mapi_getprops($inbox, [PR_ENTRYID]);
		}

		$root = mapi_msgstore_openentry($this->store, null); // TODO use getRootProps()
		$rootProps = mapi_getprops($root, [PR_IPM_APPOINTMENT_ENTRYID, PR_IPM_CONTACT_ENTRYID, PR_IPM_DRAFTS_ENTRYID, PR_IPM_JOURNAL_ENTRYID, PR_IPM_NOTE_ENTRYID, PR_IPM_TASK_ENTRYID, PR_ADDITIONAL_REN_ENTRYIDS]);

		$additional_ren_entryids = [];
		if (isset($rootProps[PR_ADDITIONAL_REN_ENTRYIDS])) {
			$additional_ren_entryids = $rootProps[PR_ADDITIONAL_REN_ENTRYIDS];
		}

		$defaultfolders = [
			"inbox" => ["inbox" => PR_ENTRYID],
			"outbox" => ["store" => PR_IPM_OUTBOX_ENTRYID],
			"sent" => ["store" => PR_IPM_SENTMAIL_ENTRYID],
			"wastebasket" => ["store" => PR_IPM_WASTEBASKET_ENTRYID],
			"favorites" => ["store" => PR_IPM_FAVORITES_ENTRYID],
			"publicfolders" => ["store" => PR_IPM_PUBLIC_FOLDERS_ENTRYID],
			"calendar" => ["root" => PR_IPM_APPOINTMENT_ENTRYID],
			"contact" => ["root" => PR_IPM_CONTACT_ENTRYID],
			"drafts" => ["root" => PR_IPM_DRAFTS_ENTRYID],
			"journal" => ["root" => PR_IPM_JOURNAL_ENTRYID],
			"note" => ["root" => PR_IPM_NOTE_ENTRYID],
			"task" => ["root" => PR_IPM_TASK_ENTRYID],
			"junk" => ["additional" => 4],
			"syncissues" => ["additional" => 1],
			"conflicts" => ["additional" => 0],
			"localfailures" => ["additional" => 2],
			"serverfailures" => ["additional" => 3],
		];

		foreach ($defaultfolders as $key => $prop) {
			$tag = reset($prop);
			$from = key($prop);

			switch ($from) {
				case "inbox":
					if (isset($inboxProps[$tag]) && $entryid == $inboxProps[$tag]) {
						SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIProvider->IsMAPIFolder(): Inbox found, key '%s'", $key));

						return true;
					}
					break;

				case "store":
					if (isset($msgstore_props[$tag]) && $entryid == $msgstore_props[$tag]) {
						SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIProvider->IsMAPIFolder(): Store folder found, key '%s'", $key));

						return true;
					}
					break;

				case "root":
					if (isset($rootProps[$tag]) && $entryid == $rootProps[$tag]) {
						SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIProvider->IsMAPIFolder(): Root folder found, key '%s'", $key));

						return true;
					}
					break;

				case "additional":
					if (isset($additional_ren_entryids[$tag]) && $entryid == $additional_ren_entryids[$tag]) {
						SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIProvider->IsMAPIFolder(): Additional folder found, key '%s'", $key));

						return true;
					}
					break;
			}
		}

		return false;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * PreDeleteMessage
	 */

	/**
	 * Performs any actions before a message is imported for deletion.
	 *
	 * @param mixed $mapimessage
	 */
	public function PreDeleteMessage($mapimessage) {
		if ($mapimessage === false) {
			return;
		}
		// Currently this is relevant only for MeetingRequests so cancellation emails can be sent to attendees.
		$props = mapi_getprops($mapimessage, [PR_MESSAGE_CLASS]);
		$messageClass = isset($props[PR_MESSAGE_CLASS]) ? $props[PR_MESSAGE_CLASS] : false;

		if ($messageClass !== false && stripos($messageClass, 'ipm.appointment') === 0) {
			SLog::Write(LOGLEVEL_DEBUG, "MAPIProvider->PreDeleteMessage(): Appointment message");
			$mr = new Meetingrequest($this->store, $mapimessage, $this->session);
			$mr->doCancelInvitation();
		}
	}

	/*----------------------------------------------------------------------------------------------------------
	 * SETTER
	 */

	/**
	 * Writes a SyncObject to MAPI
	 * Depending on the message class, a contact, appointment, task or email is written.
	 *
	 * @param mixed      $mapimessage
	 * @param SyncObject $message
	 *
	 * @return SyncObject
	 */
	public function SetMessage($mapimessage, $message) {
		// TODO check with instanceof
		switch (strtolower(get_class($message))) {
			case "synccontact":
				return $this->setContact($mapimessage, $message);

			case "syncappointment":
				return $this->setAppointment($mapimessage, $message);

			case "synctask":
				return $this->setTask($mapimessage, $message);

			case "syncnote":
				return $this->setNote($mapimessage, $message);

			default:
				// for emails only flag (read and todo) changes are possible
				return $this->setEmail($mapimessage, $message);
		}
	}

	/**
	 * Writes SyncMail to MAPI (actually flags only).
	 *
	 * @param mixed    $mapimessage
	 * @param SyncMail $message
	 *
	 * @return SyncObject
	 */
	private function setEmail($mapimessage, $message) {
		$response = new SyncMailResponse();
		// update categories
		if (!isset($message->categories)) {
			$message->categories = [];
		}
		$emailmap = MAPIMapping::GetEmailMapping();
		$emailprops = MAPIMapping::GetEmailProperties();
		$this->setPropsInMAPI($mapimessage, $message, ["categories" => $emailmap["categories"]]);

		$flagmapping = MAPIMapping::GetMailFlagsMapping();
		$flagprops = MAPIMapping::GetMailFlagsProperties();
		$flagprops = array_merge($this->getPropIdsFromStrings($flagmapping), $this->getPropIdsFromStrings($flagprops));
		// flag specific properties to be set
		$props = $delprops = [];

		// save DRAFTs
		if (isset($message->asbody) && $message->asbody instanceof SyncBaseBody) {
			// iOS+Nine send a RFC822 message
			if (isset($message->asbody->type) && $message->asbody->type == SYNC_BODYPREFERENCE_MIME) {
				SLog::Write(LOGLEVEL_DEBUG, "MAPIProvider->setEmail(): Use the mapi_inetmapi_imtomapi function to save draft email");
				$mime = stream_get_contents($message->asbody->data);
				$ab = mapi_openaddressbook($this->session);
				mapi_inetmapi_imtomapi($this->session, $this->store, $ab, $mapimessage, $mime, []);
			}
			else {
				$props[$emailmap["messageclass"]] = "IPM.Note";
				$this->setPropsInMAPI($mapimessage, $message, $emailmap);
			}
			$props[$emailprops["messageflags"]] = MSGFLAG_UNSENT | MSGFLAG_READ;

			if (isset($message->asbody->type) && $message->asbody->type == SYNC_BODYPREFERENCE_HTML && isset($message->asbody->data)) {
				$props[$emailprops["html"]] = stream_get_contents($message->asbody->data);
			}

			// Android devices send the recipients in to, cc and bcc tags
			if (isset($message->to) || isset($message->cc) || isset($message->bcc)) {
				$recips = [];
				$this->addRecips($message->to, MAPI_TO, $recips);
				$this->addRecips($message->cc, MAPI_CC, $recips);
				$this->addRecips($message->bcc, MAPI_BCC, $recips);

				mapi_message_modifyrecipients($mapimessage, MODRECIP_MODIFY, $recips);
			}
			// remove PR_CLIENT_SUBMIT_TIME
			mapi_deleteprops(
				$mapimessage,
				[
					$emailprops["clientsubmittime"],
				]
			);
		}

		// save DRAFTs attachments
		if (!empty($message->asattachments)) {
			$this->editAttachments($mapimessage, $message->asattachments, $response);
		}

		// unset message flags if:
		// flag is not set
		if (empty($message->flag) ||
			// flag status is not set
			!isset($message->flag->flagstatus) ||
			// flag status is 0 or empty
			(isset($message->flag->flagstatus) && ($message->flag->flagstatus == 0 || $message->flag->flagstatus == ""))) {
			// if message flag is empty, some properties need to be deleted
			// and some set to 0 or false

			$props[$flagprops["todoitemsflags"]] = 0;
			$props[$flagprops["status"]] = 0;
			$props[$flagprops["completion"]] = 0.0;
			$props[$flagprops["flagtype"]] = "";
			$props[$flagprops["ordinaldate"]] = 0x7FFFFFFF; // ordinal date is 12am 1.1.4501, set it to max possible value
			$props[$flagprops["subordinaldate"]] = "";
			$props[$flagprops["replyrequested"]] = false;
			$props[$flagprops["responserequested"]] = false;
			$props[$flagprops["reminderset"]] = false;
			$props[$flagprops["complete"]] = false;

			$delprops[] = $flagprops["todotitle"];
			$delprops[] = $flagprops["duedate"];
			$delprops[] = $flagprops["startdate"];
			$delprops[] = $flagprops["datecompleted"];
			$delprops[] = $flagprops["utcstartdate"];
			$delprops[] = $flagprops["utcduedate"];
			$delprops[] = $flagprops["completetime"];
			$delprops[] = $flagprops["flagstatus"];
			$delprops[] = $flagprops["flagicon"];
		}
		else {
			$this->setPropsInMAPI($mapimessage, $message->flag, $flagmapping);
			$props[$flagprops["todoitemsflags"]] = 1;
			if (isset($message->subject) && strlen($message->subject) > 0) {
				$props[$flagprops["todotitle"]] = $message->subject;
			}
			// ordinal date is utc current time
			if (!isset($message->flag->ordinaldate) || empty($message->flag->ordinaldate)) {
				$props[$flagprops["ordinaldate"]] = time();
			}
			// the default value
			if (!isset($message->flag->subordinaldate) || empty($message->flag->subordinaldate)) {
				$props[$flagprops["subordinaldate"]] = "5555555";
			}
			$props[$flagprops["flagicon"]] = 6; // red flag icon
			$props[$flagprops["replyrequested"]] = true;
			$props[$flagprops["responserequested"]] = true;

			if ($message->flag->flagstatus == SYNC_FLAGSTATUS_COMPLETE) {
				$props[$flagprops["status"]] = olTaskComplete;
				$props[$flagprops["completion"]] = 1.0;
				$props[$flagprops["complete"]] = true;
				$props[$flagprops["replyrequested"]] = false;
				$props[$flagprops["responserequested"]] = false;
				unset($props[$flagprops["flagicon"]]);
				$delprops[] = $flagprops["flagicon"];
			}
		}

		if (!empty($props)) {
			mapi_setprops($mapimessage, $props);
		}
		if (!empty($delprops)) {
			mapi_deleteprops($mapimessage, $delprops);
		}

		return $response;
	}

	/**
	 * Writes a SyncAppointment to MAPI.
	 *
	 * @param mixed $mapimessage
	 * @param mixed $appointment
	 *
	 * @return SyncObject
	 */
	private function setAppointment($mapimessage, $appointment) {
		$response = new SyncAppointmentResponse();

		$isAllday = isset($appointment->alldayevent) && $appointment->alldayevent;
		$isMeeting = isset($appointment->meetingstatus) && $appointment->meetingstatus > 0;
		$isAs16 = Request::GetProtocolVersion() >= 16.0;

		// Get timezone info
		if (isset($appointment->timezone)) {
			$tz = $this->getTZFromSyncBlob(base64_decode($appointment->timezone));
		}
		// AS 16: doesn't sent a timezone - use server TZ
		elseif ($isAs16 && $isAllday) {
			$tz = TimezoneUtil::GetFullTZ();
		}
		else {
			$tz = false;
		}

		$appointmentmapping = MAPIMapping::GetAppointmentMapping();
		$appointmentprops = MAPIMapping::GetAppointmentProperties();
		$appointmentprops = array_merge($this->getPropIdsFromStrings($appointmentmapping), $this->getPropIdsFromStrings($appointmentprops));

		// AS 16: incoming instanceid means we need to create/update an appointment exception
		if ($isAs16 && isset($appointment->instanceid) && $appointment->instanceid) {
			// this property wasn't decoded so use Utils->ParseDate to convert it into a timestamp and get basedate from it
			$instanceid = Utils::ParseDate($appointment->instanceid);
			$basedate = $this->getDayStartOfTimestamp($instanceid);

			// get compatible TZ data
			$props = [$appointmentprops["timezonetag"], $appointmentprops["isrecurring"]];
			$tzprop = $this->getProps($mapimessage, $props);
			$tz = $this->getTZFromMAPIBlob($tzprop[$appointmentprops["timezonetag"]]);

			if ($appointmentprops["isrecurring"] == false) {
				SLog::Write(LOGLEVEL_INFO, sprintf("MAPIProvider->setAppointment(): Cannot modify exception instanceId '%s' as target appointment is not recurring. Ignoring.", $appointment->instanceid));

				return false;
			}
			// get a recurrence object
			$recurrence = new Recurrence($this->store, $mapimessage);

			// check if the exception is to be deleted
			if (isset($appointment->instanceiddelete) && $appointment->instanceiddelete === true) {
				// Delete exception
				$recurrence->createException([], $basedate, true);
			}
			// create or update the exception
			else {
				$exceptionprops = [];

				if (isset($appointment->starttime)) {
					$exceptionprops[$appointmentprops["starttime"]] = $appointment->starttime;
				}
				if (isset($appointment->endtime)) {
					$exceptionprops[$appointmentprops["endtime"]] = $appointment->endtime;
				}
				if (isset($appointment->subject)) {
					$exceptionprops[$appointmentprops["subject"]] = $appointment->subject;
				}
				if (isset($appointment->location)) {
					$exceptionprops[$appointmentprops["location"]] = $appointment->location;
				}
				if (isset($appointment->busystatus)) {
					$exceptionprops[$appointmentprops["busystatus"]] = $appointment->busystatus;
				}
				if (isset($appointment->reminder)) {
					$exceptionprops[$appointmentprops["reminderset"]] = 1;
					$exceptionprops[$appointmentprops["remindertime"]] = $appointment->reminder;
				}
				if (isset($appointment->alldayevent)) {
					$exceptionprops[$appointmentprops["alldayevent"]] = $mapiexception["alldayevent"] = $appointment->alldayevent;
				}
				if (isset($appointment->body)) {
					$exceptionprops[$appointmentprops["body"]] = $appointment->body;
				}
				if (isset($appointment->asbody)) {
					$this->setASbody($appointment->asbody, $exceptionprops, $appointmentprops);
				}
				if (isset($appointment->location2)) {
					$this->setASlocation($appointment->location2, $exceptionprops, $appointmentprops);
				}

				// modify if exists else create exception
				if ($recurrence->isException($basedate)) {
					$recurrence->modifyException($exceptionprops, $basedate);
				}
				else {
					$recurrence->createException($exceptionprops, $basedate);
				}
			}

			// instantiate the MR so we can send a updates to the attendees
			$mr = new Meetingrequest($this->store, $mapimessage, $this->session);
			$mr->updateMeetingRequest($basedate);
			$deleteException = isset($appointment->instanceiddelete) && $appointment->instanceiddelete === true;
			$mr->sendMeetingRequest($deleteException, false, $basedate);

			return $response;
		}

		// Save OldProps to later check which data is being changed
		$oldProps = $this->getProps($mapimessage, $appointmentprops);

		// start and end time may not be set - try to get them from the existing appointment for further calculation.
		if (!isset($appointment->starttime) || !isset($appointment->endtime)) {
			$amapping = MAPIMapping::GetAppointmentMapping();
			$amapping = $this->getPropIdsFromStrings($amapping);
			$existingstartendpropsmap = [$amapping["starttime"], $amapping["endtime"]];
			$existingstartendprops = $this->getProps($mapimessage, $existingstartendpropsmap);

			if (isset($existingstartendprops[$amapping["starttime"]]) && !isset($appointment->starttime)) {
				$appointment->starttime = $existingstartendprops[$amapping["starttime"]];
				SLog::Write(LOGLEVEL_WBXML, sprintf("MAPIProvider->setAppointment(): Parameter 'starttime' was not set, using value from MAPI %d (%s).", $appointment->starttime, Utils::FormatDate($appointment->starttime)));
			}
			if (isset($existingstartendprops[$amapping["endtime"]]) && !isset($appointment->endtime)) {
				$appointment->endtime = $existingstartendprops[$amapping["endtime"]];
				SLog::Write(LOGLEVEL_WBXML, sprintf("MAPIProvider->setAppointment(): Parameter 'endtime' was not set, using value from MAPI %d (%s).", $appointment->endtime, Utils::FormatDate($appointment->endtime)));
			}
		}
		if (!isset($appointment->starttime) || !isset($appointment->endtime)) {
			throw new StatusException("MAPIProvider->setAppointment(): Error, start and/or end time not set and can not be retrieved from MAPI.", SYNC_STATUS_SYNCCANNOTBECOMPLETED);
		}

		// calculate duration because without it some webaccess views are broken. duration is in min
		$localstart = $this->getLocaltimeByTZ($appointment->starttime, $tz);
		$localend = $this->getLocaltimeByTZ($appointment->endtime, $tz);
		$duration = ($localend - $localstart) / 60;

		// nokia sends an yearly event with 0 mins duration but as all day event,
		// so make it end next day
		if ($appointment->starttime == $appointment->endtime && $isAllday) {
			$duration = 1440;
			$appointment->endtime = $appointment->starttime + 24 * 60 * 60;
			$localend = $localstart + 24 * 60 * 60;
		}

		// use clientUID if set
		if ($appointment->clientuid && !$appointment->uid) {
			$appointment->uid = $appointment->clientuid;
			// Facepalm: iOS sends weird ids (without dashes and a trailing null character)
			if (strlen($appointment->uid) == 33) {
				$appointment->uid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($appointment->uid, 4));
			}
		}
		// is the transmitted UID OL compatible?
		if ($appointment->uid && substr($appointment->uid, 0, 16) != "040000008200E000") {
			// if not, encapsulate the transmitted uid
			$appointment->uid = getGoidFromUid($appointment->uid);
		}
		// if there was a clientuid transport the new UID to the response
		if ($appointment->clientuid) {
			$response->uid = bin2hex($appointment->uid);
			$response->hasResponse = true;
		}

		mapi_setprops($mapimessage, [PR_MESSAGE_CLASS => "IPM.Appointment"]);

		$this->setPropsInMAPI($mapimessage, $appointment, $appointmentmapping);

		// appointment specific properties to be set
		$props = [];

		// sensitivity is not enough to mark an appointment as private, so we use another mapi tag
		$private = (isset($appointment->sensitivity) && $appointment->sensitivity >= SENSITIVITY_PRIVATE) ? true : false;

		// Set commonstart/commonend to start/end and remindertime to start, duration, private and cleanGlobalObjectId
		$props[$appointmentprops["commonstart"]] = $appointment->starttime;
		$props[$appointmentprops["commonend"]] = $appointment->endtime;
		$props[$appointmentprops["reminderstart"]] = $appointment->starttime;
		// Set reminder boolean to 'true' if reminder is set
		$props[$appointmentprops["reminderset"]] = isset($appointment->reminder) ? true : false;
		$props[$appointmentprops["duration"]] = $duration;
		$props[$appointmentprops["private"]] = $private;
		$props[$appointmentprops["uid"]] = $appointment->uid;
		// Set named prop 8510, unknown property, but enables deleting a single occurrence of a recurring
		// type in OLK2003.
		$props[$appointmentprops["sideeffects"]] = 369;

		if (isset($appointment->reminder) && $appointment->reminder >= 0) {
			// Set 'flagdueby' to correct value (start - reminderminutes)
			$props[$appointmentprops["flagdueby"]] = $appointment->starttime - $appointment->reminder * 60;
			$props[$appointmentprops["remindertime"]] = $appointment->reminder;
		}
		// unset the reminder
		else {
			$props[$appointmentprops["reminderset"]] = false;
		}

		if (isset($appointment->asbody)) {
			$this->setASbody($appointment->asbody, $props, $appointmentprops);
		}

		if (isset($appointment->location2)) {
			$this->setASlocation($appointment->location2, $props, $appointmentprops);
		}
		if ($tz !== false) {
			if (!($isAs16 && $isAllday)) {
				$props[$appointmentprops["timezonetag"]] = $this->getMAPIBlobFromTZ($tz);
			}
		}

		if (isset($appointment->recurrence)) {
			// Set PR_ICON_INDEX to 1025 to show correct icon in category view
			$props[$appointmentprops["icon"]] = 1025;

			// if there aren't any exceptions, use the 'old style' set recurrence
			$noexceptions = true;

			$recurrence = new Recurrence($this->store, $mapimessage);
			$recur = [];
			$this->setRecurrence($appointment, $recur);

			// set the recurrence type to that of the MAPI
			$props[$appointmentprops["recurrencetype"]] = $recur["recurrencetype"];

			$starttime = $this->gmtime($localstart);
			$endtime = $this->gmtime($localend);

			// set recurrence start here because it's calculated differently for tasks and appointments
			$recur["start"] = $this->getDayStartOfTimestamp($this->getGMTTimeByTZ($localstart, $tz));

			$recur["startocc"] = $starttime["tm_hour"] * 60 + $starttime["tm_min"];
			$recur["endocc"] = $recur["startocc"] + $duration; // Note that this may be > 24*60 if multi-day

			// only tasks can regenerate
			$recur["regen"] = false;

			// Process exceptions. The PDA will send all exceptions for this recurring item.
			if (isset($appointment->exceptions)) {
				foreach ($appointment->exceptions as $exception) {
					// we always need the base date
					if (!isset($exception->exceptionstarttime)) {
						continue;
					}

					$basedate = $this->getDayStartOfTimestamp($exception->exceptionstarttime);
					if (isset($exception->deleted) && $exception->deleted) {
						$noexceptions = false;
						// Delete exception
						$recurrence->createException([], $basedate, true);
					}
					else {
						// Change exception
						$mapiexception = ["basedate" => $basedate];
						// other exception properties which are not handled in recurrence
						$exceptionprops = [];

						if (isset($exception->starttime)) {
							$mapiexception["start"] = $this->getLocaltimeByTZ($exception->starttime, $tz);
							$exceptionprops[$appointmentprops["starttime"]] = $exception->starttime;
						}
						if (isset($exception->endtime)) {
							$mapiexception["end"] = $this->getLocaltimeByTZ($exception->endtime, $tz);
							$exceptionprops[$appointmentprops["endtime"]] = $exception->endtime;
						}
						if (isset($exception->subject)) {
							$exceptionprops[$appointmentprops["subject"]] = $mapiexception["subject"] = $exception->subject;
						}
						if (isset($exception->location)) {
							$exceptionprops[$appointmentprops["location"]] = $mapiexception["location"] = $exception->location;
						}
						if (isset($exception->busystatus)) {
							$exceptionprops[$appointmentprops["busystatus"]] = $mapiexception["busystatus"] = $exception->busystatus;
						}
						if (isset($exception->reminder)) {
							$exceptionprops[$appointmentprops["reminderset"]] = $mapiexception["reminder_set"] = 1;
							$exceptionprops[$appointmentprops["remindertime"]] = $mapiexception["remind_before"] = $exception->reminder;
						}
						if (isset($exception->alldayevent)) {
							$exceptionprops[$appointmentprops["alldayevent"]] = $mapiexception["alldayevent"] = $exception->alldayevent;
						}

						if (!isset($recur["changed_occurrences"])) {
							$recur["changed_occurrences"] = [];
						}

						if (isset($exception->body)) {
							$exceptionprops[$appointmentprops["body"]] = $exception->body;
						}

						if (isset($exception->asbody)) {
							$this->setASbody($exception->asbody, $exceptionprops, $appointmentprops);
							$mapiexception["body"] = $exceptionprops[$appointmentprops["body"]] =
								(isset($exceptionprops[$appointmentprops["body"]])) ? $exceptionprops[$appointmentprops["body"]] :
								((isset($exceptionprops[$appointmentprops["html"]])) ? $exceptionprops[$appointmentprops["html"]] : "");
						}

						array_push($recur["changed_occurrences"], $mapiexception);

						if (!empty($exceptionprops)) {
							$noexceptions = false;
							if ($recurrence->isException($basedate)) {
								$recurrence->modifyException($exceptionprops, $basedate);
							}
							else {
								$recurrence->createException($exceptionprops, $basedate);
							}
						}
					}
				}
			}

			// setRecurrence deletes the attachments from an appointment
			if ($noexceptions) {
				$recurrence->setRecurrence($tz, $recur);
			}
		}
		else {
			$props[$appointmentprops["isrecurring"]] = false;
			// remove recurringstate
			mapi_deleteprops($mapimessage, [$appointmentprops["recurringstate"]]);
		}

		// always set the PR_SENT_REPRESENTING_* props so that the attendee status update also works with the webaccess
		$p = [$appointmentprops["representingentryid"], $appointmentprops["representingname"], $appointmentprops["sentrepresentingaddt"],
			$appointmentprops["sentrepresentingemail"], $appointmentprops["sentrepresentinsrchk"], $appointmentprops["responsestatus"], ];
		$representingprops = $this->getProps($mapimessage, $p);

		$storeProps = $this->GetStoreProps();
		$abEntryProps = $this->getAbPropsFromEntryID($storeProps[PR_MAILBOX_OWNER_ENTRYID]);
		if (!isset($representingprops[$appointmentprops["representingentryid"]])) {
			$displayname = $sentrepresentingemail = Request::GetUser();
			$sentrepresentingaddt = 'SMPT';
			if ($abEntryProps !== false) {
				$displayname = $abEntryProps[PR_DISPLAY_NAME] ?? $displayname;
				$sentrepresentingemail = $abEntryProps[PR_EMAIL_ADDRESS] ?? $abEntryProps[PR_SMTP_ADDRESS] ?? $sentrepresentingemail;
				$sentrepresentingaddt = $abEntryProps[PR_ADDRTYPE] ?? $sentrepresentingaddt;
			}
			$props[$appointmentprops["representingentryid"]] = $storeProps[PR_MAILBOX_OWNER_ENTRYID];
			$props[$appointmentprops["representingname"]] = $displayname;
			$props[$appointmentprops["sentrepresentingemail"]] = $sentrepresentingemail;
			$props[$appointmentprops["sentrepresentingaddt"]] = $sentrepresentingaddt;
			$props[$appointmentprops["sentrepresentinsrchk"]] = $props[$appointmentprops["sentrepresentingaddt"]] . ":" . $props[$appointmentprops["sentrepresentingemail"]];

			if (isset($appointment->attendees) && is_array($appointment->attendees) && !empty($appointment->attendees)) {
				$props[$appointmentprops["icon"]] = 1026;
				// the user is the organizer
				// set these properties to show tracking tab in webapp
				$props[$appointmentprops["responsestatus"]] = olResponseOrganized;
				$props[$appointmentprops["meetingstatus"]] = olMeeting;
			}
		}
		// we also have to set the responsestatus and not only meetingstatus, so we use another mapi tag
		if (!isset($props[$appointmentprops["responsestatus"]])) {
			if (isset($appointment->responsetype)) {
				$props[$appointmentprops["responsestatus"]] = $appointment->responsetype;
			}
			// only set responsestatus to none if it is not set on the server
			elseif (!isset($representingprops[$appointmentprops["responsestatus"]])) {
				$props[$appointmentprops["responsestatus"]] = olResponseNone;
			}
		}

		// when updating a normal appointment to a MR we need to send MR emails
		$forceMRUpdateSend = false;

		// Do attendees
		// For AS-16 get a list of the current attendees (pre update)
		if ($isAs16 && $isMeeting) {
			$old_recipienttable = mapi_message_getrecipienttable($mapimessage);
			$old_receipstable = mapi_table_queryallrows(
				$old_recipienttable,
				[
					PR_ENTRYID,
					PR_DISPLAY_NAME,
					PR_EMAIL_ADDRESS,
					PR_RECIPIENT_ENTRYID,
					PR_RECIPIENT_TYPE,
					PR_SEND_INTERNET_ENCODING,
					PR_SEND_RICH_INFO,
					PR_RECIPIENT_DISPLAY_NAME,
					PR_ADDRTYPE,
					PR_DISPLAY_TYPE,
					PR_DISPLAY_TYPE_EX,
					PR_RECIPIENT_TRACKSTATUS,
					PR_RECIPIENT_TRACKSTATUS_TIME,
					PR_RECIPIENT_FLAGS,
					PR_ROWID,
					PR_OBJECT_TYPE,
					PR_SEARCH_KEY,
				]
			);
			$old_receips = [];
			foreach ($old_receipstable as $oldrec) {
				if (isset($oldrec[PR_EMAIL_ADDRESS])) {
					$old_receips[$oldrec[PR_EMAIL_ADDRESS]] = $oldrec;
				}
			}
		}

		if (isset($appointment->attendees) && is_array($appointment->attendees)) {
			$recips = [];

			// Outlook XP requires organizer in the attendee list as well
			// Only add organizer if it's a meeting
			if ($isMeeting) {
				$org = [];
				$org[PR_ENTRYID] = isset($representingprops[$appointmentprops["representingentryid"]]) ? $representingprops[$appointmentprops["representingentryid"]] : $props[$appointmentprops["representingentryid"]];
				$org[PR_DISPLAY_NAME] = isset($representingprops[$appointmentprops["representingname"]]) ? $representingprops[$appointmentprops["representingname"]] : $props[$appointmentprops["representingname"]];
				$org[PR_ADDRTYPE] = isset($representingprops[$appointmentprops["sentrepresentingaddt"]]) ? $representingprops[$appointmentprops["sentrepresentingaddt"]] : $props[$appointmentprops["sentrepresentingaddt"]];
				$org[PR_SMTP_ADDRESS] = $org[PR_EMAIL_ADDRESS] = isset($representingprops[$appointmentprops["sentrepresentingemail"]]) ? $representingprops[$appointmentprops["sentrepresentingemail"]] : $props[$appointmentprops["sentrepresentingemail"]];
				$org[PR_SEARCH_KEY] = isset($representingprops[$appointmentprops["sentrepresentinsrchk"]]) ? $representingprops[$appointmentprops["sentrepresentinsrchk"]] : $props[$appointmentprops["sentrepresentinsrchk"]];
				$org[PR_RECIPIENT_FLAGS] = recipOrganizer | recipSendable;
				$org[PR_RECIPIENT_TYPE] = MAPI_ORIG;
				$org[PR_RECIPIENT_TRACKSTATUS] = olResponseOrganized;
				if ($abEntryProps !== false && isset($abEntryProps[PR_SMTP_ADDRESS])) {
					$org[PR_SMTP_ADDRESS] = $abEntryProps[PR_SMTP_ADDRESS];
				}

				array_push($recips, $org);
				// remove organizer from old_receips
				if (isset($old_receips[$org[PR_EMAIL_ADDRESS]])) {
					unset($old_receips[$org[PR_EMAIL_ADDRESS]]);
				}
			}

			// Open address book for user resolve
			$addrbook = $this->getAddressbook();
			foreach ($appointment->attendees as $attendee) {
				$recip = [];
				$recip[PR_EMAIL_ADDRESS] = $recip[PR_SMTP_ADDRESS] = $attendee->email;

				// lookup information in GAB if possible so we have up-to-date name for given address
				$userinfo = [[PR_DISPLAY_NAME => $recip[PR_EMAIL_ADDRESS]]];
				$userinfo = mapi_ab_resolvename($addrbook, $userinfo, EMS_AB_ADDRESS_LOOKUP);
				if (mapi_last_hresult() == NOERROR) {
					$recip[PR_DISPLAY_NAME] = $userinfo[0][PR_DISPLAY_NAME];
					$recip[PR_EMAIL_ADDRESS] = $userinfo[0][PR_EMAIL_ADDRESS];
					$recip[PR_SEARCH_KEY] = $userinfo[0][PR_SEARCH_KEY];
					$recip[PR_ADDRTYPE] = $userinfo[0][PR_ADDRTYPE];
					$recip[PR_ENTRYID] = $userinfo[0][PR_ENTRYID];
					$recip[PR_RECIPIENT_TYPE] = isset($attendee->attendeetype) ? $attendee->attendeetype : MAPI_TO;
					$recip[PR_RECIPIENT_FLAGS] = recipSendable;
					$recip[PR_RECIPIENT_TRACKSTATUS] = isset($attendee->attendeestatus) ? $attendee->attendeestatus : olResponseNone;
				}
				else {
					$recip[PR_DISPLAY_NAME] = $attendee->name;
					$recip[PR_SEARCH_KEY] = "SMTP:" . $recip[PR_EMAIL_ADDRESS] . "\0";
					$recip[PR_ADDRTYPE] = "SMTP";
					$recip[PR_RECIPIENT_TYPE] = isset($attendee->attendeetype) ? $attendee->attendeetype : MAPI_TO;
					$recip[PR_ENTRYID] = mapi_createoneoff($recip[PR_DISPLAY_NAME], $recip[PR_ADDRTYPE], $recip[PR_EMAIL_ADDRESS]);
				}

				// remove still existing attendees from the list of pre-update attendees - remaining pre-update are considered deleted attendees
				if (isset($old_receips[$recip[PR_EMAIL_ADDRESS]])) {
					unset($old_receips[$recip[PR_EMAIL_ADDRESS]]);
				}
				// if there is a new attendee a MR update must be send -> Appointment to MR update
				else {
					$forceMRUpdateSend = true;
				}
				// the organizer is already in the recipient list, no need to add him again
				if (isset($org[PR_EMAIL_ADDRESS]) && strcasecmp($org[PR_EMAIL_ADDRESS], $recip[PR_EMAIL_ADDRESS]) == 0) {
					continue;
				}
				array_push($recips, $recip);
			}

			mapi_message_modifyrecipients($mapimessage, ($appointment->clientuid) ? MODRECIP_ADD : MODRECIP_MODIFY, $recips);
		}
		mapi_setprops($mapimessage, $props);

		// Since AS 16 we have to take care of MeetingRequest updates
		if ($isAs16 && $isMeeting) {
			$mr = new Meetingrequest($this->store, $mapimessage, $this->session);
			// Only send updates if this is a new MR or we are the organizer
			if ($appointment->clientuid || $mr->isLocalOrganiser() || $forceMRUpdateSend) {
				// initialize MR and/or update internal counters
				$mr->updateMeetingRequest();
				// when updating, check for significant changes and if needed will clear the existing recipient responses
				if (!isset($appointment->clientuid) && !$forceMRUpdateSend) {
					$mr->checkSignificantChanges($oldProps, false, false);
				}
				$mr->sendMeetingRequest(false, false, false, false, array_values($old_receips));
			}
		}

		// update attachments send by the mobile
		if (!empty($appointment->asattachments)) {
			$this->editAttachments($mapimessage, $appointment->asattachments, $response);
		}

		// Existing allday events may have tzdef* properties set,
		// so it's necessary to set them to UTC in order for other clients
		// to display such events properly.
		if ($isAllday && (
			isset($oldProps[$appointmentprops['tzdefstart']]) ||
			isset($oldProps[$appointmentprops['tzdefend']])
		)) {
			$utc = TimezoneUtil::GetBinaryTZ('Etc/Utc');
			if ($utc !== false) {
				mapi_setprops($mapimessage, [
					$appointmentprops['tzdefstart'] => $utc,
					$appointmentprops['tzdefend'] => $utc,
				]);
			}
		}

		return $response;
	}

	/**
	 * Writes a SyncContact to MAPI.
	 *
	 * @param mixed       $mapimessage
	 * @param SyncContact $contact
	 *
	 * @return bool
	 */
	private function setContact($mapimessage, $contact) {
		$response = new SyncContactResponse();
		mapi_setprops($mapimessage, [PR_MESSAGE_CLASS => "IPM.Contact"]);

		// normalize email addresses
		if (isset($contact->email1address) && (($contact->email1address = $this->extractEmailAddress($contact->email1address)) === false)) {
			unset($contact->email1address);
		}

		if (isset($contact->email2address) && (($contact->email2address = $this->extractEmailAddress($contact->email2address)) === false)) {
			unset($contact->email2address);
		}

		if (isset($contact->email3address) && (($contact->email3address = $this->extractEmailAddress($contact->email3address)) === false)) {
			unset($contact->email3address);
		}

		$contactmapping = MAPIMapping::GetContactMapping();
		$contactprops = MAPIMapping::GetContactProperties();
		$this->setPropsInMAPI($mapimessage, $contact, $contactmapping);

		// /set display name from contact's properties
		$cname = $this->composeDisplayName($contact);

		// get contact specific mapi properties and merge them with the AS properties
		$contactprops = array_merge($this->getPropIdsFromStrings($contactmapping), $this->getPropIdsFromStrings($contactprops));

		// contact specific properties to be set
		$props = [];

		// need to be set in order to show contacts properly in outlook and wa
		$nremails = [];
		$abprovidertype = 0;

		if (isset($contact->email1address)) {
			$this->setEmailAddress($contact->email1address, $cname, 1, $props, $contactprops, $nremails, $abprovidertype);
		}
		if (isset($contact->email2address)) {
			$this->setEmailAddress($contact->email2address, $cname, 2, $props, $contactprops, $nremails, $abprovidertype);
		}
		if (isset($contact->email3address)) {
			$this->setEmailAddress($contact->email3address, $cname, 3, $props, $contactprops, $nremails, $abprovidertype);
		}

		$props[$contactprops["addressbooklong"]] = $abprovidertype;
		$props[$contactprops["displayname"]] = $props[$contactprops["subject"]] = $cname;

		// pda multiple e-mail addresses bug fix for the contact
		if (!empty($nremails)) {
			$props[$contactprops["addressbookmv"]] = $nremails;
		}

		// set addresses
		$this->setAddress("home", $contact->homecity, $contact->homecountry, $contact->homepostalcode, $contact->homestate, $contact->homestreet, $props, $contactprops);
		$this->setAddress("business", $contact->businesscity, $contact->businesscountry, $contact->businesspostalcode, $contact->businessstate, $contact->businessstreet, $props, $contactprops);
		$this->setAddress("other", $contact->othercity, $contact->othercountry, $contact->otherpostalcode, $contact->otherstate, $contact->otherstreet, $props, $contactprops);

		// set the mailing address and its type
		if (isset($props[$contactprops["businessaddress"]])) {
			$props[$contactprops["mailingaddress"]] = 2;
			$this->setMailingAddress($contact->businesscity, $contact->businesscountry, $contact->businesspostalcode, $contact->businessstate, $contact->businessstreet, $props[$contactprops["businessaddress"]], $props, $contactprops);
		}
		elseif (isset($props[$contactprops["homeaddress"]])) {
			$props[$contactprops["mailingaddress"]] = 1;
			$this->setMailingAddress($contact->homecity, $contact->homecountry, $contact->homepostalcode, $contact->homestate, $contact->homestreet, $props[$contactprops["homeaddress"]], $props, $contactprops);
		}
		elseif (isset($props[$contactprops["otheraddress"]])) {
			$props[$contactprops["mailingaddress"]] = 3;
			$this->setMailingAddress($contact->othercity, $contact->othercountry, $contact->otherpostalcode, $contact->otherstate, $contact->otherstreet, $props[$contactprops["otheraddress"]], $props, $contactprops);
		}

		if (isset($contact->picture)) {
			$picbinary = base64_decode($contact->picture);
			$picsize = strlen($picbinary);
			$props[$contactprops["haspic"]] = false;

			// TODO contact picture handling
			// check if contact has already got a picture. delete it first in that case
			// delete it also if it was removed on a mobile
			$picprops = mapi_getprops($mapimessage, [$contactprops["haspic"]]);
			if (isset($picprops[$contactprops["haspic"]]) && $picprops[$contactprops["haspic"]]) {
				SLog::Write(LOGLEVEL_DEBUG, "Contact already has a picture. Delete it");

				$attachtable = mapi_message_getattachmenttable($mapimessage);
				mapi_table_restrict($attachtable, MAPIUtils::GetContactPicRestriction());
				$rows = mapi_table_queryallrows($attachtable, [PR_ATTACH_NUM]);
				if (isset($rows) && is_array($rows)) {
					foreach ($rows as $row) {
						mapi_message_deleteattach($mapimessage, $row[PR_ATTACH_NUM]);
					}
				}
			}

			// only set picture if there's data in the request
			if ($picbinary !== false && $picsize > 0) {
				$props[$contactprops["haspic"]] = true;
				$pic = mapi_message_createattach($mapimessage);
				// Set properties of the attachment
				$picprops = [
					PR_ATTACH_LONG_FILENAME => "ContactPicture.jpg",
					PR_DISPLAY_NAME => "ContactPicture.jpg",
					0x7FFF000B => true,
					PR_ATTACHMENT_HIDDEN => false,
					PR_ATTACHMENT_FLAGS => 1,
					PR_ATTACH_METHOD => ATTACH_BY_VALUE,
					PR_ATTACH_EXTENSION => ".jpg",
					PR_ATTACH_NUM => 1,
					PR_ATTACH_SIZE => $picsize,
					PR_ATTACH_DATA_BIN => $picbinary,
				];

				mapi_setprops($pic, $picprops);
				mapi_savechanges($pic);
			}
		}

		if (isset($contact->asbody)) {
			$this->setASbody($contact->asbody, $props, $contactprops);
		}

		// set fileas
		if (defined('FILEAS_ORDER')) {
			$lastname = (isset($contact->lastname)) ? $contact->lastname : "";
			$firstname = (isset($contact->firstname)) ? $contact->firstname : "";
			$middlename = (isset($contact->middlename)) ? $contact->middlename : "";
			$company = (isset($contact->companyname)) ? $contact->companyname : "";
			$props[$contactprops["fileas"]] = Utils::BuildFileAs($lastname, $firstname, $middlename, $company);
		}
		else {
			SLog::Write(LOGLEVEL_DEBUG, "FILEAS_ORDER not defined");
		}

		mapi_setprops($mapimessage, $props);

		return $response;
	}

	/**
	 * Writes a SyncTask to MAPI.
	 *
	 * @param mixed    $mapimessage
	 * @param SyncTask $task
	 *
	 * @return bool
	 */
	private function setTask($mapimessage, $task) {
		$response = new SyncTaskResponse();
		mapi_setprops($mapimessage, [PR_MESSAGE_CLASS => "IPM.Task"]);

		$taskmapping = MAPIMapping::GetTaskMapping();
		$taskprops = MAPIMapping::GetTaskProperties();
		$this->setPropsInMAPI($mapimessage, $task, $taskmapping);
		$taskprops = array_merge($this->getPropIdsFromStrings($taskmapping), $this->getPropIdsFromStrings($taskprops));

		// task specific properties to be set
		$props = [];

		if (isset($task->asbody)) {
			$this->setASbody($task->asbody, $props, $taskprops);
		}

		if (isset($task->complete)) {
			if ($task->complete) {
				// Set completion to 100%
				// Set status to 'complete'
				$props[$taskprops["completion"]] = 1.0;
				$props[$taskprops["status"]] = 2;
				$props[$taskprops["reminderset"]] = false;
			}
			else {
				// Set completion to 0%
				// Set status to 'not started'
				$props[$taskprops["completion"]] = 0.0;
				$props[$taskprops["status"]] = 0;
			}
		}
		if (isset($task->recurrence) && class_exists('TaskRecurrence')) {
			$deadoccur = false;
			if ((isset($task->recurrence->occurrences) && $task->recurrence->occurrences == 1) ||
				(isset($task->recurrence->deadoccur) && $task->recurrence->deadoccur == 1)) { // ios5 sends deadoccur inside the recurrence
				$deadoccur = true;
			}

			// Set PR_ICON_INDEX to 1281 to show correct icon in category view
			$props[$taskprops["icon"]] = 1281;
			// dead occur - false if new occurrences should be generated from the task
			// true - if it is the last occurrence of the task
			$props[$taskprops["deadoccur"]] = $deadoccur;
			$props[$taskprops["isrecurringtag"]] = true;

			$recurrence = new TaskRecurrence($this->store, $mapimessage);
			$recur = [];
			$this->setRecurrence($task, $recur);

			// task specific recurrence properties which we need to set here
			// "start" and "end" are in GMT when passing to class.recurrence
			// set recurrence start here because it's calculated differently for tasks and appointments
			$recur["start"] = $task->recurrence->start;
			$recur["regen"] = (isset($task->recurrence->regenerate) && $task->recurrence->regenerate) ? 1 : 0;
			// OL regenerates recurring task itself, but setting deleteOccurrence is required so that PHP-MAPI doesn't regenerate
			// completed occurrence of a task.
			if ($recur["regen"] == 0) {
				$recur["deleteOccurrence"] = 0;
			}
			// Also add dates to $recur
			$recur["duedate"] = $task->duedate;
			$recur["complete"] = (isset($task->complete) && $task->complete) ? 1 : 0;
			if (isset($task->datecompleted)) {
				$recur["datecompleted"] = $task->datecompleted;
			}
			$recurrence->setRecurrence($recur);
		}

		$props[$taskprops["private"]] = (isset($task->sensitivity) && $task->sensitivity >= SENSITIVITY_PRIVATE) ? true : false;

		// Open address book for user resolve to set the owner
		$addrbook = $this->getAddressbook();

		// check if there is already an owner for the task, set current user if not
		$p = [$taskprops["owner"]];
		$owner = $this->getProps($mapimessage, $p);
		if (!isset($owner[$taskprops["owner"]])) {
			$userinfo = nsp_getuserinfo(Request::GetUserIdentifier());
			if (mapi_last_hresult() == NOERROR && isset($userinfo["fullname"])) {
				$props[$taskprops["owner"]] = $userinfo["fullname"];
			}
		}
		mapi_setprops($mapimessage, $props);

		return $response;
	}

	/**
	 * Writes a SyncNote to MAPI.
	 *
	 * @param mixed    $mapimessage
	 * @param SyncNote $note
	 *
	 * @return bool
	 */
	private function setNote($mapimessage, $note) {
		$response = new SyncNoteResponse();
		// Touchdown does not send categories if all are unset or there is none.
		// Setting it to an empty array will unset the property in gromox as well
		if (!isset($note->categories)) {
			$note->categories = [];
		}

		// update icon index to correspond to the color
		if (isset($note->Color) && $note->Color > -1 && $note->Color < 5) {
			$note->Iconindex = 768 + $note->Color;
		}

		$this->setPropsInMAPI($mapimessage, $note, MAPIMapping::GetNoteMapping());

		$noteprops = MAPIMapping::GetNoteProperties();
		$noteprops = $this->getPropIdsFromStrings($noteprops);

		// note specific properties to be set
		$props = [];
		$props[$noteprops["messageclass"]] = "IPM.StickyNote";
		// set body otherwise the note will be "broken" when editing it in outlook
		if (isset($note->asbody)) {
			$this->setASbody($note->asbody, $props, $noteprops);
		}

		$props[$noteprops["internetcpid"]] = INTERNET_CPID_UTF8;
		mapi_setprops($mapimessage, $props);

		return $response;
	}

	/*----------------------------------------------------------------------------------------------------------
	 * HELPER
	 */

	/**
	 * Returns the timestamp offset.
	 *
	 * @param string $ts
	 *
	 * @return long
	 */
	private function GetTZOffset($ts) {
		$Offset = date("O", $ts);

		$Parity = $Offset < 0 ? -1 : 1;
		$Offset *= $Parity;
		$Offset = ($Offset - ($Offset % 100)) / 100 * 60 + $Offset % 100;

		return $Parity * $Offset;
	}

	/**
	 * UTC time of the timestamp.
	 *
	 * @param long $time
	 *
	 * @return array
	 */
	private function gmtime($time) {
		$TZOffset = $this->GetTZOffset($time);

		$t_time = $time - $TZOffset * 60; # Counter adjust for localtime()

		return localtime($t_time, 1);
	}

	/**
	 * Sets the properties in a MAPI object according to an Sync object and a property mapping.
	 *
	 * @param mixed      $mapimessage
	 * @param SyncObject $message
	 * @param array      $mapping
	 */
	private function setPropsInMAPI($mapimessage, $message, $mapping) {
		$mapiprops = $this->getPropIdsFromStrings($mapping);
		$unsetVars = $message->getUnsetVars();
		$propsToDelete = [];
		$propsToSet = [];

		foreach ($mapiprops as $asprop => $mapiprop) {
			if (isset($message->{$asprop})) {
				$value = $message->{$asprop};

				// Make sure the php values are the correct type
				switch (mapi_prop_type($mapiprop)) {
					case PT_BINARY:
					case PT_STRING8:
						settype($value, "string");
						break;

					case PT_BOOLEAN:
						settype($value, "boolean");
						break;

					case PT_SYSTIME:
					case PT_LONG:
						settype($value, "integer");
						break;
				}

				// if an "empty array" is to be saved, it the mvprop should be deleted - fixes Mantis #468
				if (is_array($value) && empty($value)) {
					$propsToDelete[] = $mapiprop;
					SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIProvider->setPropsInMAPI(): Property '%s' to be deleted as it is an empty array", $asprop));
				}
				else {
					// all properties will be set at once
					$propsToSet[$mapiprop] = $value;
				}
			}
			elseif (in_array($asprop, $unsetVars)) {
				$propsToDelete[] = $mapiprop;
			}
		}

		mapi_setprops($mapimessage, $propsToSet);
		if (mapi_last_hresult()) {
			SLog::Write(LOGLEVEL_WARN, sprintf("Failed to set properties, trying to set them separately. Error code was:%x", mapi_last_hresult()));
			$this->setPropsIndividually($mapimessage, $propsToSet, $mapiprops);
		}

		mapi_deleteprops($mapimessage, $propsToDelete);

		// clean up
		unset($unsetVars, $propsToDelete);
	}

	/**
	 * Sets the properties one by one in a MAPI object.
	 *
	 * @param mixed &$mapimessage
	 * @param array &$propsToSet
	 * @param array &$mapiprops
	 */
	private function setPropsIndividually(&$mapimessage, &$propsToSet, &$mapiprops) {
		foreach ($propsToSet as $prop => $value) {
			mapi_setprops($mapimessage, [$prop => $value]);
			if (mapi_last_hresult()) {
				SLog::Write(LOGLEVEL_ERROR, sprintf("Failed setting property [%s] with value [%s], error code was:%x", array_search($prop, $mapiprops), $value, mapi_last_hresult()));
			}
		}
	}

	/**
	 * Gets the properties from a MAPI object and sets them in the Sync object according to mapping.
	 *
	 * @param SyncObject &$message
	 * @param mixed      $mapimessage
	 * @param array      $mapping
	 */
	private function getPropsFromMAPI(&$message, $mapimessage, $mapping) {
		$messageprops = $this->getProps($mapimessage, $mapping);
		foreach ($mapping as $asprop => $mapiprop) {
			// Get long strings via openproperty
			if (isset($messageprops[mapi_prop_tag(PT_ERROR, mapi_prop_id($mapiprop))])) {
				if ($messageprops[mapi_prop_tag(PT_ERROR, mapi_prop_id($mapiprop))] == MAPI_E_NOT_ENOUGH_MEMORY_32BIT ||
					$messageprops[mapi_prop_tag(PT_ERROR, mapi_prop_id($mapiprop))] == MAPI_E_NOT_ENOUGH_MEMORY_64BIT) {
					$messageprops[$mapiprop] = MAPIUtils::readPropStream($mapimessage, $mapiprop);
				}
			}

			if (isset($messageprops[$mapiprop])) {
				if (mapi_prop_type($mapiprop) == PT_BOOLEAN) {
					// Force to actual '0' or '1'
					if ($messageprops[$mapiprop]) {
						$message->{$asprop} = 1;
					}
					else {
						$message->{$asprop} = 0;
					}
				}
				else {
					// Special handling for PR_MESSAGE_FLAGS
					if ($mapiprop == PR_MESSAGE_FLAGS) {
						$message->{$asprop} = $messageprops[$mapiprop] & 1;
					} // only look at 'read' flag
					else {
						$message->{$asprop} = $messageprops[$mapiprop];
					}
				}
			}
		}
	}

	/**
	 * Wraps getPropIdsFromStrings() calls.
	 *
	 * @param mixed &$mapiprops
	 */
	private function getPropIdsFromStrings(&$mapiprops) {
		return getPropIdsFromStrings($this->store, $mapiprops);
	}

	/**
	 * Wraps mapi_getprops() calls.
	 *
	 * @param mixed $mapimessage
	 * @param mixed $mapiproperties
	 */
	protected function getProps($mapimessage, &$mapiproperties) {
		$mapiproperties = $this->getPropIdsFromStrings($mapiproperties);

		return mapi_getprops($mapimessage, $mapiproperties);
	}

	/**
	 * Unpack timezone info from MAPI.
	 *
	 * @param string $data
	 *
	 * @return array
	 */
	private function getTZFromMAPIBlob($data) {
		return unpack("lbias/lstdbias/ldstbias/" .
						   "vconst1/vdstendyear/vdstendmonth/vdstendday/vdstendweek/vdstendhour/vdstendminute/vdstendsecond/vdstendmillis/" .
						   "vconst2/vdststartyear/vdststartmonth/vdststartday/vdststartweek/vdststarthour/vdststartminute/vdststartsecond/vdststartmillis", $data);
	}

	/**
	 * Unpack timezone info from Sync.
	 *
	 * @param string $data
	 *
	 * @return array
	 */
	private function getTZFromSyncBlob($data) {
		$tz = unpack("lbias/a64tzname/vdstendyear/vdstendmonth/vdstendday/vdstendweek/vdstendhour/vdstendminute/vdstendsecond/vdstendmillis/" .
						"lstdbias/a64tznamedst/vdststartyear/vdststartmonth/vdststartday/vdststartweek/vdststarthour/vdststartminute/vdststartsecond/vdststartmillis/" .
						"ldstbias", $data);

		// Make the structure compatible with class.recurrence.php
		$tz["timezone"] = $tz["bias"];
		$tz["timezonedst"] = $tz["dstbias"];

		return $tz;
	}

	/**
	 * Pack timezone info for MAPI.
	 *
	 * @param array $tz
	 *
	 * @return string
	 */
	private function getMAPIBlobFromTZ($tz) {
		return pack(
			"lllvvvvvvvvvvvvvvvvvv",
			$tz["bias"],
			$tz["stdbias"],
			$tz["dstbias"],
			0,
			0,
			$tz["dstendmonth"],
			$tz["dstendday"],
			$tz["dstendweek"],
			$tz["dstendhour"],
			$tz["dstendminute"],
			$tz["dstendsecond"],
			$tz["dstendmillis"],
			0,
			0,
			$tz["dststartmonth"],
			$tz["dststartday"],
			$tz["dststartweek"],
			$tz["dststarthour"],
			$tz["dststartminute"],
			$tz["dststartsecond"],
			$tz["dststartmillis"]
		);
	}

	/**
	 * Checks the date to see if it is in DST, and returns correct GMT date accordingly.
	 *
	 * @param long  $localtime
	 * @param array $tz
	 *
	 * @return long
	 */
	private function getGMTTimeByTZ($localtime, $tz) {
		if (!isset($tz) || !is_array($tz)) {
			return $localtime;
		}

		if ($this->isDST($localtime, $tz)) {
			return $localtime + $tz["bias"] * 60 + $tz["dstbias"] * 60;
		}

		return $localtime + $tz["bias"] * 60;
	}

	/**
	 * Returns the local time for the given GMT time, taking account of the given timezone.
	 *
	 * @param long  $gmttime
	 * @param array $tz
	 *
	 * @return long
	 */
	private function getLocaltimeByTZ($gmttime, $tz) {
		if (!isset($tz) || !is_array($tz)) {
			return $gmttime;
		}

		if ($this->isDST($gmttime - $tz["bias"] * 60, $tz)) { // may bug around the switch time because it may have to be 'gmttime - bias - dstbias'
			return $gmttime - $tz["bias"] * 60 - $tz["dstbias"] * 60;
		}

		return $gmttime - $tz["bias"] * 60;
	}

	/**
	 * Returns TRUE if it is the summer and therefore DST is in effect.
	 *
	 * @param long  $localtime
	 * @param array $tz
	 *
	 * @return bool
	 */
	private function isDST($localtime, $tz) {
		if (!isset($tz) || !is_array($tz) ||
			!isset($tz["dstbias"]) || $tz["dstbias"] == 0 ||
			!isset($tz["dststartmonth"]) || $tz["dststartmonth"] == 0 ||
			!isset($tz["dstendmonth"]) || $tz["dstendmonth"] == 0) {
			return false;
		}

		$year = gmdate("Y", $localtime);
		$start = $this->getTimestampOfWeek($year, $tz["dststartmonth"], $tz["dststartday"], $tz["dststartweek"], $tz["dststarthour"], $tz["dststartminute"], $tz["dststartsecond"]);
		$end = $this->getTimestampOfWeek($year, $tz["dstendmonth"], $tz["dstendday"], $tz["dstendweek"], $tz["dstendhour"], $tz["dstendminute"], $tz["dstendsecond"]);

		if ($start < $end) {
			// northern hemisphere (july = dst)
			if ($localtime >= $start && $localtime < $end) {
				$dst = true;
			}
			else {
				$dst = false;
			}
		}
		else {
			// southern hemisphere (january = dst)
			if ($localtime >= $end && $localtime < $start) {
				$dst = false;
			}
			else {
				$dst = true;
			}
		}

		return $dst;
	}

	/**
	 * Returns the local timestamp for the $week'th $wday of $month in $year at $hour:$minute:$second.
	 *
	 * @param int $year
	 * @param int $month
	 * @param int $week
	 * @param int $wday
	 * @param int $hour
	 * @param int $minute
	 * @param int $second
	 *
	 * @return long
	 */
	private function getTimestampOfWeek($year, $month, $week, $wday, $hour, $minute, $second) {
		if ($month == 0) {
			return;
		}

		$date = gmmktime($hour, $minute, $second, $month, 1, $year);

		// Find first day in month which matches day of the week
		while (1) {
			$wdaynow = gmdate("w", $date);
			if ($wdaynow == $wday) {
				break;
			}
			$date += 24 * 60 * 60;
		}

		// Forward $week weeks (may 'overflow' into the next month)
		$date = $date + $week * (24 * 60 * 60 * 7);

		// Reverse 'overflow'. Eg week '10' will always be the last week of the month in which the
		// specified weekday exists
		while (1) {
			$monthnow = gmdate("n", $date); // gmdate returns 1-12
			if ($monthnow > $month) {
				$date -= (24 * 7 * 60 * 60);
			}
			else {
				break;
			}
		}

		return $date;
	}

	/**
	 * Normalize the given timestamp to the start of the day.
	 *
	 * @param long $timestamp
	 *
	 * @return long
	 */
	private function getDayStartOfTimestamp($timestamp) {
		return $timestamp - ($timestamp % (60 * 60 * 24));
	}

	/**
	 * Returns an SMTP address from an entry id.
	 *
	 * @param string $entryid
	 *
	 * @return string
	 */
	private function getSMTPAddressFromEntryID($entryid) {
		$addrbook = $this->getAddressbook();

		$mailuser = mapi_ab_openentry($addrbook, $entryid);
		if (!$mailuser) {
			return "";
		}

		$props = mapi_getprops($mailuser, [PR_ADDRTYPE, PR_SMTP_ADDRESS, PR_EMAIL_ADDRESS]);

		$addrtype = isset($props[PR_ADDRTYPE]) ? $props[PR_ADDRTYPE] : "";

		if (isset($props[PR_SMTP_ADDRESS])) {
			return $props[PR_SMTP_ADDRESS];
		}

		if ($addrtype == "SMTP" && isset($props[PR_EMAIL_ADDRESS])) {
			return $props[PR_EMAIL_ADDRESS];
		}

		return "";
	}

	/**
	 * Returns AB data from an entryid.
	 *
	 * @param string $entryid
	 *
	 * @return mixed
	 */
	private function getAbPropsFromEntryID($entryid) {
		$addrbook = $this->getAddressbook();
		$mailuser = mapi_ab_openentry($addrbook, $entryid);
		if ($mailuser) {
			return mapi_getprops($mailuser, [PR_DISPLAY_NAME, PR_ADDRTYPE, PR_SMTP_ADDRESS, PR_EMAIL_ADDRESS]);
		}

		SLog::Write(LOGLEVEL_ERROR, sprintf("MAPIProvider->getAbPropsFromEntryID(): Unable to get mailuser (0x%X)", mapi_last_hresult()));

		return false;
	}

	/**
	 * Builds a displayname from several separated values.
	 *
	 * @param SyncContact $contact
	 *
	 * @return string
	 */
	private function composeDisplayName(&$contact) {
		// Set display name and subject to a combined value of firstname and lastname
		$cname = (isset($contact->prefix)) ? $contact->prefix . " " : "";
		$cname .= $contact->firstname;
		$cname .= (isset($contact->middlename)) ? " " . $contact->middlename : "";
		$cname .= " " . $contact->lastname;
		$cname .= (isset($contact->suffix)) ? " " . $contact->suffix : "";

		return trim($cname);
	}

	/**
	 * Sets all dependent properties for an email address.
	 *
	 * @param string $emailAddress
	 * @param string $displayName
	 * @param int    $cnt
	 * @param array  &$props
	 * @param array  &$properties
	 * @param array  &$nremails
	 * @param int    &$abprovidertype
	 */
	private function setEmailAddress($emailAddress, $displayName, $cnt, &$props, &$properties, &$nremails, &$abprovidertype) {
		if (isset($emailAddress)) {
			$name = (isset($displayName)) ? $displayName : $emailAddress;

			$props[$properties["emailaddress{$cnt}"]] = $emailAddress;
			$props[$properties["emailaddressdemail{$cnt}"]] = $emailAddress;
			$props[$properties["emailaddressdname{$cnt}"]] = $name;
			$props[$properties["emailaddresstype{$cnt}"]] = "SMTP";
			$props[$properties["emailaddressentryid{$cnt}"]] = mapi_createoneoff($name, "SMTP", $emailAddress);
			$nremails[] = $cnt - 1;
			$abprovidertype |= 2 ^ ($cnt - 1);
		}
	}

	/**
	 * Sets the properties for an address string.
	 *
	 * @param string $type        which address is being set
	 * @param string $city
	 * @param string $country
	 * @param string $postalcode
	 * @param string $state
	 * @param string $street
	 * @param array  &$props
	 * @param array  &$properties
	 */
	private function setAddress($type, &$city, &$country, &$postalcode, &$state, &$street, &$props, &$properties) {
		if (isset($city)) {
			$props[$properties[$type . "city"]] = $city;
		}

		if (isset($country)) {
			$props[$properties[$type . "country"]] = $country;
		}

		if (isset($postalcode)) {
			$props[$properties[$type . "postalcode"]] = $postalcode;
		}

		if (isset($state)) {
			$props[$properties[$type . "state"]] = $state;
		}

		if (isset($street)) {
			$props[$properties[$type . "street"]] = $street;
		}

		// set composed address
		$address = Utils::BuildAddressString($street, $postalcode, $city, $state, $country);
		if ($address) {
			$props[$properties[$type . "address"]] = $address;
		}
	}

	/**
	 * Sets the properties for a mailing address.
	 *
	 * @param string $city
	 * @param string $country
	 * @param string $postalcode
	 * @param string $state
	 * @param string $street
	 * @param string $address
	 * @param array  &$props
	 * @param array  &$properties
	 */
	private function setMailingAddress($city, $country, $postalcode, $state, $street, $address, &$props, &$properties) {
		if (isset($city)) {
			$props[$properties["city"]] = $city;
		}
		if (isset($country)) {
			$props[$properties["country"]] = $country;
		}
		if (isset($postalcode)) {
			$props[$properties["postalcode"]] = $postalcode;
		}
		if (isset($state)) {
			$props[$properties["state"]] = $state;
		}
		if (isset($street)) {
			$props[$properties["street"]] = $street;
		}
		if (isset($address)) {
			$props[$properties["postaladdress"]] = $address;
		}
	}

	/**
	 * Sets data in a recurrence array.
	 *
	 * @param SyncObject $message
	 * @param array      &$recur
	 */
	private function setRecurrence($message, &$recur) {
		if (isset($message->complete)) {
			$recur["complete"] = $message->complete;
		}

		if (!isset($message->recurrence->interval)) {
			$message->recurrence->interval = 1;
		}

		// set the default value of numoccur
		$recur["numoccur"] = 0;
		// a place holder for recurrencetype property
		$recur["recurrencetype"] = 0;

		switch ($message->recurrence->type) {
			case 0:
				$recur["type"] = 10;
				if (isset($message->recurrence->dayofweek)) {
					$recur["subtype"] = 1;
				}
				else {
					$recur["subtype"] = 0;
				}

				$recur["everyn"] = $message->recurrence->interval * (60 * 24);
				$recur["recurrencetype"] = 1;
				break;

			case 1:
				$recur["type"] = 11;
				$recur["subtype"] = 1;
				$recur["everyn"] = $message->recurrence->interval;
				$recur["recurrencetype"] = 2;
				break;

			case 2:
				$recur["type"] = 12;
				$recur["subtype"] = 2;
				$recur["everyn"] = $message->recurrence->interval;
				$recur["recurrencetype"] = 3;
				break;

			case 3:
				$recur["type"] = 12;
				$recur["subtype"] = 3;
				$recur["everyn"] = $message->recurrence->interval;
				$recur["recurrencetype"] = 3;
				break;

			case 4:
				$recur["type"] = 13;
				$recur["subtype"] = 1;
				$recur["everyn"] = $message->recurrence->interval * 12;
				$recur["recurrencetype"] = 4;
				break;

			case 5:
				$recur["type"] = 13;
				$recur["subtype"] = 2;
				$recur["everyn"] = $message->recurrence->interval * 12;
				$recur["recurrencetype"] = 4;
				break;

			case 6:
				$recur["type"] = 13;
				$recur["subtype"] = 3;
				$recur["everyn"] = $message->recurrence->interval * 12;
				$recur["recurrencetype"] = 4;
				break;
		}

		// "start" and "end" are in GMT when passing to class.recurrence
		$recur["end"] = $this->getDayStartOfTimestamp(0x7FFFFFFF); // Maximum GMT value for end by default

		if (isset($message->recurrence->until)) {
			$recur["term"] = 0x21;
			$recur["end"] = $message->recurrence->until;
		}
		elseif (isset($message->recurrence->occurrences)) {
			$recur["term"] = 0x22;
			$recur["numoccur"] = $message->recurrence->occurrences;
		}
		else {
			$recur["term"] = 0x23;
		}

		if (isset($message->recurrence->dayofweek)) {
			$recur["weekdays"] = $message->recurrence->dayofweek;
		}
		if (isset($message->recurrence->weekofmonth)) {
			$recur["nday"] = $message->recurrence->weekofmonth;
		}
		if (isset($message->recurrence->monthofyear)) {
			// MAPI stores months as the amount of minutes until the beginning of the month in a
			// non-leapyear. Why this is, is totally unclear.
			$monthminutes = [0, 44640, 84960, 129600, 172800, 217440, 260640, 305280, 348480, 393120, 437760, 480960];
			$recur["month"] = $monthminutes[$message->recurrence->monthofyear - 1];
		}
		if (isset($message->recurrence->dayofmonth)) {
			$recur["monthday"] = $message->recurrence->dayofmonth;
		}
	}

	/**
	 * Extracts the email address (mailbox@host) from an email address because
	 * some devices send email address as "Firstname Lastname" <email@me.com>.
	 *
	 *  @see http://developer.berlios.de/mantis/view.php?id=486
	 *
	 * @param string $email
	 *
	 * @return string or false on error
	 */
	private function extractEmailAddress($email) {
		if (!isset($this->zRFC822)) {
			$this->zRFC822 = new Mail_RFC822();
		}
		$parsedAddress = $this->zRFC822->parseAddressList($email);
		if (!isset($parsedAddress[0]->mailbox) || !isset($parsedAddress[0]->host)) {
			return false;
		}

		return $parsedAddress[0]->mailbox . '@' . $parsedAddress[0]->host;
	}

	/**
	 * Returns the message body for a required format.
	 *
	 * @param MAPIMessage $mapimessage
	 * @param int         $bpReturnType
	 * @param SyncObject  $message
	 *
	 * @return bool
	 */
	private function setMessageBodyForType($mapimessage, $bpReturnType, &$message) {
		$truncateHtmlSafe = false;
		// default value is PR_BODY
		$property = PR_BODY;

		switch ($bpReturnType) {
			case SYNC_BODYPREFERENCE_HTML:
				$property = PR_HTML;
				$truncateHtmlSafe = true;
				break;

			case SYNC_BODYPREFERENCE_MIME:
				$stat = $this->imtoinet($mapimessage, $message);
				if (isset($message->asbody)) {
					$message->asbody->type = $bpReturnType;
				}

				return $stat;
		}

		$stream = mapi_openproperty($mapimessage, $property, IID_IStream, 0, 0);
		if ($stream) {
			$stat = mapi_stream_stat($stream);
			$streamsize = $stat['cb'];
		}
		else {
			$streamsize = 0;
		}

		// set the properties according to supported AS version
		if (Request::GetProtocolVersion() >= 12.0) {
			$message->asbody = new SyncBaseBody();
			$message->asbody->type = $bpReturnType;
			if (isset($message->internetcpid) && $bpReturnType == SYNC_BODYPREFERENCE_HTML) {
				// if PR_HTML is UTF-8 we can stream it directly, else we have to convert to UTF-8 & wrap it
				if ($message->internetcpid == INTERNET_CPID_UTF8) {
					$message->asbody->data = MAPIStreamWrapper::Open($stream, $truncateHtmlSafe);
				}
				else {
					$body = $this->mapiReadStream($stream, $streamsize);
					$message->asbody->data = StringStreamWrapper::Open(Utils::ConvertCodepageStringToUtf8($message->internetcpid, $body), $truncateHtmlSafe);
					$message->internetcpid = INTERNET_CPID_UTF8;
				}
			}
			else {
				$message->asbody->data = MAPIStreamWrapper::Open($stream);
			}
			$message->asbody->estimatedDataSize = $streamsize;
		}
		else {
			$body = $this->mapiReadStream($stream, $streamsize);
			$message->body = str_replace("\n", "\r\n", str_replace("\r", "", $body));
			$message->bodysize = $streamsize;
			$message->bodytruncated = 0;
		}

		return true;
	}

	/**
	 * Reads from a mapi stream, if it's set. If not, returns an empty string.
	 *
	 * @param resource $stream
	 * @param int      $size
	 *
	 * @return string
	 */
	private function mapiReadStream($stream, $size) {
		if (!$stream || $size == 0) {
			return "";
		}

		return mapi_stream_read($stream, $size);
	}

	/**
	 * Build a filereference key by the clientid.
	 *
	 * @param MAPIMessage $mapimessage
	 * @param mixed       $clientid
	 * @param mixed       $entryid
	 * @param mixed       $parentSourcekey
	 * @param mixed       $exceptionBasedate
	 *
	 * @return string/bool
	 */
	private function getFileReferenceForClientId($mapimessage, $clientid, $entryid = 0, $parentSourcekey = 0, $exceptionBasedate = 0) {
		if (!$entryid || !$parentSourcekey) {
			$props = mapi_getprops($mapimessage, [PR_ENTRYID, PR_PARENT_SOURCE_KEY]);
			if (!$entryid && isset($props[PR_ENTRYID])) {
				$entryid = bin2hex($props[PR_ENTRYID]);
			}
			if (!$parentSourcekey && isset($props[PR_PARENT_SOURCE_KEY])) {
				$parentSourcekey = bin2hex($props[PR_PARENT_SOURCE_KEY]);
			}
		}

		$attachtable = mapi_message_getattachmenttable($mapimessage);
		$rows = mapi_table_queryallrows($attachtable, [PR_EC_WA_ATTACHMENT_ID, PR_ATTACH_NUM]);
		foreach ($rows as $row) {
			if ($row[PR_EC_WA_ATTACHMENT_ID] == $clientid) {
				return sprintf("%s:%s:%s:%s", $entryid, $row[PR_ATTACH_NUM], $parentSourcekey, $exceptionBasedate);
			}
		}

		return false;
	}

	/**
	 * A wrapper for mapi_inetmapi_imtoinet function.
	 *
	 * @param MAPIMessage $mapimessage
	 * @param SyncObject  $message
	 *
	 * @return bool
	 */
	private function imtoinet($mapimessage, &$message) {
		$addrbook = $this->getAddressbook();
		$stream = mapi_inetmapi_imtoinet($this->session, $addrbook, $mapimessage, ['use_tnef' => -1, 'ignore_missing_attachments' => 1]);
		// is_resource($stream) returns false in PHP8
		if ($stream !== null && mapi_last_hresult() === ecSuccess) {
			$mstreamstat = mapi_stream_stat($stream);
			$streamsize = $mstreamstat["cb"];
			if (isset($streamsize)) {
				if (Request::GetProtocolVersion() >= 12.0) {
					if (!isset($message->asbody)) {
						$message->asbody = new SyncBaseBody();
					}
					$message->asbody->data = MAPIStreamWrapper::Open($stream);
					$message->asbody->estimatedDataSize = $streamsize;
					$message->asbody->truncated = 0;
				}
				else {
					$message->mimedata = MAPIStreamWrapper::Open($stream);
					$message->mimesize = $streamsize;
					$message->mimetruncated = 0;
				}
				unset($message->body, $message->bodytruncated);

				return true;
			}
		}
		SLog::Write(LOGLEVEL_ERROR, sprintf("MAPIProvider->imtoinet(): got no stream or content from mapi_inetmapi_imtoinet(): 0x%08X", mapi_last_hresult()));

		return false;
	}

	/**
	 * Sets the message body.
	 *
	 * @param MAPIMessage       $mapimessage
	 * @param ContentParameters $contentparameters
	 * @param SyncObject        $message
	 */
	private function setMessageBody($mapimessage, $contentparameters, &$message) {
		// get the available body preference types
		$bpTypes = $contentparameters->GetBodyPreference();
		if ($bpTypes !== false) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("BodyPreference types: %s", implode(', ', $bpTypes)));
			// do not send mime data if the client requests it
			if (($contentparameters->GetMimeSupport() == SYNC_MIMESUPPORT_NEVER) && ($key = array_search(SYNC_BODYPREFERENCE_MIME, $bpTypes) !== false)) {
				unset($bpTypes[$key]);
				SLog::Write(LOGLEVEL_DEBUG, sprintf("Remove mime body preference type because the device required no mime support. BodyPreference types: %s", implode(', ', $bpTypes)));
			}
			// get the best fitting preference type
			$bpReturnType = Utils::GetBodyPreferenceBestMatch($bpTypes);
			SLog::Write(LOGLEVEL_DEBUG, sprintf("GetBodyPreferenceBestMatch: %d", $bpReturnType));
			$bpo = $contentparameters->BodyPreference($bpReturnType);
			SLog::Write(LOGLEVEL_DEBUG, sprintf("bpo: truncation size:'%d', allornone:'%d', preview:'%d'", $bpo->GetTruncationSize(), $bpo->GetAllOrNone(), $bpo->GetPreview()));

			// Android Blackberry expects a full mime message for signed emails
			// @TODO change this when refactoring
			$props = mapi_getprops($mapimessage, [PR_MESSAGE_CLASS]);
			if (isset($props[PR_MESSAGE_CLASS]) &&
					stripos($props[PR_MESSAGE_CLASS], 'IPM.Note.SMIME.MultipartSigned') !== false &&
					($key = array_search(SYNC_BODYPREFERENCE_MIME, $bpTypes) !== false)) {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIProvider->setMessageBody(): enforcing SYNC_BODYPREFERENCE_MIME type for a signed message"));
				$bpReturnType = SYNC_BODYPREFERENCE_MIME;
			}

			$this->setMessageBodyForType($mapimessage, $bpReturnType, $message);
			// only set the truncation size data if device set it in request
			if ($bpo->GetTruncationSize() != false &&
					$bpReturnType != SYNC_BODYPREFERENCE_MIME &&
					$message->asbody->estimatedDataSize > $bpo->GetTruncationSize()
			) {
				// Truncated plaintext requests are used on iOS for the preview in the email list. All images and links should be removed.
				if ($bpReturnType == SYNC_BODYPREFERENCE_PLAIN) {
					SLog::Write(LOGLEVEL_DEBUG, "MAPIProvider->setMessageBody(): truncated plain-text body requested, stripping all links and images");
					// Get more data because of the filtering it's most probably going down in size. It's going to be truncated to the correct size below.
					$plainbody = stream_get_contents($message->asbody->data, $bpo->GetTruncationSize() * 5);
					$message->asbody->data = StringStreamWrapper::Open(preg_replace('/<http(s){0,1}:\/\/.*?>/i', '', $plainbody));
				}

				// truncate data stream
				ftruncate($message->asbody->data, $bpo->GetTruncationSize());
				$message->asbody->truncated = 1;
			}
			// set the preview or windows phones won't show the preview of an email
			if (Request::GetProtocolVersion() >= 14.0 && $bpo->GetPreview()) {
				$message->asbody->preview = Utils::Utf8_truncate(MAPIUtils::readPropStream($mapimessage, PR_BODY), $bpo->GetPreview());
			}
		}
		else {
			// Override 'body' for truncation
			$truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());
			$this->setMessageBodyForType($mapimessage, SYNC_BODYPREFERENCE_PLAIN, $message);

			if ($message->bodysize > $truncsize) {
				$message->body = Utils::Utf8_truncate($message->body, $truncsize);
				$message->bodytruncated = 1;
			}

			if (!isset($message->body) || strlen($message->body) == 0) {
				$message->body = " ";
			}

			if ($contentparameters->GetMimeSupport() == SYNC_MIMESUPPORT_ALWAYS) {
				// set the html body for iphone in AS 2.5 version
				$this->imtoinet($mapimessage, $message);
			}
		}
	}

	/**
	 * Sets properties for an email message.
	 *
	 * @param mixed    $mapimessage
	 * @param SyncMail $message
	 */
	private function setFlag($mapimessage, &$message) {
		// do nothing if protocol version is lower than 12.0 as flags haven't been defined before
		if (Request::GetProtocolVersion() < 12.0) {
			return;
		}

		$message->flag = new SyncMailFlags();

		$this->getPropsFromMAPI($message->flag, $mapimessage, MAPIMapping::GetMailFlagsMapping());
	}

	/**
	 * Sets information from SyncBaseBody type for a MAPI message.
	 *
	 * @param SyncBaseBody $asbody
	 * @param array        $props
	 * @param array        $appointmentprops
	 */
	private function setASbody($asbody, &$props, $appointmentprops) {
		// TODO: fix checking for the length
		if (isset($asbody->type, $asbody->data)   /* && strlen($asbody->data) > 0 */) {
			switch ($asbody->type) {
				case SYNC_BODYPREFERENCE_PLAIN:
				default:
					// set plain body if the type is not in valid range
					$props[$appointmentprops["body"]] = stream_get_contents($asbody->data);
					break;

				case SYNC_BODYPREFERENCE_HTML:
					$props[$appointmentprops["html"]] = stream_get_contents($asbody->data);
					break;

				case SYNC_BODYPREFERENCE_MIME:
					break;
			}
		}
		else {
			SLog::Write(LOGLEVEL_DEBUG, "MAPIProvider->setASbody either type or data are not set. Setting to empty body");
			$props[$appointmentprops["body"]] = "";
		}
	}

	/**
	 * Sets attachments from an email message to a SyncObject.
	 *
	 * @param mixed      $mapimessage
	 * @param SyncObject $message
	 * @param string     $entryid
	 * @param string     $parentSourcekey
	 * @param mixed      $exceptionBasedate
	 */
	private function setAttachment($mapimessage, &$message, $entryid, $parentSourcekey, $exceptionBasedate = 0) {
		// Add attachments
		$attachtable = mapi_message_getattachmenttable($mapimessage);
		$rows = mapi_table_queryallrows($attachtable, [PR_ATTACH_NUM]);

		foreach ($rows as $row) {
			if (isset($row[PR_ATTACH_NUM])) {
				if (Request::GetProtocolVersion() >= 12.0) {
					$attach = new SyncBaseAttachment();
				}
				else {
					$attach = new SyncAttachment();
				}

				$mapiattach = mapi_message_openattach($mapimessage, $row[PR_ATTACH_NUM]);
				$attachprops = mapi_getprops($mapiattach, [PR_ATTACH_LONG_FILENAME, PR_ATTACH_FILENAME, PR_ATTACHMENT_HIDDEN, PR_ATTACH_CONTENT_ID, PR_ATTACH_CONTENT_ID_A, PR_ATTACH_MIME_TAG, PR_ATTACH_METHOD, PR_DISPLAY_NAME, PR_ATTACH_SIZE, PR_ATTACH_FLAGS]);
				if (isset($attachprops[PR_ATTACH_MIME_TAG]) && strpos(strtolower($attachprops[PR_ATTACH_MIME_TAG]), 'signed') !== false) {
					continue;
				}

				// the displayname is handled equally for all AS versions
				$attach->displayname = (isset($attachprops[PR_ATTACH_LONG_FILENAME])) ? $attachprops[PR_ATTACH_LONG_FILENAME] : ((isset($attachprops[PR_ATTACH_FILENAME])) ? $attachprops[PR_ATTACH_FILENAME] : ((isset($attachprops[PR_DISPLAY_NAME])) ? $attachprops[PR_DISPLAY_NAME] : "attachment.bin"));
				// fix attachment name in case of inline images
				if (($attach->displayname == "inline.txt" && isset($attachprops[PR_ATTACH_MIME_TAG])) ||
						(substr_compare($attach->displayname, "attachment", 0, 10, true) === 0 && substr_compare($attach->displayname, ".dat", -4, 4, true) === 0)) {
					$mimetype = $attachprops[PR_ATTACH_MIME_TAG] ?? 'application/octet-stream';
					$mime = explode("/", $mimetype);

					if (count($mime) == 2 && $mime[0] == "image") {
						$attach->displayname = "inline." . $mime[1];
					}
				}

				// set AS version specific parameters
				if (Request::GetProtocolVersion() >= 12.0) {
					$attach->filereference = sprintf("%s:%s:%s:%s", $entryid, $row[PR_ATTACH_NUM], $parentSourcekey, $exceptionBasedate);
					$attach->method = (isset($attachprops[PR_ATTACH_METHOD])) ? $attachprops[PR_ATTACH_METHOD] : ATTACH_BY_VALUE;

					// if displayname does not have the eml extension for embedde messages, android and WP devices won't open it
					if ($attach->method == ATTACH_EMBEDDED_MSG) {
						if (strtolower(substr($attach->displayname, -4)) != '.eml') {
							$attach->displayname .= '.eml';
						}
					}
					// android devices require attachment size in order to display an attachment properly
					if (!isset($attachprops[PR_ATTACH_SIZE])) {
						$stream = mapi_openproperty($mapiattach, PR_ATTACH_DATA_BIN, IID_IStream, 0, 0);
						// It's not possible to open some (embedded only?) messages, so we need to open the attachment object itself to get the data
						if (mapi_last_hresult()) {
							$embMessage = mapi_attach_openobj($mapiattach);
							$addrbook = $this->getAddressbook();
							$stream = mapi_inetmapi_imtoinet($this->session, $addrbook, $embMessage, ['use_tnef' => -1]);
						}
						$stat = mapi_stream_stat($stream);
						$attach->estimatedDataSize = $stat['cb'];
					}
					else {
						$attach->estimatedDataSize = $attachprops[PR_ATTACH_SIZE];
					}

					if (isset($attachprops[PR_ATTACH_CONTENT_ID]) && $attachprops[PR_ATTACH_CONTENT_ID]) {
						$attach->contentid = $attachprops[PR_ATTACH_CONTENT_ID];
					}

					if (!isset($attach->contentid) && isset($attachprops[PR_ATTACH_CONTENT_ID_A]) && $attachprops[PR_ATTACH_CONTENT_ID_A]) {
						$attach->contentid = $attachprops[PR_ATTACH_CONTENT_ID_A];
					}

					if (isset($attachprops[PR_ATTACHMENT_HIDDEN]) && $attachprops[PR_ATTACHMENT_HIDDEN]) {
						$attach->isinline = 1;
					}

					if (isset($attach->contentid, $attachprops[PR_ATTACH_FLAGS]) && $attachprops[PR_ATTACH_FLAGS] & 4) {
						$attach->isinline = 1;
					}

					if (!isset($message->asattachments)) {
						$message->asattachments = [];
					}

					array_push($message->asattachments, $attach);
				}
				else {
					$attach->attsize = $attachprops[PR_ATTACH_SIZE];
					$attach->attname = sprintf("%s:%s:%s", $entryid, $row[PR_ATTACH_NUM], $parentSourcekey);
					if (!isset($message->attachments)) {
						$message->attachments = [];
					}

					array_push($message->attachments, $attach);
				}
			}
		}
	}

	/**
	 * Update attachments of a mapimessage based on asattachments received.
	 *
	 * @param MAPIMessage $mapimessage
	 * @param array       $asattachments
	 * @param SyncObject  $response
	 */
	public function editAttachments($mapimessage, $asattachments, &$response) {
		foreach ($asattachments as $att) {
			// new attachment to be saved
			if ($att instanceof SyncBaseAttachmentAdd) {
				if (!isset($att->content)) {
					SLog::Write(LOGLEVEL_WARN, sprintf("MAPIProvider->editAttachments(): Ignoring attachment %s to be added as it has no content: %s", $att->clientid, $att->displayname));

					continue;
				}

				SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIProvider->editAttachments(): Saving attachment %s with name: %s", $att->clientid, $att->displayname));
				// only create if the attachment does not already exist
				if ($this->getFileReferenceForClientId($mapimessage, $att->clientid, 0, 0) === false) {
					// TODO: check: contentlocation
					$props = [
						PR_ATTACH_LONG_FILENAME => $att->displayname,
						PR_DISPLAY_NAME => $att->displayname,
						PR_ATTACH_METHOD => $att->method, // is this correct ??
						PR_ATTACH_DATA_BIN => "",
						PR_ATTACHMENT_HIDDEN => false,
						PR_ATTACH_EXTENSION => pathinfo($att->displayname, PATHINFO_EXTENSION),
						PR_EC_WA_ATTACHMENT_ID => $att->clientid,
					];
					if (!empty($att->contenttype)) {
						$props[PR_ATTACH_MIME_TAG] = $att->contenttype;
					}
					if (!empty($att->contentid)) {
						$props[PR_ATTACH_CONTENT_ID] = $att->contentid;
					}

					$attachment = mapi_message_createattach($mapimessage);
					mapi_setprops($attachment, $props);

					// Stream the file to the PR_ATTACH_DATA_BIN property
					$stream = mapi_openproperty($attachment, PR_ATTACH_DATA_BIN, IID_IStream, 0, MAPI_CREATE | MAPI_MODIFY);
					mapi_stream_write($stream, stream_get_contents($att->content));

					// Commit the stream and save changes
					mapi_stream_commit($stream);
					mapi_savechanges($attachment);
				}
				if (!isset($response->asattachments)) {
					$response->asattachments = [];
				}
				// respond linking the clientid with the newly created filereference
				$attResp = new SyncBaseAttachment();
				$attResp->clientid = $att->clientid;
				$attResp->filereference = $this->getFileReferenceForClientId($mapimessage, $att->clientid, 0, 0);
				$response->asattachments[] = $attResp;
				$response->hasResponse = true;
			}
			// attachment to be removed
			elseif ($att instanceof SyncBaseAttachmentDelete) {
				list($id, $attachnum, $parentEntryid, $exceptionBasedate) = explode(":", $att->filereference);
				SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIProvider->editAttachments(): Deleting attachment with num: %s", $attachnum));
				mapi_message_deleteattach($mapimessage, (int) $attachnum);
			}
		}
	}

	/**
	 * Sets information from SyncLocation type for a MAPI message.
	 *
	 * @param SyncBaseBody $aslocation
	 * @param array        $props
	 * @param array        $appointmentprops
	 */
	private function setASlocation($aslocation, &$props, $appointmentprops) {
		$fullAddress = "";
		if ($aslocation->street || $aslocation->city || $aslocation->state || $aslocation->country || $aslocation->postalcode) {
			$fullAddress = $aslocation->street . ", " . $aslocation->city . "-" . $aslocation->state . "," . $aslocation->country . "," . $aslocation->postalcode;
		}

		// Determine which data to use as DisplayName. This is also set to the traditional location property for backwards compatibility (this is currently displayed in OL).
		$useStreet = false;
		if ($aslocation->displayname) {
			$props[$appointmentprops["location"]] = $aslocation->displayname;
		}
		elseif ($aslocation->street) {
			$useStreet = true;
			$props[$appointmentprops["location"]] = $fullAddress;
		}
		elseif ($aslocation->city) {
			$props[$appointmentprops["location"]] = $aslocation->city;
		}
		$loc = [];
		$loc["DisplayName"] = ($useStreet) ? $aslocation->street : $props[$appointmentprops["location"]];
		$loc["LocationAnnotation"] = ($aslocation->annotation) ? $aslocation->annotation : "";
		$loc["LocationSource"] = "None";
		$loc["Unresolved"] = ($aslocation->locationuri) ? false : true;
		$loc["LocationUri"] = $aslocation->locationuri ?? "";
		$loc["Latitude"] = ($aslocation->latitude) ? floatval($aslocation->latitude) : null;
		$loc["Longitude"] = ($aslocation->longitude) ? floatval($aslocation->longitude) : null;
		$loc["Altitude"] = ($aslocation->altitude) ? floatval($aslocation->altitude) : null;
		$loc["Accuracy"] = ($aslocation->accuracy) ? floatval($aslocation->accuracy) : null;
		$loc["AltitudeAccuracy"] = ($aslocation->altitudeaccuracy) ? floatval($aslocation->altitudeaccuracy) : null;
		$loc["LocationStreet"] = $aslocation->street ?? "";
		$loc["LocationCity"] = $aslocation->city ?? "";
		$loc["LocationState"] = $aslocation->state ?? "";
		$loc["LocationCountry"] = $aslocation->country ?? "";
		$loc["LocationPostalCode"] = $aslocation->postalcode ?? "";
		$loc["LocationFullAddress"] = $fullAddress;

		$props[$appointmentprops["locations"]] = json_encode([$loc], JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Gets information from a MAPI message and applies it to a SyncLocation object.
	 *
	 * @param MAPIMessage $mapimessage
	 * @param SyncObject  $aslocation
	 * @param array       $appointmentprops
	 */
	private function getASlocation($mapimessage, &$aslocation, $appointmentprops) {
		$props = mapi_getprops($mapimessage, [$appointmentprops["locations"], $appointmentprops["location"]]);
		// set the old location as displayname - this is also the "correct" approach if there is more than one location in the "locations" property json
		if (isset($props[$appointmentprops["location"]])) {
			$aslocation->displayname = $props[$appointmentprops["location"]];
		}

		if (isset($props[$appointmentprops["locations"]])) {
			$loc = json_decode($props[$appointmentprops["locations"]], true);
			if (is_array($loc) && count($loc) == 1) {
				$l = $loc[0];
				if (!empty($l['DisplayName'])) {
					$aslocation->displayname = $l['DisplayName'];
				}
				if (!empty($l['LocationAnnotation'])) {
					$aslocation->annotation = $l['LocationAnnotation'];
				}
				if (!empty($l['LocationStreet'])) {
					$aslocation->street = $l['LocationStreet'];
				}
				if (!empty($l['LocationCity'])) {
					$aslocation->city = $l['LocationCity'];
				}
				if (!empty($l['LocationState'])) {
					$aslocation->state = $l['LocationState'];
				}
				if (!empty($l['LocationCountry'])) {
					$aslocation->country = $l['LocationCountry'];
				}
				if (!empty($l['LocationPostalCode'])) {
					$aslocation->postalcode = $l['LocationPostalCode'];
				}
				if (isset($l['Latitude']) && is_numeric($l['Latitude'])) {
					$aslocation->latitude = floatval($l['Latitude']);
				}
				if (isset($l['Longitude']) && is_numeric($l['Longitude'])) {
					$aslocation->longitude = floatval($l['Longitude']);
				}
				if (isset($l['Accuracy']) && is_numeric($l['Accuracy'])) {
					$aslocation->accuracy = floatval($l['Accuracy']);
				}
				if (isset($l['Altitude']) && is_numeric($l['Altitude'])) {
					$aslocation->altitude = floatval($l['Altitude']);
				}
				if (isset($l['AltitudeAccuracy']) && is_numeric($l['AltitudeAccuracy'])) {
					$aslocation->altitudeaccuracy = floatval($l['AltitudeAccuracy']);
				}
				if (!empty($l['LocationUri'])) {
					$aslocation->locationuri = $l['LocationUri'];
				}
			}
		}
	}

	/**
	 * Get MAPI addressbook object.
	 *
	 * @return MAPIAddressbook object to be used with mapi_ab_* or false on failure
	 */
	private function getAddressbook() {
		if (isset($this->addressbook) && $this->addressbook) {
			return $this->addressbook;
		}
		$this->addressbook = mapi_openaddressbook($this->session);
		$result = mapi_last_hresult();
		if ($result && $this->addressbook === false) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("MAPIProvider->getAddressbook error opening addressbook 0x%X", $result));

			return false;
		}

		return $this->addressbook;
	}

	/**
	 * Gets the required store properties.
	 *
	 * @return array
	 */
	public function GetStoreProps() {
		if (!isset($this->storeProps) || empty($this->storeProps)) {
			SLog::Write(LOGLEVEL_DEBUG, "MAPIProvider->GetStoreProps(): Getting store properties.");
			$this->storeProps = mapi_getprops($this->store, [PR_IPM_SUBTREE_ENTRYID, PR_IPM_OUTBOX_ENTRYID, PR_IPM_WASTEBASKET_ENTRYID, PR_IPM_SENTMAIL_ENTRYID, PR_ENTRYID, PR_IPM_PUBLIC_FOLDERS_ENTRYID, PR_IPM_FAVORITES_ENTRYID, PR_MAILBOX_OWNER_ENTRYID]);
			// make sure all properties are set
			if (!isset($this->storeProps[PR_IPM_WASTEBASKET_ENTRYID])) {
				$this->storeProps[PR_IPM_WASTEBASKET_ENTRYID] = false;
			}
			if (!isset($this->storeProps[PR_IPM_SENTMAIL_ENTRYID])) {
				$this->storeProps[PR_IPM_SENTMAIL_ENTRYID] = false;
			}
			if (!isset($this->storeProps[PR_IPM_OUTBOX_ENTRYID])) {
				$this->storeProps[PR_IPM_OUTBOX_ENTRYID] = false;
			}
			if (!isset($this->storeProps[PR_IPM_PUBLIC_FOLDERS_ENTRYID])) {
				$this->storeProps[PR_IPM_PUBLIC_FOLDERS_ENTRYID] = false;
			}
		}

		return $this->storeProps;
	}

	/**
	 * Gets the required inbox properties.
	 *
	 * @return array
	 */
	public function GetInboxProps() {
		if (!isset($this->inboxProps) || empty($this->inboxProps)) {
			SLog::Write(LOGLEVEL_DEBUG, "MAPIProvider->GetInboxProps(): Getting inbox properties.");
			$this->inboxProps = [];
			$inbox = mapi_msgstore_getreceivefolder($this->store);
			if ($inbox) {
				$this->inboxProps = mapi_getprops($inbox, [PR_ENTRYID, PR_IPM_DRAFTS_ENTRYID, PR_IPM_TASK_ENTRYID, PR_IPM_APPOINTMENT_ENTRYID, PR_IPM_CONTACT_ENTRYID, PR_IPM_NOTE_ENTRYID, PR_IPM_JOURNAL_ENTRYID]);
				// make sure all properties are set
				if (!isset($this->inboxProps[PR_ENTRYID])) {
					$this->inboxProps[PR_ENTRYID] = false;
				}
				if (!isset($this->inboxProps[PR_IPM_DRAFTS_ENTRYID])) {
					$this->inboxProps[PR_IPM_DRAFTS_ENTRYID] = false;
				}
				if (!isset($this->inboxProps[PR_IPM_TASK_ENTRYID])) {
					$this->inboxProps[PR_IPM_TASK_ENTRYID] = false;
				}
				if (!isset($this->inboxProps[PR_IPM_APPOINTMENT_ENTRYID])) {
					$this->inboxProps[PR_IPM_APPOINTMENT_ENTRYID] = false;
				}
				if (!isset($this->inboxProps[PR_IPM_CONTACT_ENTRYID])) {
					$this->inboxProps[PR_IPM_CONTACT_ENTRYID] = false;
				}
				if (!isset($this->inboxProps[PR_IPM_NOTE_ENTRYID])) {
					$this->inboxProps[PR_IPM_NOTE_ENTRYID] = false;
				}
				if (!isset($this->inboxProps[PR_IPM_JOURNAL_ENTRYID])) {
					$this->inboxProps[PR_IPM_JOURNAL_ENTRYID] = false;
				}
			}
		}

		return $this->inboxProps;
	}

	/**
	 * Gets the required store root properties.
	 *
	 * @return array
	 */
	private function getRootProps() {
		if (!isset($this->rootProps)) {
			$root = mapi_msgstore_openentry($this->store, null);
			$this->rootProps = mapi_getprops($root, [PR_ADDITIONAL_REN_ENTRYIDS_EX]);
		}

		return $this->rootProps;
	}

	/**
	 * Returns an array with entryids of some special folders.
	 *
	 * @return array
	 */
	private function getSpecialFoldersData() {
		// The persist data of an entry in PR_ADDITIONAL_REN_ENTRYIDS_EX consists of:
		//      PersistId - e.g. RSF_PID_SUGGESTED_CONTACTS (2 bytes)
		//      DataElementsSize - size of DataElements field (2 bytes)
		//      DataElements - array of PersistElement structures (variable size)
		//          PersistElement Structure consists of
		//              ElementID - e.g. RSF_ELID_ENTRYID (2 bytes)
		//              ElementDataSize - size of ElementData (2 bytes)
		//              ElementData - The data for the special folder identified by the PersistID (variable size)
		if (empty($this->specialFoldersData)) {
			$this->specialFoldersData = [];
			$rootProps = $this->getRootProps();
			if (isset($rootProps[PR_ADDITIONAL_REN_ENTRYIDS_EX])) {
				$persistData = $rootProps[PR_ADDITIONAL_REN_ENTRYIDS_EX];
				while (strlen($persistData) > 0) {
					// PERSIST_SENTINEL marks the end of the persist data
					if (strlen($persistData) == 4 && intval($persistData) == PERSIST_SENTINEL) {
						break;
					}
					$unpackedData = unpack("vdataSize/velementID/velDataSize", substr($persistData, 2, 6));
					if (isset($unpackedData['dataSize'], $unpackedData['elementID']) && $unpackedData['elementID'] == RSF_ELID_ENTRYID && isset($unpackedData['elDataSize'])) {
						$this->specialFoldersData[] = substr($persistData, 8, $unpackedData['elDataSize']);
						// Add PersistId and DataElementsSize lengths to the data size as they're not part of it
						$persistData = substr($persistData, $unpackedData['dataSize'] + 4);
					}
					else {
						SLog::Write(LOGLEVEL_INFO, "MAPIProvider->getSpecialFoldersData(): persistent data is not valid");
						break;
					}
				}
			}
		}

		return $this->specialFoldersData;
	}

	/**
	 * Extracts email address from PR_SEARCH_KEY property if possible.
	 *
	 * @param string $searchKey
	 *
	 * @return string
	 */
	private function getEmailAddressFromSearchKey($searchKey) {
		if (strpos($searchKey, ':') !== false && strpos($searchKey, '@') !== false) {
			SLog::Write(LOGLEVEL_INFO, "MAPIProvider->getEmailAddressFromSearchKey(): fall back to PR_SEARCH_KEY or PR_SENT_REPRESENTING_SEARCH_KEY to resolve user and get email address");

			return trim(strtolower(explode(':', $searchKey)[1]));
		}

		return "";
	}

	/**
	 * Returns categories for a message.
	 *
	 * @param binary $parentsourcekey
	 * @param binary $sourcekey
	 *
	 * @return array or false on failure
	 */
	public function GetMessageCategories($parentsourcekey, $sourcekey) {
		$entryid = mapi_msgstore_entryidfromsourcekey($this->store, $parentsourcekey, $sourcekey);
		if (!$entryid) {
			SLog::Write(LOGLEVEL_INFO, sprintf("MAPIProvider->GetMessageCategories(): Couldn't retrieve message, sourcekey: '%s', parentsourcekey: '%s'", bin2hex($sourcekey), bin2hex($parentsourcekey)));

			return false;
		}
		$mapimessage = mapi_msgstore_openentry($this->store, $entryid);
		$emailMapping = MAPIMapping::GetEmailMapping();
		$emailMapping = ["categories" => $emailMapping["categories"]];
		$messageCategories = $this->getProps($mapimessage, $emailMapping);
		if (isset($messageCategories[$emailMapping["categories"]]) && is_array($messageCategories[$emailMapping["categories"]])) {
			return $messageCategories[$emailMapping["categories"]];
		}

		return false;
	}

	/**
	 * Adds recipients to the recips array.
	 *
	 * @param string $recip
	 * @param int    $type
	 * @param array  $recips
	 */
	private function addRecips($recip, $type, &$recips) {
		if (!empty($recip) && is_array($recip)) {
			$emails = $recip;
			// Recipients should be comma separated, but android devices separate
			// them with semicolon, hence the additional processing
			if (count($recip) === 1 && strpos($recip[0], ';') !== false) {
				$emails = explode(';', $recip[0]);
			}

			foreach ($emails as $email) {
				$extEmail = $this->extractEmailAddress($email);
				if ($extEmail !== false) {
					$r = $this->createMapiRecipient($extEmail, $type);
					$recips[] = $r;
				}
			}
		}
	}

	/**
	 * Creates a MAPI recipient to use with mapi_message_modifyrecipients().
	 *
	 * @param string $email
	 * @param int    $type
	 *
	 * @return array
	 */
	private function createMapiRecipient($email, $type) {
		// Open address book for user resolve
		$addrbook = $this->getAddressbook();
		$recip = [];
		$recip[PR_EMAIL_ADDRESS] = $email;
		$recip[PR_SMTP_ADDRESS] = $email;

		// lookup information in GAB if possible so we have up-to-date name for given address
		$userinfo = [[PR_DISPLAY_NAME => $recip[PR_EMAIL_ADDRESS]]];
		$userinfo = mapi_ab_resolvename($addrbook, $userinfo, EMS_AB_ADDRESS_LOOKUP);
		if (mapi_last_hresult() == NOERROR) {
			$recip[PR_DISPLAY_NAME] = $userinfo[0][PR_DISPLAY_NAME];
			$recip[PR_EMAIL_ADDRESS] = $userinfo[0][PR_EMAIL_ADDRESS];
			$recip[PR_SEARCH_KEY] = $userinfo[0][PR_SEARCH_KEY];
			$recip[PR_ADDRTYPE] = $userinfo[0][PR_ADDRTYPE];
			$recip[PR_ENTRYID] = $userinfo[0][PR_ENTRYID];
			$recip[PR_RECIPIENT_TYPE] = $type;
		}
		else {
			$recip[PR_DISPLAY_NAME] = $email;
			$recip[PR_SEARCH_KEY] = "SMTP:" . $recip[PR_EMAIL_ADDRESS] . "\0";
			$recip[PR_ADDRTYPE] = "SMTP";
			$recip[PR_RECIPIENT_TYPE] = $type;
			$recip[PR_ENTRYID] = mapi_createoneoff($recip[PR_DISPLAY_NAME], $recip[PR_ADDRTYPE], $recip[PR_EMAIL_ADDRESS]);
		}

		return $recip;
	}
}
