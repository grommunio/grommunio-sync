<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * This is a fatal exception when e.g. a subsystem is not
 * available. The mobile should retry later.
 */
class UnavailableException extends FatalException {
}
