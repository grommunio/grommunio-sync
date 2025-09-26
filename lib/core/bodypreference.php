<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Holds body preference data
 */

class BodyPreference extends StateObject {
	protected $unsetdata = [
		'truncationsize' => false,
		'allornone' => false,
		'preview' => false,
	];

	/**
	 * expected magic getters and setters.
	 *
	 * GetTruncationSize() + SetTruncationSize()
	 * GetAllOrNone() + SetAllOrNone()
	 * GetPreview() + SetPreview()
	 */

	/**
	 * Indicates if this object has values.
	 *
	 * @return bool
	 */
	public function HasValues() {
		return count($this->data) > 0;
	}
}
