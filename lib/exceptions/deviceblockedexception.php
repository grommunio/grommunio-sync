<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2026 grommunio GmbH
 *
 * Exception announcing to the mobile that it is blocked by the device access rules
 */
class DeviceBlockedException extends HTTPReturnCodeException {
	protected $defaultLogLevel = LOGLEVEL_INFO;
	protected $httpReturnCode = HTTP_CODE_403;
	protected $httpReturnMessage = "Forbidden";
}
