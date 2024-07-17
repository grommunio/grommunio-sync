<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2015-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * Filters null characters out of a stream.
 */

class ReplaceNullcharFilter extends php_user_filter {
	/**
	 * This method is called whenever data is read from or written to the attached stream.
	 *
	 * @see php_user_filter::filter()
	 *
	 * @param resource $in
	 * @param resource $out
	 * @param int      $consumed
	 * @param bool     $closing
	 *
	 */
	public function filter($in, $out, &$consumed, $closing): int {
		while ($bucket = stream_bucket_make_writeable($in)) {
			$bucket->data = str_replace("\0", "", $bucket->data);
			$consumed += $bucket->datalen;
			stream_bucket_append($out, $bucket);
		}

		return PSFS_PASS_ON;
	}
}
