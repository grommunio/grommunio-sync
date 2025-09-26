<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Exception sending a "401 Unauthorized" to the mobile
 */
class AuthenticationRequiredException extends HTTPReturnCodeException {
	protected $defaultLogLevel = LOGLEVEL_INFO;
	protected $httpReturnCode = HTTP_CODE_401;
	protected $httpReturnMessage = "Unauthorized";
	protected $httpHeaders = ['WWW-Authenticate: Basic realm="GSync"'];
	protected $showLegal = true;
}
