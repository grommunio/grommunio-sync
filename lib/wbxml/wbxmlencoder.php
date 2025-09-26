<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXMLEncoder encodes to Wap Binary XML
 */

class WBXMLEncoder extends WBXMLDefs {
	private $_dtd;
	private $_out;
	private $_tagcp = 0;
	private $log = false;
	private $logStack = [];

	// We use a delayed output mechanism in which we only output a tag when it actually has something
	// in it. This can cause entire XML trees to disappear if they don't have output data in them; Ie
	// calling 'startTag' 10 times, and then 'endTag' will cause 0 bytes of output apart from the header.

	// Only when content() is called do we output the current stack of tags

	private $_stack;
	private $multipart; // the content is multipart
	private $bodyparts;

	public function __construct($output, $multipart = false) {
		$this->log = SLog::IsWbxmlDebugEnabled();
		$this->_out = $output;

		// reverse-map the DTD
		foreach ($this->dtd["namespaces"] as $nsid => $nsname) {
			$this->_dtd["namespaces"][$nsname] = $nsid;
		}

		foreach ($this->dtd["codes"] as $cp => $value) {
			$this->_dtd["codes"][$cp] = [];
			foreach ($this->dtd["codes"][$cp] as $tagid => $tagname) {
				$this->_dtd["codes"][$cp][$tagname] = $tagid;
			}
		}
		$this->_stack = [];
		$this->multipart = $multipart;
		$this->bodyparts = [];
	}

	/**
	 * Puts the WBXML header on the stream.
	 */
	public function startWBXML() {
		if ($this->multipart) {
			header("Content-Type: application/vnd.ms-sync.multipart");
			SLog::Write(LOGLEVEL_DEBUG, "WBXMLEncoder->startWBXML() type: vnd.ms-sync.multipart");
		}
		else {
			header("Content-Type: application/vnd.ms-sync.wbxml");
			SLog::Write(LOGLEVEL_DEBUG, "WBXMLEncoder->startWBXML() type: vnd.ms-sync.wbxml");
		}

		$this->outByte(0x03); // WBXML 1.3
		$this->outMBUInt(0x01); // Public ID 1
		$this->outMBUInt(106); // UTF-8
		$this->outMBUInt(0x00); // string table length (0)
	}

	/**
	 * Puts a StartTag on the output stack.
	 *
	 * @param mixed $tag
	 * @param mixed $attributes
	 * @param mixed $nocontent
	 */
	public function startTag($tag, $attributes = false, $nocontent = false) {
		$stackelem = [];

		if (!$nocontent) {
			$stackelem['tag'] = $tag;
			$stackelem['nocontent'] = $nocontent;
			$stackelem['sent'] = false;

			array_push($this->_stack, $stackelem);

		// If 'nocontent' is specified, then apparently the user wants to force
		// output of an empty tag, and we therefore output the stack here
		}
		else {
			$this->_outputStack();
			$this->_startTag($tag, $nocontent);
		}
	}

	/**
	 * Puts an EndTag on the stack.
	 */
	public function endTag() {
		$stackelem = array_pop($this->_stack);

		// Only output end tags for items that have had a start tag sent
		if ($stackelem['sent']) {
			$this->_endTag();

			if (count($this->_stack) == 0) {
				SLog::Write(LOGLEVEL_DEBUG, "WBXMLEncoder->endTag() WBXML output completed");
			}
			if (count($this->_stack) == 0 && $this->multipart == true) {
				$this->processMultipart();
			}
			if (count($this->_stack) == 0) {
				$this->writeLog();
			}
		}
	}

	/**
	 * Puts content on the output stack.
	 *
	 * @param string $content
	 */
	public function content($content) {
		// We need to filter out any \0 chars because it's the string terminator in WBXML. We currently
		// cannot send \0 characters within the XML content anywhere.
		$content = str_replace("\0", "", $content);
		if ("x" . $content == "x") {
			return;
		}
		$this->_outputStack();
		$this->_content($content);
	}

	/**
	 * Puts content of a stream on the output stack AND closes it.
	 *
	 * @param resource $stream
	 * @param bool     $asBase64 if true, the data will be encoded as base64, default: false
	 * @param bool     $opaque   if true, output the opaque data, default: false
	 */
	public function contentStream($stream, $asBase64 = false, $opaque = false) {
		// Do not append filters to opaque data as it might contain null char
		if (!$asBase64 && !$opaque) {
			stream_filter_register('replacenullchar', 'ReplaceNullcharFilter');
			$rnc_filter = stream_filter_append($stream, 'replacenullchar');
		}

		$this->_outputStack();
		$this->_contentStream($stream, $asBase64, $opaque);

		if (!$asBase64 && !$opaque) {
			stream_filter_remove($rnc_filter);
		}

		fclose($stream);
	}

	/**
	 * Gets the value of multipart.
	 *
	 * @return bool
	 */
	public function getMultipart() {
		return $this->multipart;
	}

	/**
	 * Adds a bodypart.
	 *
	 * @param Stream $bp
	 */
	public function addBodypartStream($bp) {
		if (!is_resource($bp)) {
			throw new WBXMLException("WBXMLEncoder->addBodypartStream(): trying to add a " . gettype($bp) . " instead of a stream");
		}
		if ($this->multipart) {
			$this->bodyparts[] = $bp;
		}
	}

	/**
	 * Gets the number of bodyparts.
	 *
	 * @return int
	 */
	public function getBodypartsCount() {
		return count($this->bodyparts);
	}

	/*----------------------------------------------------------------------------------------------------------
	 * Private WBXMLEncoder stuff
	 */

	/**
	 * Output any tags on the stack that haven't been output yet.
	 */
	private function _outputStack() {
		$stackCount = count($this->_stack);
		for ($i = 0; $i < $stackCount; ++$i) {
			if (!$this->_stack[$i]['sent']) {
				$this->_startTag($this->_stack[$i]['tag'], $this->_stack[$i]['nocontent']);
				$this->_stack[$i]['sent'] = true;
			}
		}
	}

	/**
	 * Outputs an actual start tag.
	 *
	 * @param mixed $tag
	 * @param mixed $nocontent
	 */
	private function _startTag($tag, $nocontent = false) {
		if ($this->log) {
			$this->logStartTag($tag, $nocontent);
		}

		$mapping = $this->getMapping($tag);

		if (!$mapping) {
			return false;
		}

		if ($this->_tagcp != $mapping["cp"]) {
			$this->outSwitchPage($mapping["cp"]);
			$this->_tagcp = $mapping["cp"];
		}

		$code = $mapping["code"];

		if (!isset($nocontent) || !$nocontent) {
			$code |= 0x40;
		}

		$this->outByte($code);
	}

	/**
	 * Outputs actual data.
	 *
	 * @param string $content
	 */
	private function _content($content) {
		if ($this->log) {
			$this->logContent($content);
		}
		$this->outByte(self::WBXML_STR_I);
		$this->outTermStr($content);
	}

	/**
	 * Outputs actual data coming from a stream, optionally encoded as base64.
	 *
	 * @param resource $stream
	 * @param bool     $asBase64
	 * @param mixed    $opaque
	 */
	private function _contentStream($stream, $asBase64, $opaque) {
		$stat = fstat($stream);
		// write full stream, including the finalizing terminator to the output stream (stuff outTermStr() would do)
		if ($opaque) {
			$this->outByte(self::WBXML_OPAQUE);
			$this->outMBUInt($stat['size']);
		}
		else {
			$this->outByte(self::WBXML_STR_I);
		}

		if ($asBase64) {
			$out_filter = stream_filter_append($this->_out, 'convert.base64-encode');
		}
		$written = stream_copy_to_stream($stream, $this->_out);
		if ($asBase64) {
			stream_filter_remove($out_filter);
		}
		if (!$opaque) {
			fwrite($this->_out, chr(0));
		}

		if ($this->log) {
			// data is out, do some logging
			$this->logContent(sprintf("<<< written %d of %d bytes of %s data >>>", $written, $stat['size'], $asBase64 ? "base64 encoded" : "plain"));
		}
	}

	/**
	 * Outputs an actual end tag.
	 */
	private function _endTag() {
		if ($this->log) {
			$this->logEndTag();
		}
		$this->outByte(self::WBXML_END);
	}

	/**
	 * Outputs a byte.
	 *
	 * @param mixed $byte
	 */
	private function outByte($byte) {
		fwrite($this->_out, chr($byte));
	}

	/**
	 * Output the multibyte integers to the stream.
	 *
	 * A multi-byte integer consists of a series of octets,
	 * where the most significant bit is the continuation flag
	 * and the remaining seven bits are a scalar value.
	 * The octets are arranged in a big-endian order,
	 * eg, the most significant seven bits are transmitted first.
	 *
	 * @see https://www.w3.org/1999/06/NOTE-wbxml-19990624/#_Toc443384895
	 *
	 * @param int $uint
	 */
	private function outMBUInt($uint) {
		if ($uint == 0x0) {
			return $this->outByte($uint);
		}

		$out = '';

		for ($i = 0; $uint != 0; ++$i) {
			$byte = $uint & 0x7F;
			$uint = $uint >> 7;
			if ($i == 0) {
				$out = chr($byte) . $out;
			}
			else {
				$out = chr($byte | 0x80) . $out;
			}
		}
		fwrite($this->_out, $out);
	}

	/**
	 * Outputs content with string terminator.
	 *
	 * @param mixed $content
	 */
	private function outTermStr($content) {
		fwrite($this->_out, $content);
		fwrite($this->_out, chr(0));
	}

	/**
	 * Switches the codepage.
	 *
	 * @param mixed $page
	 */
	private function outSwitchPage($page) {
		$this->outByte(self::WBXML_SWITCH_PAGE);
		$this->outByte($page);
	}

	/**
	 * Get the mapping for a tag.
	 *
	 * @param mixed $tag
	 *
	 * @return array
	 */
	private function getMapping($tag) {
		$mapping = [];

		$split = $this->splitTag($tag);

		if (isset($split["ns"])) {
			$cp = $this->_dtd["namespaces"][$split["ns"]];
		}
		else {
			$cp = 0;
		}

		$code = $this->_dtd["codes"][$cp][$split["tag"]];

		$mapping["cp"] = $cp;
		$mapping["code"] = $code;

		return $mapping;
	}

	/**
	 * Split a tag from a the fulltag (namespace + tag).
	 *
	 * @param mixed $fulltag
	 *
	 * @return array keys: 'ns' (namespace), 'tag' (tag)
	 */
	private function splitTag($fulltag) {
		$ns = false;
		$pos = strpos($fulltag, chr(58)); // chr(58) == ':'

		if ($pos) {
			$ns = substr($fulltag, 0, $pos);
			$tag = substr($fulltag, $pos + 1);
		}
		else {
			$tag = $fulltag;
		}

		$ret = [];
		if ($ns) {
			$ret["ns"] = $ns;
		}
		$ret["tag"] = $tag;

		return $ret;
	}

	/**
	 * Logs a StartTag to SLog.
	 *
	 * @param mixed $tag
	 * @param mixed $nocontent
	 */
	private function logStartTag($tag, $nocontent) {
		$spaces = str_repeat(" ", count($this->logStack));
		if ($nocontent) {
			SLog::Write(LOGLEVEL_WBXML, "O " . $spaces . " <{$tag}/>");
		}
		else {
			array_push($this->logStack, $tag);
			SLog::Write(LOGLEVEL_WBXML, "O " . $spaces . " <{$tag}>");
		}
	}

	/**
	 * Logs a EndTag to SLog.
	 */
	private function logEndTag() {
		$spaces = str_repeat(" ", count($this->logStack));
		$tag = array_pop($this->logStack);
		SLog::Write(LOGLEVEL_WBXML, "O " . $spaces . "</{$tag}>");
	}

	/**
	 * Logs content to SLog.
	 *
	 * @param string $content
	 */
	private function logContent($content) {
		$spaces = str_repeat(" ", count($this->logStack));
		SLog::Write(LOGLEVEL_WBXML, "O " . $spaces . $content);
	}

	/**
	 * Processes the multipart response.
	 */
	private function processMultipart() {
		SLog::Write(LOGLEVEL_DEBUG, sprintf("WBXMLEncoder->processMultipart() with %d parts to be processed", $this->getBodypartsCount()));
		$len = ob_get_length();
		$buffer = ob_get_clean();
		$nrBodyparts = $this->getBodypartsCount();
		$blockstart = (($nrBodyparts + 1) * 2) * 4 + 4;

		fwrite($this->_out, pack("iii", $nrBodyparts + 1, $blockstart, $len));

		foreach ($this->bodyparts as $i => $bp) {
			$blockstart = $blockstart + $len;
			$len = fstat($bp);
			$len = (isset($len['size'])) ? $len['size'] : 0;
			if ($len == 0) {
				SLog::Write(LOGLEVEL_WARN, sprintf("WBXMLEncoder->processMultipart(): the length of the body part at position %d is 0", $i));
			}
			fwrite($this->_out, pack("ii", $blockstart, $len));
		}

		fwrite($this->_out, $buffer);

		foreach ($this->bodyparts as $bp) {
			stream_copy_to_stream($bp, $this->_out);
			fclose($bp);
		}
	}

	/**
	 * Writes the sent WBXML data to the log if it is not bigger than 512K.
	 */
	private function writeLog() {
		if (ob_get_length() === false) {
			$data = "output buffer disabled";
		}
		elseif (ob_get_length() < 524288) {
			$data = base64_encode(ob_get_contents());
		}
		else {
			$data = "more than 512K of data";
		}
		SLog::Write(LOGLEVEL_WBXML, "WBXML-OUT: " . $data, false);
	}
}
