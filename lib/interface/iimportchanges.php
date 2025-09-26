<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * IImportChanges interface. It's responsible for
 * importing (receiving) data, content and hierarchy changes.
 * This interface extends the IChanges interface.
 */

interface IImportChanges extends IChanges {
	/*----------------------------------------------------------------------------------------------------------
	 * Methods for to import contents
	 */

	/**
	 * Loads objects which are expected to be exported with the state
	 * Before importing/saving the actual message from the mobile, a conflict detection should be done.
	 *
	 * @param ContentParameters $contentparameters
	 * @param string            $state
	 *
	 * @return bool
	 *
	 * @throws StatusException
	 */
	public function LoadConflicts($contentparameters, $state);

	/**
	 * Imports a single message.
	 *
	 * @param string     $id
	 * @param SyncObject $message
	 *
	 * @return bool|string failure / SyncObject
	 *
	 * @throws StatusException
	 */
	public function ImportMessageChange($id, $message);

	/**
	 * Imports a deletion. This may conflict if the local object has been modified.
	 *
	 * @param string $id
	 * @param bool   $asSoftDelete (opt) if true, the deletion is exported as "SoftDelete", else as "Remove" - default: false
	 *
	 * @return bool
	 */
	public function ImportMessageDeletion($id, $asSoftDelete = false);

	/**
	 * Imports a change in 'read' flag
	 * This can never conflict.
	 *
	 * @param string $id
	 * @param int    $flags
	 * @param array  $categories
	 *
	 * @return bool
	 *
	 * @throws StatusException
	 */
	public function ImportMessageReadFlag($id, $flags, $categories = []);

	/**
	 * Imports a move of a message. This occurs when a user moves an item to another folder.
	 *
	 * @param string $id
	 * @param string $newfolder destination folder
	 *
	 * @return bool
	 *
	 * @throws StatusException
	 */
	public function ImportMessageMove($id, $newfolder);

	/*----------------------------------------------------------------------------------------------------------
	 * Methods to import hierarchy
	 */

	/**
	 * Imports a change on a folder.
	 *
	 * @param object $folder SyncFolder
	 *
	 * @return bool|SyncObject status/object with the ath least the serverid of the folder set
	 *
	 * @throws StatusException
	 */
	public function ImportFolderChange($folder);

	/**
	 * Imports a folder deletion.
	 *
	 * @param SyncFolder $folder at least "serverid" needs to be set
	 *
	 * @return bool|int success/SYNC_FOLDERHIERARCHY_STATUS
	 *
	 * @throws StatusException
	 */
	public function ImportFolderDeletion($folder);
}
