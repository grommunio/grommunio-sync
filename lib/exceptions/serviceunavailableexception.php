<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Exception sending a "503 Service Unavailable" to the mobile.
 */

class ServiceUnavailableException extends HTTPReturnCodeException {
	protected $defaultLogLevel = LOGLEVEL_INFO;
	protected $httpReturnCode = HTTP_CODE_503;
	protected $httpReturnMessage = "Service Unavailable";
	protected $httpHeaders = [];
	protected $showLegal = false;

	public function __construct($message = "", $code = 0, $previous = null, $logLevel = false) {
		parent::__construct($message, $code, $previous, $logLevel);
		if (RETRY_AFTER_DELAY !== false) {
			$this->httpHeaders[] = 'Retry-After: ' . RETRY_AFTER_DELAY;
		}
	}
}
