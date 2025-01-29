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
