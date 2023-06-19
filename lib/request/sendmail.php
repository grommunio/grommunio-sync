<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2013,2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Provides the SENDMAIL, SMARTREPLY and SMARTFORWARD command
 */

class SendMail extends RequestProcessor {
	/**
	 * Handles the SendMail, SmartReply and SmartForward command.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public function Handle($commandCode) {
		$sm = new SyncSendMail();

		$reply = $forward = $parent = $sendmail = $smartreply = $smartforward = false;
		if (Request::GetGETCollectionId()) {
			$parent = Request::GetGETCollectionId();
		}
		if ($commandCode == GSync::COMMAND_SMARTFORWARD) {
			$forward = Request::GetGETItemId();
		}
		elseif ($commandCode == GSync::COMMAND_SMARTREPLY) {
			$reply = Request::GetGETItemId();
		}

		if (self::$decoder->IsWBXML()) {
			$el = self::$decoder->getElement();

			if ($el[EN_TYPE] != EN_TYPE_STARTTAG) {
				return false;
			}

			if ($el[EN_TAG] == SYNC_COMPOSEMAIL_SENDMAIL) {
				$sendmail = true;
			}
			elseif ($el[EN_TAG] == SYNC_COMPOSEMAIL_SMARTREPLY) {
				$smartreply = true;
			}
			elseif ($el[EN_TAG] == SYNC_COMPOSEMAIL_SMARTFORWARD) {
				$smartforward = true;
			}

			if (!$sendmail && !$smartreply && !$smartforward) {
				return false;
			}

			$sm->Decode(self::$decoder);
		}
		else {
			$sm->mime = self::$decoder->GetPlainInputStream();
			// no wbxml output is provided, only a http OK
			$sm->saveinsent = Request::GetGETSaveInSent();
		}

		// Check if it is a reply or forward. Two cases are possible:
		// 1. Either $smartreply or $smartforward are set after reading WBXML
		// 2. Either $reply or $forward are set after getting the request parameters
		if ($reply || $smartreply || $forward || $smartforward) {
			// If the mobile sends an email in WBXML data the variables below
			// should be set. If it is a RFC822 message, get the reply/forward message id
			// from the request as they are always available there
			if (!isset($sm->source)) {
				$sm->source = new SyncSendMailSource();
			}
			if (!isset($sm->source->itemid)) {
				$sm->source->itemid = Request::GetGETItemId();
			}
			if (!isset($sm->source->folderid)) {
				$sm->source->folderid = Request::GetGETCollectionId();
			}

			// split long-id if it's set - it overwrites folderid and itemid
			if (isset($sm->source->longid) && $sm->source->longid) {
				list($sm->source->folderid, $sm->source->itemid) = Utils::SplitMessageId($sm->source->longid);
			}

			// Rewrite the AS folderid into a backend folderid
			if (isset($sm->source->folderid)) {
				$sm->source->folderid = self::$deviceManager->GetBackendIdForFolderId($sm->source->folderid);
			}
			if (isset($sm->source->itemid)) {
				list(, $sk) = Utils::SplitMessageId($sm->source->itemid);
				$sm->source->itemid = $sk;
			}
			// replyflag and forward flags are actually only for the correct icon.
			// Even if they are a part of SyncSendMail object, they won't be streamed.
			if ($smartreply || $reply) {
				$sm->replyflag = true;
			}
			else {
				$sm->forwardflag = true;
			}

			if (!isset($sm->source->folderid) || !$sm->source->folderid) {
				SLog::Write(LOGLEVEL_ERROR, sprintf("SendMail(): No parent folder id while replying or forwarding message:'%s'", ($reply) ? $reply : $forward));
			}
		}

		self::$topCollector->AnnounceInformation(sprintf("SendMail(): Sending email with %d bytes", strlen($sm->mime)), true);

		$statusMessage = '';

		try {
			$status = self::$backend->SendMail($sm);
		}
		catch (StatusException $se) {
			$status = $se->getCode();
			$statusMessage = $se->getMessage();
		}

		if ($status != SYNC_COMMONSTATUS_SUCCESS) {
			if (self::$decoder->IsWBXML()) {
				// TODO check no WBXML on SmartReply and SmartForward
				self::$encoder->StartWBXML();
				self::$encoder->startTag(SYNC_COMPOSEMAIL_SENDMAIL);
				self::$encoder->startTag(SYNC_COMPOSEMAIL_STATUS);
				self::$encoder->content($status); // TODO return the correct status
				self::$encoder->endTag();
				self::$encoder->endTag();
			}
			else {
				throw new HTTPReturnCodeException($statusMessage, HTTP_CODE_500, null, LOGLEVEL_WARN);
			}
		}

		return $status;
	}
}
