<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Indicates that an object was identified as broken.
 * The SyncObject may be available for further analysis.
 */

class SyncObjectBrokenException extends GSyncException {
	protected $defaultLogLevel = LOGLEVEL_WARN;
	private $syncObject;

	/**
	 * Returns the SyncObject which caused this Exception (if set).
	 *
	 * @return SyncObject
	 */
	public function GetSyncObject() {
		return isset($this->syncObject) ? $this->syncObject : false;
	}

	/**
	 * Sets the SyncObject which caused the exception so it can be later retrieved.
	 *
	 * @param SyncObject $syncobject
	 *
	 * @return bool
	 */
	public function SetSyncObject($syncobject) {
		$this->syncObject = $syncobject;

		return true;
	}
}
