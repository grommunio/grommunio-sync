<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Defines general behavior of sub-WBXML entities (Sync* objects) that can be
 * parsed directly (as a stream) from WBXML. They are automatically decoded
 * according to $mapping by the Streamer and the Sync WBXML mappings.
 */

abstract class SyncObject extends Streamer {
	public const STREAMER_CHECKS = 6;
	public const STREAMER_CHECK_REQUIRED = 7;
	public const STREAMER_CHECK_ZEROORONE = 8;
	public const STREAMER_CHECK_NOTALLOWED = 9;
	public const STREAMER_CHECK_ONEVALUEOF = 10;
	public const STREAMER_CHECK_SETZERO = "setToValue0";
	public const STREAMER_CHECK_SETONE = "setToValue1";
	public const STREAMER_CHECK_SETTWO = "setToValue2";
	public const STREAMER_CHECK_SETEMPTY = "setToValueEmpty";
	public const STREAMER_CHECK_CMPLOWER = 13;
	public const STREAMER_CHECK_CMPHIGHER = 14;
	public const STREAMER_CHECK_LENGTHMAX = 15;
	public const STREAMER_CHECK_EMAIL = 16;

	protected $unsetVars;
	protected $supportsPrivateStripping;

	public function __construct($mapping) {
		$this->unsetVars = [];
		$this->supportsPrivateStripping = false;
		parent::__construct($mapping);
	}

	/**
	 * Sets all supported but not transmitted variables
	 * of this SyncObject to an "empty" value, so they are deleted when being saved.
	 *
	 * @param array $supportedFields array with all supported fields, if available
	 *
	 * @return bool
	 */
	public function emptySupported($supportedFields) {
		// Some devices do not send supported tag. In such a case remove all not set properties.
		if (($supportedFields === false || !is_array($supportedFields) || (empty($supportedFields)))) {
			if (defined('UNSET_UNDEFINED_PROPERTIES') &&
					UNSET_UNDEFINED_PROPERTIES &&
					(
						$this instanceof SyncContact ||
						$this instanceof SyncAppointment ||
						$this instanceof SyncTask
					)) {
				SLog::Write(LOGLEVEL_INFO, sprintf("%s->emptySupported(): no supported list available, emptying all not set parameters", get_class($this)));
				$supportedFields = array_keys($this->mapping);
			}
			else {
				return false;
			}
		}

		foreach ($supportedFields as $field) {
			if (!isset($this->mapping[$field])) {
				SLog::Write(LOGLEVEL_WARN, sprintf("Field '%s' is supposed to be emptied but is not defined for '%s'", $field, get_class($this)));

				continue;
			}
			$var = $this->mapping[$field][self::STREAMER_VAR];
			// add var to $this->unsetVars if $var is not set
			if (!isset($this->{$var})) {
				$this->unsetVars[] = $var;
			}
		}
		SLog::Write(LOGLEVEL_DEBUG, sprintf("Supported variables to be unset: %s", implode(',', $this->unsetVars)));

		return true;
	}

	/**
	 * Compares this a SyncObject to another.
	 * In case that all available mapped fields are exactly EQUAL, it returns true.
	 *
	 * @see SyncObject
	 *
	 * @param SyncObject $odo               other SyncObject
	 * @param bool       $log               flag to turn on logging
	 * @param bool       $strictTypeCompare to enforce type matching
	 *
	 * @return bool
	 */
	public function equals($odo, $log = false, $strictTypeCompare = false) {
		if ($odo === false) {
			return false;
		}

		// check objecttype
		if (!($odo instanceof SyncObject)) {
			SLog::Write(LOGLEVEL_DEBUG, "SyncObject->equals() the target object is not a SyncObject");

			return false;
		}

		// check for mapped fields
		foreach ($this->mapping as $v) {
			$val = $v[self::STREAMER_VAR];
			// array of values?
			if (isset($v[self::STREAMER_ARRAY])) {
				// if neither array is created then don't fail the comparison
				if (!isset($this->{$val}) && !isset($odo->{$val})) {
					SLog::Write(LOGLEVEL_DEBUG, sprintf("SyncObject->equals() array '%s' is NOT SET in either object", $val));

					continue;
				}
				if (is_array($this->{$val}) && is_array($odo->{$val})) {
					// if both arrays exist then seek for differences in the arrays
					if (count(array_diff($this->{$val}, $odo->{$val})) + count(array_diff($odo->{$val}, $this->{$val})) > 0) {
						SLog::Write(LOGLEVEL_DEBUG, sprintf("SyncObject->equals() items in array '%s' differ", $val));

						return false;
					}
				}
				else {
					SLog::Write(LOGLEVEL_DEBUG, sprintf("SyncObject->equals() array '%s' is set in one but not the other object", $val));

					return false;
				}
			}
			else {
				if (isset($this->{$val}, $odo->{$val})) {
					if ($strictTypeCompare) {
						if ($this->{$val} !== $odo->{$val}) {
							SLog::Write(LOGLEVEL_DEBUG, sprintf("SyncObject->equals() false on field '%s': '%s' != '%s' using strictTypeCompare", $val, Utils::PrintAsString($this->{$val}), Utils::PrintAsString($odo->{$val})));

							return false;
						}
					}
					else {
						if ($this->{$val} != $odo->{$val}) {
							SLog::Write(LOGLEVEL_DEBUG, sprintf("SyncObject->equals() false on field '%s': '%s' != '%s'", $val, Utils::PrintAsString($this->{$val}), Utils::PrintAsString($odo->{$val})));

							return false;
						}
					}
				}
				elseif (!isset($this->{$val}) && !isset($odo->{$val})) {
					continue;
				}
				else {
					SLog::Write(LOGLEVEL_DEBUG, sprintf("SyncObject->equals() false because field '%s' is only defined at one obj: '%s' != '%s'", $val, Utils::PrintAsString(isset($this->{$val})), Utils::PrintAsString(isset($odo->{$val}))));

					return false;
				}
			}
		}

		return true;
	}

	/**
	 * String representation of the object.
	 *
	 * @return string
	 */
	public function __toString() {
		$str = get_class($this) . " (\n";

		$streamerVars = [];
		foreach ($this->mapping as $k => $v) {
			$streamerVars[$v[self::STREAMER_VAR]] = (isset($v[self::STREAMER_TYPE])) ? $v[self::STREAMER_TYPE] : false;
		}

		foreach (get_object_vars($this) as $k => $v) {
			if ($k == "mapping") {
				continue;
			}

			if (array_key_exists($k, $streamerVars)) {
				$strV = "(S) ";
			}
			else {
				$strV = "";
			}

			// self::STREAMER_ARRAY ?
			if (is_array($v)) {
				$str .= "\t" . $strV . $k . "(Array) size: " . count($v) . "\n";
				foreach ($v as $value) {
					$str .= "\t\t" . Utils::PrintAsString($value) . "\n";
				}
			}
			elseif ($v instanceof SyncObject) {
				$str .= "\t" . $strV . $k . " => " . str_replace("\n", "\n\t\t\t", $v->__toString()) . "\n";
			}
			else {
				$str .= "\t" . $strV . $k . " => " . (isset($this->{$k}) ? Utils::PrintAsString($this->{$k}) : "null") . "\n";
			}
		}
		$str .= ")";

		return $str;
	}

	/**
	 * Returns the properties which have to be unset on the server.
	 *
	 * @return array
	 */
	public function getUnsetVars() {
		return $this->unsetVars;
	}

	/**
	 * Removes not necessary data from the object.
	 *
	 * @param mixed $flags
	 *
	 * @return bool
	 */
	public function StripData($flags = 0) {
		if ($flags === 0 && isset($this->unsetVars)) {
			unset($this->unsetVars);
		}

		return parent::StripData($flags);
	}

	/**
	 * Indicates if a SyncObject supports the private flag and stripping of private data.
	 * If an object does not support it, it will not be sent to the client but permanently be excluded from the sync.
	 *
	 * @return bool - default false defined in constructor - overwritten by implementation
	 */
	public function SupportsPrivateStripping() {
		return $this->supportsPrivateStripping;
	}

	/**
	 * Method checks if the object has the minimum of required parameters
	 * and fulfills semantic dependencies.
	 *
	 * General checks:
	 *     STREAMER_CHECK_REQUIRED      may have as value false (do not fix, ignore object!) or set-to-values: STREAMER_CHECK_SETZERO/ONE/TWO, STREAMER_CHECK_SETEMPTY
	 *     STREAMER_CHECK_ZEROORONE     may be 0 or 1, if none of these, set-to-values: STREAMER_CHECK_SETZERO or STREAMER_CHECK_SETONE
	 *     STREAMER_CHECK_NOTALLOWED    fails if is set
	 *     STREAMER_CHECK_ONEVALUEOF    expects an array with accepted values, fails if value is not in array
	 *
	 * Comparison:
	 *     STREAMER_CHECK_CMPLOWER      compares if the current parameter is lower as a literal or another parameter of the same object
	 *     STREAMER_CHECK_CMPHIGHER     compares if the current parameter is higher as a literal or another parameter of the same object
	 *
	 * @param bool $logAsDebug (opt) default is false, so messages are logged in WARN log level
	 *
	 * @return bool
	 */
	public function Check($logAsDebug = false) {
		// semantic checks general "turn off switch"
		if (defined("DO_SEMANTIC_CHECKS") && DO_SEMANTIC_CHECKS === false) {
			SLog::Write(LOGLEVEL_DEBUG, "SyncObject->Check(): semantic checks disabled. Check your config for 'DO_SEMANTIC_CHECKS'.");

			return true;
		}

		$defaultLogLevel = LOGLEVEL_WARN;

		// in some cases non-false checks should not provoke a WARN log but only a DEBUG log
		if ($logAsDebug) {
			$defaultLogLevel = LOGLEVEL_DEBUG;
		}

		$objClass = get_class($this);
		foreach ($this->mapping as $k => $v) {
			// check sub-objects recursively
			if (isset($v[self::STREAMER_TYPE], $this->{$v[self::STREAMER_VAR]})) {
				if ($this->{$v[self::STREAMER_VAR]} instanceof SyncObject) {
					if (!$this->{$v[self::STREAMER_VAR]}->Check($logAsDebug)) {
						return false;
					}
				}
				elseif (is_array($this->{$v[self::STREAMER_VAR]})) {
					foreach ($this->{$v[self::STREAMER_VAR]} as $subobj) {
						if ($subobj instanceof SyncObject && !$subobj->Check($logAsDebug)) {
							return false;
						}
					}
				}
			}

			if (isset($v[self::STREAMER_CHECKS])) {
				foreach ($v[self::STREAMER_CHECKS] as $rule => $condition) {
					// check REQUIRED settings
					if ($rule === self::STREAMER_CHECK_REQUIRED && (!isset($this->{$v[self::STREAMER_VAR]}) || $this->{$v[self::STREAMER_VAR]} === '')) {
						// parameter is not set but ..
						// requested to set to 0
						if ($condition === self::STREAMER_CHECK_SETZERO) {
							$this->{$v[self::STREAMER_VAR]} = 0;
							SLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Fixed object from type %s: parameter '%s' is set to 0", $objClass, $v[self::STREAMER_VAR]));
						}
						// requested to be set to 1
						elseif ($condition === self::STREAMER_CHECK_SETONE) {
							$this->{$v[self::STREAMER_VAR]} = 1;
							SLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Fixed object from type %s: parameter '%s' is set to 1", $objClass, $v[self::STREAMER_VAR]));
						}
						// requested to be set to 2
						elseif ($condition === self::STREAMER_CHECK_SETTWO) {
							$this->{$v[self::STREAMER_VAR]} = 2;
							SLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Fixed object from type %s: parameter '%s' is set to 2", $objClass, $v[self::STREAMER_VAR]));
						}
						// requested to be set to ''
						elseif ($condition === self::STREAMER_CHECK_SETEMPTY) {
							if (!isset($this->{$v[self::STREAMER_VAR]})) {
								$this->{$v[self::STREAMER_VAR]} = '';
								SLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Fixed object from type %s: parameter '%s' is set to ''", $objClass, $v[self::STREAMER_VAR]));
							}
						}
						// there is another value !== false
						elseif ($condition !== false) {
							$this->{$v[self::STREAMER_VAR]} = $condition;
							SLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Fixed object from type %s: parameter '%s' is set to '%s'", $objClass, $v[self::STREAMER_VAR], $condition));
						}
						// no fix available!
						else {
							SLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Unmet condition in object from type %s: parameter '%s' is required but not set. Check failed!", $objClass, $v[self::STREAMER_VAR]));

							return false;
						}
					} // end STREAMER_CHECK_REQUIRED

					// check STREAMER_CHECK_ZEROORONE
					if ($rule === self::STREAMER_CHECK_ZEROORONE && isset($this->{$v[self::STREAMER_VAR]})) {
						if ($this->{$v[self::STREAMER_VAR]} != 0 && $this->{$v[self::STREAMER_VAR]} != 1) {
							$newval = $condition === self::STREAMER_CHECK_SETZERO ? 0 : 1;
							$this->{$v[self::STREAMER_VAR]} = $newval;
							SLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Fixed object from type %s: parameter '%s' is set to '%s' as it was not 0 or 1", $objClass, $v[self::STREAMER_VAR], $newval));
						}
					}// end STREAMER_CHECK_ZEROORONE

					// check STREAMER_CHECK_ONEVALUEOF
					if ($rule === self::STREAMER_CHECK_ONEVALUEOF && isset($this->{$v[self::STREAMER_VAR]})) {
						if (!in_array($this->{$v[self::STREAMER_VAR]}, $condition)) {
							SLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): object from type %s: parameter '%s'->'%s' is not in the range of allowed values.", $objClass, $v[self::STREAMER_VAR], $this->{$v[self::STREAMER_VAR]}));

							return false;
						}
					}// end STREAMER_CHECK_ONEVALUEOF

					// Check value compared to other value or literal
					if ($rule === self::STREAMER_CHECK_CMPHIGHER || $rule === self::STREAMER_CHECK_CMPLOWER) {
						if (isset($this->{$v[self::STREAMER_VAR]})) {
							$cmp = false;
							// directly compare against literals
							if (is_int($condition)) {
								$cmp = $condition;
							}
							// check for invalid compare-to
							elseif (!isset($this->mapping[$condition])) {
								SLog::Write(LOGLEVEL_ERROR, sprintf("SyncObject->Check(): Can not compare parameter '%s' against the other value '%s' as it is not defined object from type %s. Please report this! Check skipped!", $objClass, $v[self::STREAMER_VAR], $condition));

								continue;
							}
							else {
								$cmpPar = $this->mapping[$condition][self::STREAMER_VAR];
								if (isset($this->{$cmpPar})) {
									$cmp = $this->{$cmpPar};
								}
							}

							if ($cmp === false) {
								SLog::Write(LOGLEVEL_WARN, sprintf("SyncObject->Check(): Unmet condition in object from type %s: parameter '%s' can not be compared, as the comparable is not set. Check failed!", $objClass, $v[self::STREAMER_VAR]));

								return false;
							}
							if (
									($rule == self::STREAMER_CHECK_CMPHIGHER && intval($this->{$v[self::STREAMER_VAR]}) < $cmp) ||
									($rule == self::STREAMER_CHECK_CMPLOWER && intval($this->{$v[self::STREAMER_VAR]}) > $cmp)
									) {
								SLog::Write(LOGLEVEL_WARN, sprintf(
									"SyncObject->Check(): Unmet condition in object from type %s: parameter '%s' is %s than '%s'. Check failed!",
									$objClass,
									$v[self::STREAMER_VAR],
									(($rule === self::STREAMER_CHECK_CMPHIGHER) ? 'LOWER' : 'HIGHER'),
									((isset($cmpPar) ? $cmpPar : $condition))
								));

								return false;
							}
						}
					} // STREAMER_CHECK_CMP*

					// check STREAMER_CHECK_LENGTHMAX
					if ($rule === self::STREAMER_CHECK_LENGTHMAX && isset($this->{$v[self::STREAMER_VAR]})) {
						if (is_array($this->{$v[self::STREAMER_VAR]})) {
							// implosion takes 2bytes, so we just assume ", " here
							$chkstr = implode(", ", $this->{$v[self::STREAMER_VAR]});
						}
						else {
							$chkstr = $this->{$v[self::STREAMER_VAR]};
						}

						if (strlen($chkstr) > $condition) {
							SLog::Write(LOGLEVEL_WARN, sprintf("SyncObject->Check(): object from type %s: parameter '%s' is longer than %d. Check failed", $objClass, $v[self::STREAMER_VAR], $condition));

							return false;
						}
					}// end STREAMER_CHECK_LENGTHMAX

					// check STREAMER_CHECK_EMAIL
					// if $condition is false then the check really fails. Otherwise invalid emails are removed.
					// if nothing is left (all emails were false), the parameter is set to condition
					if ($rule === self::STREAMER_CHECK_EMAIL && isset($this->{$v[self::STREAMER_VAR]})) {
						if ($condition === false && ((is_array($this->{$v[self::STREAMER_VAR]}) && empty($this->{$v[self::STREAMER_VAR]})) || strlen($this->{$v[self::STREAMER_VAR]}) == 0)) {
							continue;
						}

						$as_array = false;

						if (is_array($this->{$v[self::STREAMER_VAR]})) {
							$mails = $this->{$v[self::STREAMER_VAR]};
							$as_array = true;
						}
						else {
							$mails = [$this->{$v[self::STREAMER_VAR]}];
						}

						$output = [];
						foreach ($mails as $mail) {
							if (!Utils::CheckEmail($mail)) {
								SLog::Write(LOGLEVEL_WARN, sprintf("SyncObject->Check(): object from type %s: parameter '%s' contains an invalid email address '%s'. Address is removed.", $objClass, $v[self::STREAMER_VAR], $mail));
							}
							else {
								$output[] = $mail;
							}
						}
						if (count($mails) != count($output)) {
							if ($condition === false) {
								return false;
							}

							// nothing left, use $condition as new value
							if (count($output) == 0) {
								$output[] = $condition;
							}

							// if we are allowed to rewrite the attribute, we do that
							if ($as_array) {
								$this->{$v[self::STREAMER_VAR]} = $output;
							}
							else {
								$this->{$v[self::STREAMER_VAR]} = $output[0];
							}
						}
					}// end STREAMER_CHECK_EMAIL
				} // foreach CHECKS
			} // isset CHECKS
		} // foreach mapping

		return true;
	}

	/**
	 * Returns human friendly property name from its value if a mapping is available.
	 *
	 * @param array $v
	 * @param mixed $val
	 *
	 * @return mixed
	 */
	public function GetNameFromPropertyValue($v, $val) {
		if (isset($v[self::STREAMER_VALUEMAP][$val])) {
			return $v[self::STREAMER_VALUEMAP][$val];
		}

		return $val;
	}

	/**
	 * Called after the SyncObject was unserialized.
	 *
	 * @return bool
	 */
	public function postUnserialize() {
		return true;
	}
}
