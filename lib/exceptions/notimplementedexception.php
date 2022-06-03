<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Exception indicating that that some code is not
 * available which is non-fatal.
 */
class NotImplementedException extends GSyncException {
	protected $defaultLogLevel = LOGLEVEL_ERROR;
}
