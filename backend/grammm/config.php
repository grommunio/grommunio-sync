<?php
/***********************************************
* File      :   config.php
* Project   :   Grammm-Sync
* Descr     :   Grammm backend configuration file
*
* Created   :   27.11.2012
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
* Copyright 2020 Grammm GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

// ************************
//  BackendGrammm settings
// ************************

// Defines the server to which we want to connect.
//
// Depending on your setup, it might be advisable to change the lines below to one defined with your
// default socket location.
define('MAPI_SERVER', 'default:');

// Read-Only shared folders
//   When trying to write a change on a read-only folder this data is dropped and replaced on the device of the user.
//   Enabling the option below, sends an email to the user notifying that this happened (default enabled).
//   If this is disabled, the data will be dropped silently and will be lost.
//   The template of the email sent can be customized here. The placeholders can also be used in the subject.
define('READ_ONLY_NOTIFY_LOST_DATA', true);
// String to mark the data changed by the user (that he is trying to save)
define('READ_ONLY_NOTIFY_YOURDATA', 'Your data');
// Email template to be sent to the user
define('READ_ONLY_NOTIFY_SUBJECT', "Grammm-Sync: Writing operation not permitted - data reset");
define('READ_ONLY_NOTIFY_BODY', <<<END
Dear **USERFULLNAME**,

on **DATE** at **TIME** you've tried to save a data in the folder '**FOLDERNAME**' on your device '**MOBILETYPE**' ID: '**MOBILEDEVICEID**'.

This operation was not successful, as you lack write access to this folder.
Your data has been dropped and replaced with the original data on your device to ensure data integrity.

Below is a copy of the data you tried to save. If you want your changes to be stored permanently you should forward this email to a person with write access to this folder asking to perform these changes again.
**DIFFERENCES**

If you have questions about this email, please contact your e-mail administrator.

Sincerely,
Your Grammm-Sync system
END
         );
// Format of the **DATE** and **TIME** placeholders - more information on formats, see http://php.net/manual/en/function.strftime.php
define('READ_ONLY_NOTIFY_DATE_FORMAT', "%d.%m.%Y");
define('READ_ONLY_NOTIFY_TIME_FORMAT', "%H:%M:%S");

// Comma separated list of folder ids as string for which the notification emails of the changes in read-only folders shouldn't be sent.
// E.g. define('READ_ONLY_NONOTIFY', '1, 2, 3, 4');
// When configuring $additionalFolders it is possible to use DeviceManager::FLD_FLAGS_NOREADONLYNOTIFY in the flags bitmask
// in order to prevent the notifications as well.
define('READ_ONLY_NONOTIFY', '');