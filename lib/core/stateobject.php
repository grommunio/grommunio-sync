<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Simple data object with some PHP magic
 */

class StateObject implements JsonSerializable {
	private $SO_internalid;
	protected $data = [];
	protected $unsetdata = [];
	protected $changed = false;

	/**
	 * Returns the unique id of that data object.
	 *
	 * @return array
	 */
	public function GetID() {
		if (!isset($this->SO_internalid)) {
			$this->SO_internalid = sprintf('%04x%04x%04x', mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF));
		}

		return $this->SO_internalid;
	}

	/**
	 * Indicates if the data contained in this object was modified.
	 *
	 * @return array
	 */
	public function IsDataChanged() {
		return $this->changed;
	}

	/**
	 * PHP magic to set an instance variable.
	 *
	 * @param mixed $name
	 * @param mixed $value
	 *
	 * @return
	 */
	public function __set($name, $value) {
		$lname = strtolower($name);
		if (isset($this->data[$lname]) &&
				(
					(is_scalar($value) && !is_array($value) && $this->data[$lname] === $value) ||
				  (is_array($value) && is_array($this->data[$lname]) && $this->data[$lname] === $value)
				)) {
			return false;
		}

		$this->data[$lname] = $value;
		$this->changed = true;
	}

	/**
	 * PHP magic to get an instance variable
	 * if the variable was not set previously, the value of the
	 * Unsetdata array is returned.
	 *
	 * @param mixed $name
	 *
	 * @return
	 */
	public function __get($name) {
		$lname = strtolower($name);

		if (array_key_exists($lname, $this->data)) {
			return $this->data[$lname];
		}

		if (isset($this->unsetdata) && is_array($this->unsetdata) && array_key_exists($lname, $this->unsetdata)) {
			return $this->unsetdata[$lname];
		}

		return null;
	}

	/**
	 * PHP magic to check if an instance variable is set.
	 *
	 * @param mixed $name
	 *
	 * @return
	 */
	public function __isset($name) {
		return isset($this->data[strtolower($name)]);
	}

	/**
	 * PHP magic to remove an instance variable.
	 *
	 * @param mixed $name
	 *
	 * @return
	 */
	public function __unset($name) {
		if (isset($this->{$name})) {
			unset($this->data[strtolower($name)]);
			$this->changed = true;
		}
	}

	/**
	 * PHP magic to implement any getter, setter, has and delete operations
	 * on an instance variable.
	 * Methods like e.g. "SetVariableName($x)" and "GetVariableName()" are supported.
	 *
	 * @param mixed $name
	 * @param mixed $arguments
	 *
	 * @return mixed
	 */
	public function __call($name, $arguments) {
		$name = strtolower($name);
		$operator = substr($name, 0, 3);
		$var = substr($name, 3);

		if ($name == "postunserialize") {
			return $this->postUnserialize();
		}

		if ($operator == "set" && count($arguments) == 1) {
			$this->{$var} = $arguments[0];

			return true;
		}

		if ($operator == "set" && count($arguments) == 2 && $arguments[1] === false) {
			$this->data[$var] = $arguments[0];

			return true;
		}

		// getter without argument = return variable, null if not set
		if ($operator == "get" && count($arguments) == 0) {
			return $this->{$var};
		}

		// getter with one argument = return variable if set, else the argument
		if ($operator == "get" && count($arguments) == 1) {
			if (isset($this->{$var})) {
				return $this->{$var};
			}

			return $arguments[0];
		}

		if ($operator == "has" && count($arguments) == 0) {
			return isset($this->{$var});
		}

		if ($operator == "del" && count($arguments) == 0) {
			unset($this->{$var});

			return true;
		}

		throw new FatalNotImplementedException(sprintf("StateObject->__call('%s'): not implemented. op: {%s} args: %d", $name, $operator, count($arguments)));
	}

	/**
	 * Called after the StateObject was unserialized.
	 *
	 * @return bool
	 */
	protected function postUnserialize() {
		$this->changed = false;

		return true;
	}

	/**
	 * Callback function for failed unserialize.
	 *
	 * @throws StateInvalidException
	 */
	public static function ThrowStateInvalidException() {
		throw new StateInvalidException("Unserialization failed as class was not found or not compatible");
	}

	/**
	 * JsonSerializable interface method.
	 *
	 * Serializes the object to a value that can be serialized natively by json_encode()
	 *
	 * @return array
	 */
	public function jsonSerialize() {
		return [
			'gsSyncStateClass' => get_class($this),
			'data' => $this->data,
		];
	}

	/**
	 * Restores the object from a value provided by json_decode.
	 *
	 * @param $stdObj   stdClass Object
	 */
	public function jsonDeserialize($stdObj) {
		foreach ($stdObj->data as $prop => $val) {
			if (is_object($val) && isset($val->gsSyncStateClass)) {
				SLog::Write(LOGLEVEL_DEBUG, sprintf("StateObject->jsonDeserialize(): top class '%s'", $val->gsSyncStateClass));
				$this->data[$prop] = new $val->gsSyncStateClass();
				$this->data[$prop]->jsonDeserialize($val);
				$this->data[$prop]->postUnserialize();
			}
			elseif (is_object($val)) {
				// json_decode converts arrays into objects, convert them back to arrays
				$this->data[$prop] = [];
				foreach ($val as $k => $v) {
					if (is_object($v) && isset($v->gsSyncStateClass)) {
						SLog::Write(LOGLEVEL_DEBUG, sprintf("StateObject->jsonDeserialize(): sub class '%s'", $v->gsSyncStateClass));
						// TODO: case should be removed when removing ASDevice backwards compatibility
						if (strcasecmp($v->gsSyncStateClass, "ASDevice") == 0) {
							$this->data[$prop][$k] = new ASDevice(Request::GetDeviceID(), Request::GetDeviceType(), Request::GetGETUser(), Request::GetUserAgent());
						}
						else {
							$this->data[$prop][$k] = new $v->gsSyncStateClass();
						}
						$this->data[$prop][$k]->jsonDeserialize($v);
						$this->data[$prop][$k]->postUnserialize();
					}
					else {
						$this->data[$prop][$k] = $v;
					}
				}
			}
			else {
				$this->data[$prop] = $val;
			}
		}
	}
}
