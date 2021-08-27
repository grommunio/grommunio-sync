<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Simple data object with some PHP magic
 */

class StateObject implements Serializable, JsonSerializable {
    private $SO_internalid;
    protected $data = array();
    protected $unsetdata = array();
    protected $changed = false;

    /**
     * Returns the unique id of that data object
     *
     * @access public
     * @return array
     */
    public function GetID() {
        if (!isset($this->SO_internalid))
            $this->SO_internalid = sprintf('%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

        return $this->SO_internalid;
    }

    /**
     * Returns the internal array which contains all data of this object
     *
     * @access public
     * @return array
     */
    public function GetDataArray() {
        return $this->data;
    }

    /**
     * Sets the internal array which contains all data of this object
     *
     * @param array     $data           the data to be written
     * @param boolean   $markAsChanged  (opt) indicates if the object should be marked as "changed", default false
     *
     * @access public
     * @return array
     */
    public function SetDataArray($data, $markAsChanged = false) {
        $this->data = $data;
        if (isset($this->data['contentdata']) && is_array($this->data['contentdata'])) {
            // ASDevice contendata is array of stdClass objects,
            // but we need an array of arrays
            $this->data['contentdata'] = json_decode(json_encode($this->data['contentdata']), true);
        }
        $this->changed = $markAsChanged;
    }

    /**
     * Indicates if the data contained in this object was modified
     *
     * @access public
     * @return array
     */
    public function IsDataChanged() {
        return $this->changed;
    }

    /**
     * PHP magic to set an instance variable
     *
     * @access public
     * @return
     */
    public function __set($name, $value) {
        $lname = strtolower($name);
        if (isset($this->data[$lname]) &&
                ( (is_scalar($value) && !is_array($value) && $this->data[$lname] === $value) ||
                  (is_array($value) && is_array($this->data[$lname]) && $this->data[$lname] === $value)
                ))
            return false;

        $this->data[$lname] = $value;
        $this->changed = true;
    }

    /**
     * PHP magic to get an instance variable
     * if the variable was not set previousely, the value of the
     * Unsetdata array is returned
     *
     * @access public
     * @return
     */
    public function __get($name) {
        $lname = strtolower($name);

        if (array_key_exists($lname, $this->data))
            return $this->data[$lname];

        if (isset($this->unsetdata) && is_array($this->unsetdata) && array_key_exists($lname, $this->unsetdata))
            return $this->unsetdata[$lname];

        return null;
    }

    /**
     * PHP magic to check if an instance variable is set
     *
     * @access public
     * @return
     */
    public function __isset($name) {
        return isset($this->data[strtolower($name)]);
    }

    /**
     * PHP magic to remove an instance variable
     *
     * @access public
     * @return
     */
    public function __unset($name) {
        if (isset($this->$name)) {
            unset($this->data[strtolower($name)]);
            $this->changed = true;
        }
    }

    /**
     * PHP magic to implement any getter, setter, has and delete operations
     * on an instance variable.
     * Methods like e.g. "SetVariableName($x)" and "GetVariableName()" are supported
     *
     * @access public
     * @return mixed
     */
    public function __call($name, $arguments) {
        $name = strtolower($name);
        $operator = substr($name, 0,3);
        $var = substr($name,3);

        if ($name == "postunserialize") {
            return $this->postUnserialize();
        }

        if ($operator == "set" && count($arguments) == 1){
            $this->$var = $arguments[0];
            return true;
        }

        if ($operator == "set" && count($arguments) == 2 && $arguments[1] === false){
            $this->data[$var] = $arguments[0];
            return true;
        }

        // getter without argument = return variable, null if not set
        if ($operator == "get" && count($arguments) == 0) {
            return $this->$var;
        }

        // getter with one argument = return variable if set, else the argument
        else if ($operator == "get" && count($arguments) == 1) {
            if (isset($this->$var)) {
                return $this->$var;
            }
            else
                return $arguments[0];
        }

        if ($operator == "has" && count($arguments) == 0)
            return isset($this->$var);

        if ($operator == "del" && count($arguments) == 0) {
            unset($this->$var);
            return true;
        }

        throw new FatalNotImplementedException(sprintf("StateObject->__call('%s'): not implemented. op: {%s} args: %d", $name, $operator, count($arguments)));
    }

    /**
     * Method to serialize a StateObject
     *
     * @access public
     * @return array
     */
    public function serialize() {
        // perform tasks just before serialization
        $this->preSerialize();

        return serialize(array($this->SO_internalid,$this->data));
    }

    /**
     * Method to unserialize a StateObject
     *
     * @access public
     * @return array
     * @throws StateInvalidException
     */
    public function unserialize($data) {
        // throw a StateInvalidException if unserialize fails
        ini_set('unserialize_callback_func', 'StateObject::ThrowStateInvalidException');

        list($this->SO_internalid, $this->data) = unserialize($data);

        // perform tasks just after unserialization
        $this->postUnserialize();
        return true;
    }

    /**
     * Called before the StateObject is serialized
     *
     * @access protected
     * @return boolean
     */
    protected function preSerialize() {
        // make sure the object has an id before serialization
        $this->GetID();

        return true;
    }

    /**
     * Called after the StateObject was unserialized
     *
     * @access protected
     * @return boolean
     */
    protected function postUnserialize() {
        return true;
    }

    /**
     * Callback function for failed unserialize
     *
     * @access public
     * @throws StateInvalidException
     */
    public static function ThrowStateInvalidException() {
        throw new StateInvalidException("Unserialization failed as class was not found or not compatible");
    }


    /**
     * JsonSerializable interface method
     *
     * Serializes the object to a value that can be serialized natively by json_encode()
     *
     * @access public
     * @return array
     */
    public function jsonSerialize() {
        return [
            'gsSyncStateClass'  => get_class($this),
            'data'              => $this->data,
        ];
    }

    /**
     * Restores the object from a value provided by json_decode
     *
     * @param $stdObj   stdClass Object
     *
     * @access public
     * @return void
     */
    public function jsonDeserialize($stdObj) {
        foreach($stdObj->data as $prop => $val) {
            if (is_object($val) && isset($val->gsSyncStateClass)) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("StateObject->jsonDeserialize(): top class '%s'", $val->gsSyncStateClass));
                $this->data[$prop] = new $val->gsSyncStateClass;
                $this->data[$prop]->jsonDeserialize($val);
                $this->data[$prop]->postUnserialize();
            }
            else if (is_object($val)) {
                // json_decode converts arrays into objects, convert them back to arrays
                $this->data[$prop] = [];
                foreach ($val as $k => $v) {
                    if (is_object($v) && isset($v->gsSyncStateClass)) {
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("StateObject->jsonDeserialize(): sub class '%s'", $v->gsSyncStateClass));
                        if (strcasecmp($v->gsSyncStateClass, "ASDevice") == 0) {
                            $this->data[$prop][$k] = new ASDevice(Request::GetDeviceID(), Request::GetDeviceType(), Request::GetGETUser(), Request::GetUserAgent());
                        }
                        else {
                            $this->data[$prop][$k] = new $v->gsSyncStateClass;
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
