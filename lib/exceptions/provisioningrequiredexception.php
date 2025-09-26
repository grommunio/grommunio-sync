<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Exception announcing to the mobile that a provisioning request is required
 */
class ProvisioningRequiredException extends HTTPReturnCodeException {
	protected $defaultLogLevel = LOGLEVEL_INFO;
	protected $httpReturnCode = HTTP_CODE_449;
	protected $httpReturnMessage = "Retry after sending a PROVISION command";
}
