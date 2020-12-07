<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * Configuration file for the memcache IPC provider.
 */

// Comma separated list of available memcache servers.
// Servers can be added as 'hostname:port,otherhost:port'
define('MEMCACHED_SERVERS','localhost:11211');

// Memcached down indicator
// In case memcached is not available, a lock file will be written to disk
define('MEMCACHED_DOWN_LOCK_FILE', '/tmp/grammm-sync-memcache-down');
// indicates how long the lock file will be maintained (in seconds)
define('MEMCACHED_DOWN_LOCK_EXPIRATION', 30);

// Prefix to used for keys
define('MEMCACHED_PREFIX', 'grammm-sync-ipc');

// Connection timeout in ms
define('MEMCACHED_TIMEOUT', 100);

// Mutex timeout (in seconds)
define('MEMCACHED_MUTEX_TIMEOUT', 5);

// Waiting time before re-trying to aquire mutex (in ms), must be higher than 0
define('MEMCACHED_BLOCK_WAIT', 10);
