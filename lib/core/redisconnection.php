<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2021 grommunio GmbH
 *
 * Redis Connection Class
 * CAS inspiration: https://github.com/WeihanLi/WeihanLi.Redis/blob/dev/src/WeihanLi.Redis/RedisExtensions.cs
 */

class RedisConnection {
	private $redisObj;
	private $luaCAS = <<<'EOF'
    local old = redis.call('GET', KEYS[1]);
    if not old or old == ARGV[1] then
        redis.call('SET',KEYS[1], ARGV[2]);
        return 1
    else
        return 0
    end
EOF;

	private $luaCASHash = <<<'EOF'
    local old = redis.call('HGET', KEYS[1], ARGV[1]);
    if not old or old == ARGV[2] then
        redis.call('HSET', KEYS[1], ARGV[1], ARGV[3]);
        return 1
    else
        return 0
    end
EOF;

	/**
	 * Constructor.
	 *
	 * @param mixed $host
	 * @param mixed $port
	 * @param mixed $auth
	 */
	public function __construct($host = REDIS_HOST, $port = REDIS_PORT, $auth = REDIS_AUTH) {
		$this->redisObj = new Redis();
		// Opening a redis connection
		$this->redisObj->connect($host, $port);
		if ($auth) {
			$this->redisObj->auth($auth);
		}
	}

	public function getKey($key) {
		try {
			return $this->redisObj->get($key);
		}
		catch (Exception $e) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("%s->getKey(): %s", get_class($this), $e->getMessage()));
		}
	}

	public function setKey($key, $value, $ttl = -1) {
		try {
			if ($ttl > 0) {
				return $this->redisObj->setEx($key, $ttl, $value);
			}

			return $this->redisObj->set($key, $value);
		}
		catch (Exception $e) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("%s->setKey(): %s", get_class($this), $e->getMessage()));
		}
	}

	public function delKey($key) {
		try {
			return $this->redisObj->del($key);
		}
		catch (Exception $e) {
			SLog::Write(LOGLEVEL_ERROR, sprintf("%s->delKey(): %s", get_class($this), $e->getMessage()));
		}
	}

	public function get() {
		return $this->redisObj;
	}

	public function CAS($key, $oldvalue, $newvalue) {
		// custom Compare-and-swap implementation using LUA
		return $this->redisObj->eval($this->luaCAS, [$key, $oldvalue, $newvalue], 1);
	}

	public function CASHash($key, $subkey, $oldvalue, $newvalue) {
		// custom Compare-and-swap implementation using LUA for a hash subkey
		return $this->redisObj->eval($this->luaCASHash, [$key, $subkey, $oldvalue, $newvalue], 1);
	}
}
