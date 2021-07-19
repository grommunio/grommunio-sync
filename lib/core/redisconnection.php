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
    private $luaCAS = <<<EOF
    local old = redis.call('GET', KEYS[1]);
    if not old or old == ARGV[1] then
        redis.call('SET',KEYS[1], ARGV[2]);
        return 1
    else
        return 0
    end
EOF;

    private $luaCASHash = <<<EOF
    local old = redis.call('HGET', KEYS[1], ARGV[1]);
    if not old or old == ARGV[2] then
        redis.call('HSET', KEYS[1], ARGV[1], ARGV[3]);
        return 1
    else
        return 0
    end
EOF;

    /**
     * Constructor
     *
     * @access public
     */
    public function __construct($host ='localhost', $port = 6379) {
        $this->redisObj = new Redis();
        // Opening a redis connection
        $this->redisObj->connect($host, $port);
    }

    function getKey($key) {
        try {
            return $this->redisObj->get($key);
        }
        catch(Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("%s->getKey(): ", get_class($this), $e->getMessage()));
        }
    }
    function setKey($key, $value, $ttl=-1) {
        try {
            if ($ttl > 0) {
                return $this->redisObj->setEx($key, $ttl, $value);
            }
            else {
                return $this->redisObj->set($key, $value);
            }
        }
        catch(Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("%s->setKey(): ", get_class($this), $e->getMessage()));
        }
    }
    function delKey($key) {
        try {
            return $this->redisObj->del($key);
        }
        catch(Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("%s->delKey(): ", get_class($this), $e->getMessage()));
        }
    }

    function get() {
        return $this->redisObj;
    }

    function CAS($key, $oldvalue, $newvalue) {
        // custom Compare-and-swap implementation using LUA
        return $this->redisObj->eval($this->luaCAS, array($key, $oldvalue, $newvalue), 1);
    }
    function CASHash($key, $subkey, $oldvalue, $newvalue) {
        // custom Compare-and-swap implementation using LUA for a hash subkey
        return $this->redisObj->eval($this->luaCASHash, array($key, $subkey, $oldvalue, $newvalue), 1);
    }
}

