<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * IExportChanges interface. It's responsible for
 * exporting (sending) data, content and hierarchy changes.
 * This interface extends the IChanges interface.
 */

interface IExportChanges extends IChanges {
	/**
	 * Sets the importer where the exporter will sent its changes to
	 * This exporter should also be ready to accept calls after this.
	 *
	 * @param object &$importer Implementation of IImportChanges
	 *
	 * @throws StatusException
	 *
	 * @return bool
	 */
	public function InitializeExporter(&$importer);

	/**
	 * Returns the amount of changes to be exported.
	 *
	 * @return int
	 */
	public function GetChangeCount();

	/**
	 * Synchronizes a change to the configured importer.
	 *
	 * @return array with status information
	 */
	public function Synchronize();
}
