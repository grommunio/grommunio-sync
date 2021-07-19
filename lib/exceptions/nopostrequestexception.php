<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Exception thrown if the request is not a POST request
 * The code indicates if the request identified was a OPTIONS or GET request
 */
class NoPostRequestException extends FatalException {
    const OPTIONS_REQUEST = 1;
    const GET_REQUEST = 2;
    protected $defaultLogLevel = LOGLEVEL_DEBUG;
}
