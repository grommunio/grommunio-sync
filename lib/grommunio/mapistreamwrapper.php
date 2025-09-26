<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Wraps a mapi stream as a standard php stream
 * The used method names are predefined and can not be altered.
 */

class MAPIStreamWrapper {
	public const PROTOCOL = "mapistream";

	private $mapistream;
	private $position;
	private $streamlength;
	private $toTruncate;
	private $truncateHtmlSafe;
	private $context;

	/**
	 * Opens the stream
	 * The mapistream reference is passed over the context.
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
		if (!isset($contextOptions[self::PROTOCOL]['stream'])) {
			return false;
		}

		$this->position = 0;
		$this->toTruncate = false;
		$this->truncateHtmlSafe = (isset($contextOptions[self::PROTOCOL]['truncatehtmlsafe'])) ? $contextOptions[self::PROTOCOL]['truncatehtmlsafe'] : false;

		// this is our stream!
		$this->mapistream = $contextOptions[self::PROTOCOL]['stream'];

		// get the data length from mapi
		if ($this->mapistream) {
			$stat = mapi_stream_stat($this->mapistream);
			$this->streamlength = $stat["cb"];
		}
		else {
			$this->streamlength = 0;
		}

		SLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIStreamWrapper::stream_open(): initialized mapistream: %s - streamlength: %d - HTML-safe-truncate: %s", $this->mapistream, $this->streamlength, Utils::PrintAsString($this->truncateHtmlSafe)));

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
		$len = ($this->position + $len > $this->streamlength) ? ($this->streamlength - $this->position) : $len;

		// read 4 additional bytes from the stream so we can always truncate correctly
		if ($this->toTruncate && $this->position + $len >= $this->streamlength) {
			$len += 4;
		}
		if ($this->mapistream) {
			$data = mapi_stream_read($this->mapistream, $len);
		}
		else {
			$data = "";
		}
		$this->position += strlen($data);

		// we need to truncate UTF8 compatible if ftruncate() was called
		if ($this->toTruncate && $this->position >= $this->streamlength) {
			$data = Utils::Utf8_truncate($data, $this->streamlength, $this->truncateHtmlSafe);
		}

		return $data;
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
		switch ($whence) {
			case SEEK_SET:
				$mapiWhence = STREAM_SEEK_SET;
				break;

			case SEEK_END:
				$mapiWhence = STREAM_SEEK_END;
				break;

			default:
				$mapiWhence = STREAM_SEEK_CUR;
		}

		return mapi_stream_seek($this->mapistream, $offset, $mapiWhence);
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
		return $this->position >= $this->streamlength;
	}

	/**
	 * Truncates the stream to the new size.
	 *
	 * @param int $new_size
	 *
	 * @return bool
	 */
	public function stream_truncate($new_size) {
		$this->streamlength = $new_size;
		$this->toTruncate = true;

		if ($this->position > $this->streamlength) {
			SLog::Write(LOGLEVEL_WARN, sprintf("MAPIStreamWrapper->stream_truncate(): stream position (%d) ahead of new size of %d. Repositioning pointer to end of stream.", $this->position, $this->streamlength));
			$this->position = $this->streamlength;
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
			7 => $this->streamlength,
			'size' => $this->streamlength,
		];
	}

	/**
	 * Instantiates a MAPIStreamWrapper.
	 *
	 * @param mapistream $mapistream       The stream to be wrapped
	 * @param bool       $truncatehtmlsafe Indicates if a truncation should be done html-safe - default: false
	 *
	 * @return MAPIStreamWrapper
	 */
	public static function Open($mapistream, $truncatehtmlsafe = false) {
		$context = stream_context_create([self::PROTOCOL => ['stream' => &$mapistream, 'truncatehtmlsafe' => $truncatehtmlsafe]]);

		return fopen(self::PROTOCOL . "://", 'r', false, $context);
	}
}

stream_wrapper_register(MAPIStreamWrapper::PROTOCOL, "MAPIStreamWrapper");
