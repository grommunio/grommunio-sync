grommunio-sync 2.7 (2026-10-10)
===============================

Fixes:

* Yearly recurrences with a wrong interval (now saved and sent in years)
* DST transitions of rules not on the last weekday of a month off by a week
* All-day AS 16 series and exceptions saved and sent at wrong times
* Occurrences of an instanceid not found in the item timezone or on its day
* Exceptions lost when changing an unchanged recurrence or sent without it
* Omitted elements of AS 16 calendar changes cleared stored values
* Attendees not informed of changed or deleted occurrences
* Attendees informed of insignificant AS 16 changes
* Pre-update attendees not matched by SMTP address
* Organizer sent with AttendeeType 0
* Appointment attachments missing in the meeting request
* Draft submission and sending of new drafts
* Emails accepted outside the drafts folder for AS 16
* Moved messages not hard deleted at the source
* Messages leaving the sync window not exported as SoftDelete
* Deleted messages exported as SoftDelete instead of Remove
* Removal of the wrong body preference for MIMESupport 0
* Search: unconsumed Query and Picture end tags, MaxSize and MaxPictures
  of GAL pictures ignored
* FolderSync with folder states that are not SyncParameters
* GetFolderStat taking very long due to search folders
* SmartReply/SmartForward errors answered with the wrong root tag
* Out of office state of time based replies
* Truncated special folder persist data
* Stores without PR_STORE_SUPPORT_MASK
* Passing null to stripos() for impersonation
* Failed merge CAS of the interprocess data not retried

Enhancements:

* Device access rules to allow or block devices by DeviceModel, DeviceType,
  DeviceOS, UserAgent or X-MS-WL header
* EAS 16 compliance: meeting responses for single occurrences, proposed
  times of counter proposals, DisallowNewTimeProposal, compose and draft
  status codes, conversation id and index, AccountOnlyRemoteWipe for 16.1
* Find: GAL search, draft state, range and folder of found items
* Moving a conversation in ItemOperations
* ResolveRecipients: GAL photos, contact search, unresolved status
* External out of office reply
* Status 142 for unprovisioned AS 14+ devices
* Calendar items forwarded to forwardees
* TimezoneUtil of mapi-header-php 2.4 (now required)
* Removal of dead code and outdated TODOs

grommunio-sync 2.6 (2026-09-24)
===============================

Fixes:

* AS 16.0 clients sending a draft with an empty <Send/> tag aborted the Sync
* Wrong attendees after saving a draft
* Sender properties set needlessly before sending

Enhancements:

* Send messages from the ICS importer when the client requests it
* Log auth challenges at INFO level
* Shared MAPI helpers from mapi-header-php 2.3 (now required): property
  streams, timezone definitions, calendar restriction
* Codepage table with iconv-resolvable names, 936 decoded as GB18030

grommunio-sync 2.5 (2026-07-27)
===============================

Fixes:

* Searching for device states in wrong folder
* Calling member funcions on spa when it is not an object
* Wrong total count and sort order
* iOS devices show old emails as new
* Wrong times for all-day exceptions between Android and Outlook
* All-day exception turns into a meeting request
* Wrong exceptionstarttime calculation for deleted occurrences
* Meeting update for an exception without attendees

Enhancements:

* User impersonation
* Use getRootProps in IsMAPIDefaultFolder
* Save impersonatinguser in device data

grommunio-sync 2.4 (2025-12-16)
===============================

Fixes:

* Casting to correct compare type

Enhancements:

* Connection tracking in redis


grommunio-sync 2.3 (2025-09-26)
===============================

Fixes:

* S/MIME signed emails sent from iOS had shown no recipient
* Cleanup search folders from store created by Find operation


grommunio-sync 2.2 (2025-04-15)
===============================

Fixes:

* Allow for successful synchronization when logged in via altname (turning it
  into email address internally)
* TimeZoneStruct wDayOfWeek and wDay were erroneously switched whan calculating
  DST start and end, which has been fixed.
* Adjust all day events to midnight only if a TZ definition was saved with the
  message. The server timezone should not be applied in this case, because if
  no TZ is there, starttime/endtime of an event should be midnight already.
* Apply server TZ to allday appointments with non GMT start times.


grommunio-sync 2.1 (2025-01-28)
===============================

Fixes:

* Fix crashes caused by off-by-one-errors with utf8_truncate calls
* Fix missing response of MR cancellations
* Fix wrong timezone mappings for Android devices

Enhancements:

* Allow custom configuration snippets for nginx config templates
* Better handling of all-day events with PidLidAppointmentTimeZoneDefinition*
* Enhanced robustness for organizer data on GAL without PR_SENT_REPRESENTING_*
* Flicker-free grommunio-sync-top CLI rerendering
* Improve appointment editing with older devices (Android <= 12; EAS 14.1)
* Improvements on PHP 8.2+ support
* More efficient GAL object retrieval
* Prevent organizers double listing to recipients
* Seach improvements (using searchfolder IDs)

Behavioral changes:

* Introduced $isMeeting mnemonic for meeting requests
* Remove RTF body handling (outdated, no clients <5 years uses RTF)
* Respect the USER_PRIVILEGE_SYNC flag of the user account on login
* Undesired folders are ignored for sync operations (Journal, RSS Feeds,...)
