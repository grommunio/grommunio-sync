<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Simple transportation class for requested content parameter options
 */

class ContentParameters extends StateObject {
	protected $unsetdata = [
		'contentclass' => false,
		'foldertype' => '',
		'conflict' => false,
		'deletesasmoves' => true,
		'filtertype' => false,
		'truncation' => false,
		'rtftruncation' => false,
		'mimesupport' => false,
		'conversationmode' => false,
	];

	private $synckeyChanged = false;

	/**
	 * Expected magic getters and setters.
	 *
	 * GetContentClass() + SetContentClass()
	 * GetConflict() + SetConflict()
	 * GetDeletesAsMoves() + SetDeletesAsMoves()
	 * GetFilterType() + SetFilterType()
	 * GetTruncation() + SetTruncation
	 * GetRTFTruncation() + SetRTFTruncation()
	 * GetMimeSupport () + SetMimeSupport()
	 * GetMimeTruncation() + SetMimeTruncation()
	 * GetConversationMode() + SetConversationMode()
	 *
	 * @param mixed $name
	 * @param mixed $arguments
	 */

	/**
	 * Overwrite StateObject->__call so we are able to handle ContentParameters->BodyPreference()
	 * and ContentParameters->BodyPartPreference().
	 *
	 * @return mixed
	 */
	public function __call($name, $arguments) {
		if ($name === "BodyPreference") {
			return $this->BodyPreference($arguments[0]);
		}

		if ($name === "BodyPartPreference") {
			return $this->BodyPartPreference($arguments[0]);
		}

		return parent::__call($name, $arguments);
	}

	/**
	 * Instantiates/returns the bodypreference object for a type.
	 *
	 * @param int $type
	 *
	 * @return bool|int returns false if value is not defined
	 */
	public function BodyPreference($type) {
		if (!isset($this->bodypref)) {
			$this->bodypref = [];
		}

		if (isset($this->bodypref[$type])) {
			return $this->bodypref[$type];
		}

		$asb = new BodyPreference();
		$arr = (array) $this->bodypref;
		$arr[$type] = $asb;
		$this->bodypref = $arr;

		return $asb;
	}

	/**
	 * Instantiates/returns the bodypartpreference object for a type.
	 *
	 * @param int $type
	 *
	 * @return bool|int returns false if value is not defined
	 */
	public function BodyPartPreference($type) {
		if (!isset($this->bodypartpref)) {
			$this->bodypartpref = [];
		}

		if (isset($this->bodypartpref[$type])) {
			return $this->bodypartpref[$type];
		}

		$asb = new BodyPartPreference();
		$arr = (array) $this->bodypartpref;
		$arr[$type] = $asb;
		$this->bodypartpref = $arr;

		return $asb;
	}

	/**
	 * Returns available body preference objects.
	 *
	 *  @return array|bool       returns false if the client's body preference is not available
	 */
	public function GetBodyPreference() {
		if (!isset($this->bodypref) || !(is_array($this->bodypref) || empty($this->bodypref))) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("ContentParameters->GetBodyPreference(): bodypref is empty or not set"));

			return false;
		}

		return array_keys($this->bodypref);
	}

	/**
	 * Returns available body part preference objects.
	 *
	 *  @return array|bool       returns false if the client's body preference is not available
	 */
	public function GetBodyPartPreference() {
		if (!isset($this->bodypartpref) || !(is_array($this->bodypartpref) || empty($this->bodypartpref))) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("ContentParameters->GetBodyPartPreference(): bodypartpref is empty or not set"));

			return false;
		}

		return array_keys($this->bodypartpref);
	}
}
