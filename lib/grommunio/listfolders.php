#!/usr/bin/env php
<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2013,2015-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * This is a small command line tool to list folders of a user store or public
 * folder available for synchronization.
 */

define('MAPI_SERVER', 'default:');
define('SSLCERT_FILE', null);
define('SSLCERT_PASS', null);

$supported_classes = [
	"IPF.Note" => "SYNC_FOLDER_TYPE_USER_MAIL",
	"IPF.Task" => "SYNC_FOLDER_TYPE_USER_TASK",
	"IPF.Appointment" => "SYNC_FOLDER_TYPE_USER_APPOINTMENT",
	"IPF.Contact" => "SYNC_FOLDER_TYPE_USER_CONTACT",
	"IPF.StickyNote" => "SYNC_FOLDER_TYPE_USER_NOTE",
];

main();

function main() {
	listfolders_configure();
	listfolders_handle();
}

function listfolders_configure() {
	if (php_sapi_name() != "cli") {
		fwrite(STDERR, "This script can only be called from the CLI.\n");

		exit(1);
	}

	if (!function_exists("getopt")) {
		echo "PHP Function 'getopt()' not found. Please check your PHP version and settings.\n";

		exit(1);
	}

	// include PHP-MAPI classes
	define('UMAPI_PATH', '/usr/share/php-mapi');
	require_once UMAPI_PATH . '/mapi.util.php';
	require_once UMAPI_PATH . '/mapidefs.php';
	require_once UMAPI_PATH . '/mapitags.php';
	require_once UMAPI_PATH . '/mapiguid.php';
}

function listfolders_handle() {
	$shortoptions = "l:h:u:p:c:";
	$options = getopt($shortoptions);

	$mapi = MAPI_SERVER;
	$sslcert_file = SSLCERT_FILE;
	$sslcert_pass = SSLCERT_PASS;
	$user = "SYSTEM";
	$pass = "";

	if (isset($options['h'])) {
		$mapi = $options['h'];
	}

	// accept a remote user
	if (isset($options['u'], $options['p'])) {
		$user = $options['u'];
		$pass = $options['p'];
	}
	// accept a certificate and passwort for login
	elseif (isset($options['c'], $options['p'])) {
		$sslcert_file = $options['c'];
		$sslcert_pass = $options['p'];
	}

	$zarafaAdmin = listfolders_zarafa_admin_setup($mapi, $user, $pass, $sslcert_file, $sslcert_pass);
	if (isset($zarafaAdmin['adminStore'], $options['l'])) {
		listfolders_getlist($zarafaAdmin['adminStore'], $zarafaAdmin['session'], trim($options['l']));
	}
	else {
		echo "Usage:\n" .
				"listfolders.php [actions] [options]\n\n" .
				"Actions: [-l username]\n" .
					"\t-l username\tlist folders of user, for public folder use 'SYSTEM'\n\n" .
				"Global options: [-h path] [[-u remoteuser] [-p password]] [[-c certificate_path] [-p password]]\n" .
					"\t-h path\t\tconnect through <path>, e.g. file:///var/run/socket or https://10.0.0.1:237/grommunio\n" .
					"\t-u remoteuser\tlogin as authenticated administration user\n" .
					"\t-c certificate\tlogin with a ssl certificate located in this location, e.g. /etc/zarafa/ssl/client.pem\n" .
					"\t-p password\tpassword of the remoteuser or certificate\n\n";
	}
}

function listfolders_zarafa_admin_setup($mapi, $user, $pass, $sslcert_file, $sslcert_pass) {
	$session = @mapi_logon_zarafa($user, $pass, $mapi, $sslcert_file, $sslcert_pass, 0, 'script', 'script');

	if (!$session) {
		printf("User '%s' could not login. The script will exit. Errorcode: 0x%08x\n", $user, mapi_last_hresult());

		exit(1);
	}

	$adminStore = null;
	$stores = @mapi_getmsgstorestable($session);
	$storeslist = @mapi_table_queryallrows($stores, [PR_ENTRYID, PR_DEFAULT_STORE, PR_MDB_PROVIDER]);
	foreach ($storeslist as $store) {
		if (isset($store[PR_DEFAULT_STORE]) && $store[PR_DEFAULT_STORE] == true) {
			$adminStore = @mapi_openmsgstore($session, $store[PR_ENTRYID]);

			break;
		}
	}
	$zarafauserinfo['admin'] = 1;
	$admin = (isset($zarafauserinfo['admin']) && $zarafauserinfo['admin']) ? true : false;

	if (!$stores || !$storeslist || !$adminStore || !$admin) {
		echo "There was error trying to log in as admin or retrieving admin info. The script will exit.\n";

		exit(1);
	}

	return ["session" => $session, "adminStore" => $adminStore];
}

function listfolders_getlist($adminStore, $session, $user) {
	global $supported_classes;

	if (strtoupper((string) $user) == 'SYSTEM') {
		// Find the public store store
		$storestables = @mapi_getmsgstorestable($session);
		$result = @mapi_last_hresult();

		if ($result == NOERROR) {
			$rows = @mapi_table_queryallrows($storestables, [PR_ENTRYID, PR_MDB_PROVIDER]);

			foreach ($rows as $row) {
				if (isset($row[PR_MDB_PROVIDER]) && $row[PR_MDB_PROVIDER] == ZARAFA_STORE_PUBLIC_GUID) {
					if (!isset($row[PR_ENTRYID])) {
						echo "Public folder are not available.\nIf this is a multi-tenancy system, use -u and -p and login with an admin user of the company.\nThe script will exit.\n";

						exit(1);
					}
					$entryid = $row[PR_ENTRYID];

					break;
				}
			}
		}
	}
	else {
		$entryid = @mapi_msgstore_createentryid($adminStore, $user);
	}

	$userStore = @mapi_openmsgstore($session, $entryid);
	$hresult = mapi_last_hresult();

	// Cache the store for later use
	if ($hresult != NOERROR) {
		echo "Could not open store for '{$user}'. The script will exit.\n";

		exit(1);
	}

	if (strtoupper((string) $user) != 'SYSTEM') {
		$inbox = mapi_msgstore_getreceivefolder($userStore);
		if (mapi_last_hresult() != NOERROR) {
			printf("Could not open inbox for %s (0x%08X). The script will exit.\n", $user, mapi_last_hresult());

			exit(1);
		}
		$inboxProps = mapi_getprops($inbox, [PR_SOURCE_KEY]);
	}

	$storeProps = mapi_getprops($userStore, [PR_IPM_OUTBOX_ENTRYID, PR_IPM_SENTMAIL_ENTRYID, PR_IPM_WASTEBASKET_ENTRYID]);
	$root = @mapi_msgstore_openentry($userStore, null);
	$h_table = @mapi_folder_gethierarchytable($root, CONVENIENT_DEPTH);
	$subfolders = @mapi_table_queryallrows($h_table, [PR_ENTRYID, PR_DISPLAY_NAME, PR_CONTAINER_CLASS, PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY, PR_FOLDER_TYPE, PR_ATTR_HIDDEN]);

	echo "Available folders in store '{$user}':\n" . str_repeat("-", 50) . "\n";
	foreach ($subfolders as $folder) {
		// do not display hidden and search folders
		if ((isset($folder[PR_ATTR_HIDDEN]) && $folder[PR_ATTR_HIDDEN]) ||
			(isset($folder[PR_FOLDER_TYPE]) && $folder[PR_FOLDER_TYPE] == FOLDER_SEARCH)) {
			continue;
		}

		// handle some special folders
		if ((strtoupper((string) $user) != 'SYSTEM') &&
			((isset($inboxProps[PR_SOURCE_KEY]) && $folder[PR_SOURCE_KEY] == $inboxProps[PR_SOURCE_KEY]) ||
			$folder[PR_ENTRYID] == $storeProps[PR_IPM_SENTMAIL_ENTRYID] ||
			$folder[PR_ENTRYID] == $storeProps[PR_IPM_WASTEBASKET_ENTRYID])) {
			$folder[PR_CONTAINER_CLASS] = "IPF.Note";
		}

		if (isset($folder[PR_CONTAINER_CLASS]) && array_key_exists($folder[PR_CONTAINER_CLASS], $supported_classes)) {
			echo "Folder name:\t" . $folder[PR_DISPLAY_NAME] . "\n";
			echo "Folder ID:\t" . bin2hex((string) $folder[PR_SOURCE_KEY]) . "\n";
			echo "Type:\t\t" . $supported_classes[$folder[PR_CONTAINER_CLASS]] . "\n";
			echo "ShortId:\t" . generateFolderHash(bin2hex((string) $folder[PR_SOURCE_KEY]), 'U') . "\n";
			echo "\n";
		}
	}
}

function generateFolderHash(string $backendid, string $folderOrigin): string {
	// Hash backendid with crc32 and get the hex representation of it.
	// 5 chars of hash + $folderOrigin should still be enough to avoid collisions.
	return substr($folderOrigin . dechex(crc32($backendid)), 0, 6);
}
