<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Exception thrown to return a an non 200 http return code
 * to the mobile.
 */
class HTTPReturnCodeException extends FatalException {
	protected $defaultLogLevel = LOGLEVEL_ERROR;
	protected $showLegal = false;

	public function __construct($message = "", $code = 0, $previous = null, $logLevel = false) {
		if ($code) {
			$this->httpReturnCode = $code;
		}
		parent::__construct($message, (int) $code, $previous, $logLevel);
	}
}
