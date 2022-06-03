<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Generic IChanges interface. This interface can
 * not be implemented alone.
 * IImportChanges and IExportChanges interfaces
 * inherit from this interface
 */

interface IChanges {
	/**
	 * Constructor.
	 *
	 * @param mixed $state
	 * @param mixed $flags
	 *
	 * @throws StatusException
	 */

	/**
	 * Initializes the state and flags.
	 *
	 * @param string $state
	 * @param int    $flags
	 *
	 * @throws StatusException
	 *
	 * @return bool status flag
	 */
	public function Config($state, $flags = 0);

	/**
	 * Configures additional parameters used for content synchronization.
	 *
	 * @param ContentParameters $contentparameters
	 *
	 * @throws StatusException
	 *
	 * @return bool
	 */
	public function ConfigContentParameters($contentparameters);

	/**
	 * Reads and returns the current state.
	 *
	 * @return string
	 */
	public function GetState();
}
