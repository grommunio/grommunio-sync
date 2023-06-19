<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * This file handles streaming of WBXML SyncObjects. It must be subclassed so
 * the internals of the object can be specified via $mapping. Basically we
 * set/read the object variables of the subclass according to the mappings
 */

class Streamer implements JsonSerializable {
	public const STREAMER_VAR = 1;
	public const STREAMER_ARRAY = 2;
	public const STREAMER_TYPE = 3;
	public const STREAMER_PROP = 4;
	public const STREAMER_RONOTIFY = 5;
	public const STREAMER_VALUEMAP = 20;
	public const STREAMER_TYPE_DATE = 1;
	public const STREAMER_TYPE_HEX = 2;
	public const STREAMER_TYPE_DATE_DASHES = 3;
	public const STREAMER_TYPE_STREAM = 4; // deprecated
	public const STREAMER_TYPE_IGNORE = 5;
	public const STREAMER_TYPE_SEND_EMPTY = 6;
	public const STREAMER_TYPE_NO_CONTAINER = 7;
	public const STREAMER_TYPE_COMMA_SEPARATED = 8;
	public const STREAMER_TYPE_SEMICOLON_SEPARATED = 9;
	public const STREAMER_TYPE_MULTIPART = 10;
	public const STREAMER_TYPE_STREAM_ASBASE64 = 11;
	public const STREAMER_TYPE_STREAM_ASPLAIN = 12;
	public const STREAMER_PRIVATE = 13;
	public const STRIP_PRIVATE_DATA = 1;
	public const STRIP_PRIVATE_SUBSTITUTE = 'Private';

	protected $mapping;
	public $flags;
	public $content;

	/**
	 * Constructor.
	 *
	 * @param array $mapping internal mapping of variables
	 */
	public function __construct($mapping) {
		$this->mapping = $mapping;
		$this->flags = false;
	}

	/**
	 * Return the streamer mapping for this object.
	 */
	public function GetMapping() {
		return $this->mapping;
	}

	/**
	 * Decodes the WBXML from a WBXMLdecoder until we reach the same depth level of WBXML.
	 * This means that if there are multiple objects at this level, then only the first is
	 * decoded SubOjects are auto-instantiated and decoded using the same functionality.
	 *
	 * @param WBXMLDecoder $decoder
	 */
	public function Decode(&$decoder) {
		WBXMLDecoder::ResetInWhile("decodeMain");
		while (WBXMLDecoder::InWhile("decodeMain")) {
			$entity = $decoder->getElement();

			if ($entity[EN_TYPE] == EN_TYPE_STARTTAG) {
				if (!($entity[EN_FLAGS] & EN_FLAGS_CONTENT)) {
					$map = $this->mapping[$entity[EN_TAG]];
					if (isset($map[self::STREAMER_ARRAY])) {
						$this->{$map[self::STREAMER_VAR]} = [];
					}
					elseif (isset($map[self::STREAMER_PROP]) && $map[self::STREAMER_PROP] == self::STREAMER_TYPE_SEND_EMPTY) {
						$this->{$map[self::STREAMER_VAR]} = "1";
					}
					elseif (!isset($map[self::STREAMER_TYPE])) {
						$this->{$map[self::STREAMER_VAR]} = "";
					}
					elseif ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_DATE || $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_DATE_DASHES) {
						$this->{$map[self::STREAMER_VAR]} = "";
					}

					continue;
				}
				// Found a start tag
				if (!isset($this->mapping[$entity[EN_TAG]])) {
					// This tag shouldn't be here, abort
					SLog::Write(LOGLEVEL_WBXMLSTACK, sprintf("Tag '%s' unexpected in type XML type '%s'", $entity[EN_TAG], get_class($this)));

					return false;
				}

				$map = $this->mapping[$entity[EN_TAG]];

				// Handle an array
				if (isset($map[self::STREAMER_ARRAY])) {
					WBXMLDecoder::ResetInWhile("decodeArray");
					while (WBXMLDecoder::InWhile("decodeArray")) {
						$streamertype = false;
						// do not get start tag for an array without a container
						if (!(isset($map[self::STREAMER_PROP]) && $map[self::STREAMER_PROP] == self::STREAMER_TYPE_NO_CONTAINER)) {
							// are there multiple possibilities for element encapsulation tags?
							if (is_array($map[self::STREAMER_ARRAY])) {
								$encapTagsTypes = $map[self::STREAMER_ARRAY];
							}
							else {
								// set $streamertype to null if the element is a single string (e.g. category)
								$encapTagsTypes = [$map[self::STREAMER_ARRAY] => isset($map[self::STREAMER_TYPE]) ? $map[self::STREAMER_TYPE] : null];
							}

							// Identify the used tag
							$streamertype = false;
							foreach ($encapTagsTypes as $tag => $type) {
								if ($decoder->getElementStartTag($tag)) {
									$streamertype = $type;
								}
							}
							if ($streamertype === false) {
								break;
							}
						}
						if ($streamertype) {
							$decoded = new $streamertype();
							$decoded->Decode($decoder);
						}
						else {
							$decoded = $decoder->getElementContent();
						}

						if (!isset($this->{$map[self::STREAMER_VAR]})) {
							$this->{$map[self::STREAMER_VAR]} = [$decoded];
						}
						else {
							array_push($this->{$map[self::STREAMER_VAR]}, $decoded);
						}

						if (!$decoder->getElementEndTag()) { // end tag of a container element
							return false;
						}

						if (isset($map[self::STREAMER_PROP]) && $map[self::STREAMER_PROP] == self::STREAMER_TYPE_NO_CONTAINER) {
							$e = $decoder->peek();
							// go back to the initial while if another block of no container elements is found
							if ($e[EN_TYPE] == EN_TYPE_STARTTAG) {
								continue 2;
							}
							// break on end tag because no container elements block end is reached
							if ($e[EN_TYPE] == EN_TYPE_ENDTAG) {
								break;
							}
							if (empty($e)) {
								break;
							}
						}
					}
					// do not get end tag for an array without a container
					if (!(isset($map[self::STREAMER_PROP]) && $map[self::STREAMER_PROP] == self::STREAMER_TYPE_NO_CONTAINER)) {
						if (!$decoder->getElementEndTag()) { // end tag of container
							return false;
						}
					}
				}
				else { // Handle single value
					if (isset($map[self::STREAMER_TYPE])) {
						// Complex type, decode recursively
						if ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_DATE || $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_DATE_DASHES) {
							$decoded = Utils::parseDate($decoder->getElementContent());
							if (!$decoder->getElementEndTag()) {
								return false;
							}
						}
						elseif ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_HEX) {
							$decoded = hex2bin($decoder->getElementContent());
							if (!$decoder->getElementEndTag()) {
								return false;
							}
						}
						// explode comma or semicolon strings into arrays
						elseif ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_COMMA_SEPARATED || $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_SEMICOLON_SEPARATED) {
							$glue = ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_COMMA_SEPARATED) ? ", " : "; ";
							$decoded = explode($glue, $decoder->getElementContent());
							if (!$decoder->getElementEndTag()) {
								return false;
							}
						}
						elseif ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_STREAM_ASPLAIN) {
							$decoded = StringStreamWrapper::Open($decoder->getElementContent());
							if (!$decoder->getElementEndTag()) {
								return false;
							}
						}
						else {
							$subdecoder = new $map[self::STREAMER_TYPE]();
							if ($subdecoder->Decode($decoder) === false) {
								return false;
							}

							$decoded = $subdecoder;

							if (!$decoder->getElementEndTag()) {
								SLog::Write(LOGLEVEL_WBXMLSTACK, sprintf("No end tag for '%s'", $entity[EN_TAG]));

								return false;
							}
						}
					}
					else {
						// Simple type, just get content
						$decoded = $decoder->getElementContent();

						if ($decoded === false) {
							// the tag is declared to have content, but no content is available.
							// set an empty content
							$decoded = "";
						}

						if (!$decoder->getElementEndTag()) {
							SLog::Write(LOGLEVEL_WBXMLSTACK, sprintf("Unable to get end tag for '%s'", $entity[EN_TAG]));

							return false;
						}
					}
					// $decoded now contains data object (or string)
					$this->{$map[self::STREAMER_VAR]} = $decoded;
				}
			}
			elseif ($entity[EN_TYPE] == EN_TYPE_ENDTAG) {
				$decoder->ungetElement($entity);

				break;
			}
			else {
				SLog::Write(LOGLEVEL_WBXMLSTACK, "Unexpected content in type");

				break;
			}
		}
	}

	/**
	 * Encodes this object and any subobjects - output is ordered according to mapping.
	 *
	 * @param WBXMLEncoder $encoder
	 */
	public function Encode(&$encoder) {
		// A return value if anything was streamed. We need for empty tags.
		$streamed = false;
		foreach ($this->mapping as $tag => $map) {
			if (isset($this->{$map[self::STREAMER_VAR]})) {
				// Variable is available
				if (is_object($this->{$map[self::STREAMER_VAR]})) {
					// Subobjects can do their own encoding
					if ($this->{$map[self::STREAMER_VAR]} instanceof Streamer) {
						$encoder->startTag($tag);
						$res = $this->{$map[self::STREAMER_VAR]}->Encode($encoder);
						$encoder->endTag();
						// nothing was streamed in previous encode but it should be streamed empty anyway
						if (!$res && isset($map[self::STREAMER_PROP]) && $map[self::STREAMER_PROP] == self::STREAMER_TYPE_SEND_EMPTY) {
							$encoder->startTag($tag, false, true);
						}
					}
					else {
						SLog::Write(LOGLEVEL_ERROR, sprintf("Streamer->Encode(): parameter '%s' of object %s is not of type Streamer", $map[self::STREAMER_VAR], get_class($this)));
					}
				}
				// Array of objects
				elseif (isset($map[self::STREAMER_ARRAY])) {
					if (empty($this->{$map[self::STREAMER_VAR]}) && isset($map[self::STREAMER_PROP]) && $map[self::STREAMER_PROP] == self::STREAMER_TYPE_SEND_EMPTY) {
						$encoder->startTag($tag, false, true);
					}
					else {
						// Outputs array container (eg Attachments)
						// Do not output start and end tag when type is STREAMER_TYPE_NO_CONTAINER
						if (!isset($map[self::STREAMER_PROP]) || $map[self::STREAMER_PROP] != self::STREAMER_TYPE_NO_CONTAINER) {
							$encoder->startTag($tag);
						}

						foreach ($this->{$map[self::STREAMER_VAR]} as $element) {
							if (is_object($element)) {
								// find corresponding encapsulation tag for element
								if (!is_array($map[self::STREAMER_ARRAY])) {
									$eltag = $map[self::STREAMER_ARRAY];
								}
								else {
									$eltag = array_search(get_class($element), $map[self::STREAMER_ARRAY]);
								}
								$encoder->startTag($eltag); // Outputs object container (eg Attachment)
								$element->Encode($encoder);
								$encoder->endTag();
							}
							else {
								// Do not output empty items. Not sure if we should output an empty tag with $encoder->startTag($map[self::STREAMER_ARRAY], false, true);
								if (strlen($element) > 0) {
									$encoder->startTag($map[self::STREAMER_ARRAY]);
									$encoder->content($element);
									$encoder->endTag();
									$streamed = true;
								}
							}
						}

						if (!isset($map[self::STREAMER_PROP]) || $map[self::STREAMER_PROP] != self::STREAMER_TYPE_NO_CONTAINER) {
							$encoder->endTag();
						}
					}
				}
				else {
					if (isset($map[self::STREAMER_TYPE]) && $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_IGNORE) {
						continue;
					}

					if ($encoder->getMultipart() && isset($map[self::STREAMER_PROP]) && $map[self::STREAMER_PROP] == self::STREAMER_TYPE_MULTIPART) {
						$encoder->addBodypartStream($this->{$map[self::STREAMER_VAR]});
						$encoder->startTag(SYNC_ITEMOPERATIONS_PART);
						$encoder->content($encoder->getBodypartsCount());
						$encoder->endTag();

						continue;
					}

					// Simple type
					if (!isset($map[self::STREAMER_TYPE]) && strlen($this->{$map[self::STREAMER_VAR]}) == 0) {
						// send empty tags
						if (isset($map[self::STREAMER_PROP]) && $map[self::STREAMER_PROP] == self::STREAMER_TYPE_SEND_EMPTY) {
							$encoder->startTag($tag, false, true);
						}

						// Do not output empty items. See above: $encoder->startTag($tag, false, true);
						continue;
					}
					$encoder->startTag($tag);

					if (isset($map[self::STREAMER_TYPE]) && ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_DATE || $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_DATE_DASHES)) {
						if ($this->{$map[self::STREAMER_VAR]} != 0) { // don't output 1-1-1970
							$encoder->content($this->formatDate($this->{$map[self::STREAMER_VAR]}, $map[self::STREAMER_TYPE]));
						}
					}
					elseif (isset($map[self::STREAMER_TYPE]) && $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_HEX) {
						$encoder->content(strtoupper(bin2hex($this->{$map[self::STREAMER_VAR]})));
					}
					elseif (isset($map[self::STREAMER_TYPE]) && $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_STREAM_ASPLAIN) {
						$encoder->contentStream($this->{$map[self::STREAMER_VAR]}, false);
					}
					elseif (isset($map[self::STREAMER_TYPE]) && ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_STREAM_ASBASE64 || $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_STREAM)) {
						$encoder->contentStream($this->{$map[self::STREAMER_VAR]}, true);
					}
					// implode comma or semicolon arrays into a string
					elseif (isset($map[self::STREAMER_TYPE]) && is_array($this->{$map[self::STREAMER_VAR]}) &&
						($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_COMMA_SEPARATED || $map[self::STREAMER_TYPE] == self::STREAMER_TYPE_SEMICOLON_SEPARATED)) {
						$glue = ($map[self::STREAMER_TYPE] == self::STREAMER_TYPE_COMMA_SEPARATED) ? ", " : "; ";
						$encoder->content(implode($glue, $this->{$map[self::STREAMER_VAR]}));
					}
					else {
						$encoder->content($this->{$map[self::STREAMER_VAR]});
					}
					$encoder->endTag();
					$streamed = true;
				}
			}
		}
		// Output our own content
		if (isset($this->content)) {
			$encoder->content($this->content);
		}

		return $streamed;
	}

	/**
	 * Removes not necessary data from the object.
	 *
	 * @param mixed $flags
	 *
	 * @return bool
	 */
	public function StripData($flags = 0) {
		foreach ($this->mapping as $k => $v) {
			if (isset($this->{$v[self::STREAMER_VAR]})) {
				if (is_object($this->{$v[self::STREAMER_VAR]}) && method_exists($this->{$v[self::STREAMER_VAR]}, "StripData")) {
					$this->{$v[self::STREAMER_VAR]}->StripData($flags);
				}
				elseif (isset($v[self::STREAMER_ARRAY]) && !empty($this->{$v[self::STREAMER_VAR]})) {
					foreach ($this->{$v[self::STREAMER_VAR]} as $element) {
						if (is_object($element) && method_exists($element, "StripData")) {
							$element->StripData($flags);
						}
						elseif ($flags === Streamer::STRIP_PRIVATE_DATA && isset($v[self::STREAMER_PRIVATE])) {
							if ($v[self::STREAMER_PRIVATE] !== true) {
								$this->{$v[self::STREAMER_VAR]} = $v[self::STREAMER_PRIVATE];
							}
							else {
								unset($this->{$v[self::STREAMER_VAR]});
							}
						}
					}
				}
				elseif ($flags === Streamer::STRIP_PRIVATE_DATA && isset($v[self::STREAMER_PRIVATE])) {
					if ($v[self::STREAMER_PRIVATE] !== true) {
						$this->{$v[self::STREAMER_VAR]} = $v[self::STREAMER_PRIVATE];
					}
					else {
						unset($this->{$v[self::STREAMER_VAR]});
					}
				}
			}
		}
		if ($flags === 0) {
			unset($this->mapping);
		}

		return true;
	}

	/**
	 * Returns SyncObject's streamer variable names.
	 *
	 * @return array
	 */
	public function GetStreamerVars() {
		$streamerVars = [];
		foreach ($this->mapping as $v) {
			$streamerVars[] = $v[self::STREAMER_VAR];
		}

		return $streamerVars;
	}

	/**
	 * JsonSerializable interface method.
	 *
	 * Serializes the object to a value that can be serialized natively by json_encode()
	 *
	 * @return array
	 */
	public function jsonSerialize() {
		$data = [];
		foreach ($this->mapping as $k => $v) {
			if (isset($this->{$v[self::STREAMER_VAR]})) {
				$data[$v[self::STREAMER_VAR]] = $this->{$v[self::STREAMER_VAR]};
			}
		}

		return [
			'gsSyncStateClass' => get_class($this),
			'data' => $data,
		];
	}

	/**
	 * Restores the object from a value provided by json_decode.
	 *
	 * @param $stdObj stdClass Object
	 */
	public function jsonDeserialize($stdObj) {
		foreach ($stdObj->data as $k => $v) {
			if (is_object($v) && isset($v->gsSyncStateClass)) {
				$this->{$k} = new $v->gsSyncStateClass();
				$this->{$k}->jsonDeserialize($v);
			}
			else {
				$this->{$k} = $v;
			}
		}
	}

	/*----------------------------------------------------------------------------------------------------------
	 * Private methods for conversion
	 */

	/**
	 * Formats a timestamp
	 * Oh yeah, this is beautiful. Exchange outputs date fields differently in calendar items
	 * and emails. We could just always send one or the other, but unfortunately nokia's 'Mail for
	 *  exchange' depends on this quirk. So we have to send a different date type depending on where
	 * it's used. Sigh.
	 *
	 * @param int $ts
	 * @param int $type
	 *
	 * @return string
	 */
	private function formatDate($ts, $type) {
		if ($type == self::STREAMER_TYPE_DATE) {
			return gmstrftime("%Y%m%dT%H%M%SZ", $ts);
		}
		if ($type == self::STREAMER_TYPE_DATE_DASHES) {
			return gmstrftime("%Y-%m-%dT%H:%M:%S.000Z", $ts);
		}
		// fallback to dashes (should never be reached)
		return gmstrftime("%Y-%m-%dT%H:%M:%S.000Z", $ts);
	}
}
