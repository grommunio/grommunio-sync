<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Interface called from the Device and StateManager to save states for a
 * user/device/folder. Backends provide their own IStateMachine implementation of
 * this interface and return an IStateMachine instance with
 * IBackend->GetStateMachine(). Old sync states are not deleted until a new
 * sync state is requested. At that moment, the PIM is apparently requesting an
 * update since sync key X, so any sync states before X are already on the PIM,
 * and can therefore be removed. This algorithm should be automatically
 * enforced by the IStateMachine implementation.
 */

interface IStateMachine {
	public const DEFTYPE = "";
	public const DEVICEDATA = "devicedata";
	public const FOLDERDATA = "fd";
	public const FAILSAFE = "fs";
	public const HIERARCHY = "hc";
	public const BACKENDSTORAGE = "bs";

	public const STATEVERSION_01 = "1";
	public const STATEVERSION_02 = "2";

	/**
	 * Constructor.
	 *
	 * @param mixed $devid
	 * @param mixed $type
	 * @param mixed $key
	 * @param mixed $counter
	 *
	 * @throws FatalMisconfigurationException
	 */

	/**
	 * Gets a hash value indicating the latest dataset of the named
	 * state with a specified key and counter.
	 * If the state is changed between two calls of this method
	 * the returned hash should be different.
	 *
	 * @param string $devid   the device id
	 * @param string $type    the state type
	 * @param string $key     (opt)
	 * @param string $counter (opt)
	 *
	 * @throws StateNotFoundException, StateInvalidException, UnavailableException
	 *
	 * @return string
	 */
	public function GetStateHash($devid, $type, $key = false, $counter = false);

	/**
	 * Gets a state for a specified key and counter.
	 * This method should call IStateMachine->CleanStates()
	 * to remove older states (same key, previous counters).
	 *
	 * @param string $devid       the device id
	 * @param string $type        the state type
	 * @param string $key         (opt)
	 * @param string $counter     (opt)
	 * @param string $cleanstates (opt)
	 *
	 * @throws StateNotFoundException, StateInvalidException, UnavailableException
	 *
	 * @return mixed
	 */
	public function GetState($devid, $type, $key = false, $counter = false, $cleanstates = true);

	/**
	 * Writes ta state to for a key and counter.
	 *
	 * @param mixed  $state
	 * @param string $devid   the device id
	 * @param string $type    the state type
	 * @param string $key     (opt)
	 * @param int    $counter (opt)
	 *
	 * @throws StateInvalidException, UnavailableException
	 *
	 * @return bool
	 */
	public function SetState($state, $devid, $type, $key = false, $counter = false);

	/**
	 * Cleans up all older states.
	 * If called with a $counter, all states previous state counter can be removed.
	 * If additionally the $thisCounterOnly flag is true, only that specific counter will be removed.
	 * If called without $counter, all keys (independently from the counter) can be removed.
	 *
	 * @param string $devid           the device id
	 * @param string $type            the state type
	 * @param string $key
	 * @param string $counter         (opt)
	 * @param string $thisCounterOnly (opt) if provided, the exact counter only will be removed
	 *
	 * @throws StateInvalidException
	 *
	 * @return
	 */
	public function CleanStates($devid, $type, $key, $counter = false, $thisCounterOnly = false);

	/**
	 * Links a user to a device.
	 *
	 * @param string $username
	 * @param string $devid
	 *
	 * @return bool indicating if the user was added or not (existed already)
	 */
	public function LinkUserDevice($username, $devid);

	/**
	 * Unlinks a device from a user.
	 *
	 * @param string $username
	 * @param string $devid
	 *
	 * @return bool
	 */
	public function UnLinkUserDevice($username, $devid);

	/**
	 * Returns the current version of the state files.
	 *
	 * @return int
	 */
	public function GetStateVersion();

	/**
	 * Sets the current version of the state files.
	 *
	 * @param int $version the new supported version
	 *
	 * @return bool
	 */
	public function SetStateVersion($version);
}
