<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2013,2015-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Wraps a string as a standard php stream
 * The used method names are predefined and can not be altered.
 */

class StringStreamWrapper {
	public const PROTOCOL = "stringstream";

	private $stringstream;
	private $position;
	private $stringlength;
	private $truncateHtmlSafe;
	private $context;

	/**
	 * Opens the stream
	 * The string to be streamed is passed over the context.
	 *
	 * @param string $path        Specifies the URL that was passed to the original function
	 * @param string $mode        The mode used to open the file, as detailed for fopen()
	 * @param int    $options     Holds additional flags set by the streams API
	 * @param string $opened_path if the path is opened successfully, and STREAM_USE_PATH is set in options,
	 *                            opened_path should be set to the full path of the file/resource that was actually opened
	 *
	 * @return bool
	 */
	public function stream_open($path, $mode, $options, &$opened_path) {
		$contextOptions = stream_context_get_options($this->context);
		if (!isset($contextOptions[self::PROTOCOL]['string'])) {
			return false;
		}

		$this->position = 0;

		// this is our stream!
		$this->stringstream = $contextOptions[self::PROTOCOL]['string'];
		$this->truncateHtmlSafe = (isset($contextOptions[self::PROTOCOL]['truncatehtmlsafe'])) ? $contextOptions[self::PROTOCOL]['truncatehtmlsafe'] : false;

		$this->stringlength = strlen($this->stringstream);
		SLog::Write(LOGLEVEL_DEBUG, sprintf("StringStreamWrapper::stream_open(): initialized stream length: %d - HTML-safe-truncate: %s", $this->stringlength, Utils::PrintAsString($this->truncateHtmlSafe)));

		return true;
	}

	/**
	 * Reads from stream.
	 *
	 * @param int $len amount of bytes to be read
	 *
	 * @return string
	 */
	public function stream_read($len) {
		$data = substr($this->stringstream, $this->position, $len);
		$this->position += strlen($data);

		return $data;
	}

	/**
	 * Writes data to the stream.
	 *
	 * @param string $data
	 *
	 * @return int
	 */
	public function stream_write($data) {
		$l = strlen($data);
		$this->stringstream = substr($this->stringstream, 0, $this->position) . $data . substr($this->stringstream, $this->position += $l);
		$this->stringlength = strlen($this->stringstream);

		return $l;
	}

	/**
	 * Stream "seek" functionality.
	 *
	 * @param int $offset
	 * @param int $whence
	 *
	 * @return bool
	 */
	public function stream_seek($offset, $whence = SEEK_SET) {
		if ($whence == SEEK_CUR) {
			$this->position += $offset;
		}
		elseif ($whence == SEEK_END) {
			$this->position = $this->stringlength + $offset;
		}
		else {
			$this->position = $offset;
		}

		return true;
	}

	/**
	 * Returns the current position on stream.
	 *
	 * @return int
	 */
	public function stream_tell() {
		return $this->position;
	}

	/**
	 * Indicates if 'end of file' is reached.
	 *
	 * @return bool
	 */
	public function stream_eof() {
		return $this->position >= $this->stringlength;
	}

	/**
	 * Truncates the stream to the new size.
	 *
	 * @param int $new_size
	 *
	 * @return bool
	 */
	public function stream_truncate($new_size) {
		// cut the string!
		$this->stringstream = Utils::Utf8_truncate($this->stringstream, $new_size, $this->truncateHtmlSafe);
		$this->stringlength = strlen($this->stringstream);

		if ($this->position > $this->stringlength) {
			SLog::Write(LOGLEVEL_WARN, sprintf("StringStreamWrapper->stream_truncate(): stream position (%d) ahead of new size of %d. Repositioning pointer to end of stream.", $this->position, $this->stringlength));
			$this->position = $this->stringlength;
		}

		return true;
	}

	/**
	 * Retrieves information about a stream.
	 *
	 * @return array
	 */
	public function stream_stat() {
		return [
			7 => $this->stringlength,
			'size' => $this->stringlength,
		];
	}

	/**
	 * Instantiates a StringStreamWrapper.
	 *
	 * @param string $string           The string to be wrapped
	 * @param bool   $truncatehtmlsafe Indicates if a truncation should be done html-safe - default: false
	 *
	 * @return StringStreamWrapper
	 */
	public static function Open($string, $truncatehtmlsafe = false) {
		$context = stream_context_create([self::PROTOCOL => ['string' => &$string, 'truncatehtmlsafe' => $truncatehtmlsafe]]);

		return fopen(self::PROTOCOL . "://", 'r', false, $context);
	}
}

stream_wrapper_register(StringStreamWrapper::PROTOCOL, "StringStreamWrapper");
