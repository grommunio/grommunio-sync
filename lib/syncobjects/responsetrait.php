<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2023 grommunio GmbH
 *
 * A trait used in response objects to ensure there is always an
 * serverid to be responded to the client.
 */

trait ResponseTrait {
	public $serverid;
	public $hasResponse;

	public function Check($logAsDebug = false) {
		return true;
	}
}
