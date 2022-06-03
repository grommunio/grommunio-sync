<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 */

if (!defined("GROMMUNIOSYNC_VERSION")) {
	$path = escapeshellarg(dirname(realpath($_SERVER['SCRIPT_FILENAME'])));
	$branch = trim(exec("hash git 2>/dev/null && cd {$path} >/dev/null 2>&1 && git branch --no-color 2>/dev/null | sed -e '/^[^*]/d' -e \"s/* \\(.*\\)/\\1/\""));
	$version = exec("hash git 2>/dev/null && cd {$path} >/dev/null 2>&1 && git describe  --always 2>/dev/null");
	if ($branch && $version) {
		define("GROMMUNIOSYNC_VERSION", $branch . '-' . $version);
	}
	else {
		define("GROMMUNIOSYNC_VERSION", "GIT");
	}
}
