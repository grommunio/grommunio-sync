<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Exception sending a "401 Unauthorized" to the mobile
 */
class AuthenticationRequiredException extends HTTPReturnCodeException {
    protected $defaultLogLevel = LOGLEVEL_INFO;
    protected $httpReturnCode = HTTP_CODE_401;
    protected $httpReturnMessage = "Unauthorized";
    protected $httpHeaders = array('WWW-Authenticate: Basic realm="ZPush"');
    protected $showLegal = true;
}
