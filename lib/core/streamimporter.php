<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Sends changes directly to the wbxml stream
 */

class ImportChangesStream implements IImportChanges {
	private $encoder;
	private $objclass;
	private $seenObjects;
	private $importedMsgs;
	private $checkForIgnoredMessages;
	private $classAsString;

	/**
	 * Constructor of the StreamImporter.
	 *
	 * @param WBXMLEncoder $encoder Objects are streamed to this encoder
	 * @param SyncObject   $class   SyncObject class (only these are accepted when streaming content messages)
	 */
	public function __construct(&$encoder, $class) {
		$this->encoder = &$encoder;
		$this->objclass = $class;
		$this->classAsString = (is_object($class)) ? get_class($class) : '';
		$this->seenObjects = [];
		$this->importedMsgs = 0;
		$this->checkForIgnoredMessages = true;
	}

	/**
	 * Implement interface - never used.
	 *
	 * @param mixed $state
	 * @param mixed $flags
	 */
	public function Config($state, $flags = 0) {
		return true;
	}

	public function ConfigContentParameters($contentparameters) {
		return true;
	}

	public function GetState() {
		return false;
	}

	public function LoadConflicts($contentparameters, $state) {
		return true;
	}

	/**
	 * Imports a single message.
	 *
	 * @param string     $id
	 * @param SyncObject $message
	 *
	 * @return bool
	 */
	public function ImportMessageChange($id, $message) {
		// ignore other SyncObjects
		if (!($message instanceof $this->classAsString)) {
			return false;
		}

		// prevent sending the same object twice in one request
		if (in_array($id, $this->seenObjects)) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("Object '%s' discarded! Object already sent in this request.", $id));

			return true;
		}

		++$this->importedMsgs;
		$this->seenObjects[] = $id;

		// checks if the next message may cause a loop or is broken
		if (GSync::GetDeviceManager()->DoNotStreamMessage($id, $message)) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesStream->ImportMessageChange('%s'): message ignored and requested to be removed from mobile", $id));

			// this is an internal operation & should not trigger an update in the device manager
			$this->checkForIgnoredMessages = false;
			$stat = $this->ImportMessageDeletion($id);
			$this->checkForIgnoredMessages = true;

			return $stat;
		}

		if ($message->flags === false || $message->flags === SYNC_NEWMESSAGE) {
			$this->encoder->startTag(SYNC_ADD);
		}
		else {
			// on update of an SyncEmail we only export the flags and categories
			if ($message instanceof SyncMail && ((isset($message->flag) && $message->flag instanceof SyncMailFlags) || isset($message->categories))) {
				$newmessage = new SyncMail();
				$newmessage->read = $message->read;
				if (isset($message->flag)) {
					$newmessage->flag = $message->flag;
				}
				if (isset($message->lastverbexectime)) {
					$newmessage->lastverbexectime = $message->lastverbexectime;
				}
				if (isset($message->lastverbexecuted)) {
					$newmessage->lastverbexecuted = $message->lastverbexecuted;
				}
				if (isset($message->categories)) {
					$newmessage->categories = $message->categories;
				}
				$message = $newmessage;
				unset($newmessage);
				SLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesStream->ImportMessageChange('%s'): SyncMail message updated. Message content is striped, only flags/categories are streamed.", $id));
			}

			$this->encoder->startTag(SYNC_MODIFY);
		}

		// TAG: SYNC_ADD / SYNC_MODIFY
		$this->encoder->startTag(SYNC_SERVERENTRYID);
		$this->encoder->content($id);
		$this->encoder->endTag();
		$this->encoder->startTag(SYNC_DATA);
		$message->Encode($this->encoder);
		$this->encoder->endTag();
		$this->encoder->endTag();

		return true;
	}

	/**
	 * Imports a deletion.
	 *
	 * @param string $id
	 * @param bool   $asSoftDelete (opt) if true, the deletion is exported as "SoftDelete", else as "Remove" - default: false
	 *
	 * @return bool
	 */
	public function ImportMessageDeletion($id, $asSoftDelete = false) {
		if ($this->checkForIgnoredMessages) {
			GSync::GetDeviceManager()->RemoveBrokenMessage($id);
		}

		++$this->importedMsgs;
		if ($asSoftDelete) {
			$this->encoder->startTag(SYNC_SOFTDELETE);
		}
		else {
			$this->encoder->startTag(SYNC_REMOVE);
		}
		$this->encoder->startTag(SYNC_SERVERENTRYID);
		$this->encoder->content($id);
		$this->encoder->endTag();
		$this->encoder->endTag();

		return true;
	}

	/**
	 * Imports a change in 'read' flag
	 * Can only be applied to SyncMail (Email) requests.
	 *
	 * @param string $id
	 * @param int    $flags      - read/unread
	 * @param array  $categories
	 *
	 * @return bool
	 */
	public function ImportMessageReadFlag($id, $flags, $categories = []) {
		if (!($this->objclass instanceof SyncMail)) {
			return false;
		}

		++$this->importedMsgs;

		$this->encoder->startTag(SYNC_MODIFY);
		$this->encoder->startTag(SYNC_SERVERENTRYID);
		$this->encoder->content($id);
		$this->encoder->endTag();
		$this->encoder->startTag(SYNC_DATA);
		$this->encoder->startTag(SYNC_POOMMAIL_READ);
		$this->encoder->content($flags);
		$this->encoder->endTag();
		if (!empty($categories) && is_array($categories)) {
			$this->encoder->startTag(SYNC_POOMMAIL_CATEGORIES);
			foreach ($categories as $category) {
				$this->encoder->startTag(SYNC_POOMMAIL_CATEGORY);
				$this->encoder->content($category);
				$this->encoder->endTag();
			}
			$this->encoder->endTag();
		}
		$this->encoder->endTag();
		$this->encoder->endTag();

		return true;
	}

	/**
	 * ImportMessageMove is not implemented, as this operation can not be streamed to a WBXMLEncoder.
	 *
	 * @param string $id
	 * @param int    $flags     read/unread
	 * @param mixed  $newfolder
	 *
	 * @return bool
	 */
	public function ImportMessageMove($id, $newfolder) {
		return true;
	}

	/**
	 * Imports a change on a folder.
	 *
	 * @param object $folder SyncFolder
	 *
	 * @return bool|SyncObject status/object with the ath least the serverid of the folder set
	 */
	public function ImportFolderChange($folder) {
		// checks if the next message may cause a loop or is broken
		if (GSync::GetDeviceManager(false) && GSync::GetDeviceManager()->DoNotStreamMessage($folder->serverid, $folder)) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesStream->ImportFolderChange('%s'): folder ignored as requested by DeviceManager.", $folder->serverid));

			return true;
		}

		// send a modify flag if the folder is already known on the device
		if (isset($folder->flags) && $folder->flags === SYNC_NEWMESSAGE) {
			$this->encoder->startTag(SYNC_FOLDERHIERARCHY_ADD);
		}
		else {
			$this->encoder->startTag(SYNC_FOLDERHIERARCHY_UPDATE);
		}

		$folder->Encode($this->encoder);
		$this->encoder->endTag();

		return true;
	}

	/**
	 * Imports a folder deletion.
	 *
	 * @param SyncFolder $folder at least "serverid" needs to be set
	 *
	 * @return bool
	 */
	public function ImportFolderDeletion($folder) {
		$this->encoder->startTag(SYNC_FOLDERHIERARCHY_REMOVE);
		$this->encoder->startTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID);
		$this->encoder->content($folder->serverid);
		$this->encoder->endTag();
		$this->encoder->endTag();

		return true;
	}

	/**
	 * Returns the number of messages which were changed, deleted and had changed read status.
	 *
	 * @return int
	 */
	public function GetImportedMessages() {
		return $this->importedMsgs;
	}
}
