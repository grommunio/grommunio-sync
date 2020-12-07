<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * Main exception related to errors regarding synchronization
 */
class StatusException extends ZPushException {
    protected $defaultLogLevel = LOGLEVEL_INFO;
}
