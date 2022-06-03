<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Main exception related to errors regarding synchronization
 */
class StatusException extends GSyncException {
	protected $defaultLogLevel = LOGLEVEL_INFO;
}
