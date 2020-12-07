<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * Provides the GETATTACHMENT command
 */

class GetAttachment extends RequestProcessor {

    /**
     * Handles the GetAttachment command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        $attname = Request::GetGETAttachmentName();
        if(!$attname)
            return false;

        try {
            $attachment = self::$backend->GetAttachmentData($attname);
            $stream = $attachment->data;
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleGetAttachment(): attachment stream from backend: %s", $stream));

            // need to check for a resource here, as eg. feof('Error') === false and causing infinit loop in while!
            if (!is_resource($stream))
                throw new StatusException(sprintf("HandleGetAttachment(): No stream resource returned by backend for attachment: %s", $attname), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

            header("Content-Type: application/octet-stream");
            self::$topCollector->AnnounceInformation("Starting attachment streaming", true);
            $l = fpassthru($stream);
            fclose($stream);
            if ($l === false)
                throw new FatalException("HandleGetAttachment(): fpassthru === false !!!");
            self::$topCollector->AnnounceInformation(sprintf("Streamed %d KB attachment", round($l/1024)), true);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleGetAttachment(): attachment with %d KB sent to mobile", round($l/1024)));
        }
        catch (StatusException $s) {
            // StatusException already logged so we just need to pass it upwards to send a HTTP error
            throw new HTTPReturnCodeException($s->getMessage(), HTTP_CODE_500, null, LOGLEVEL_DEBUG);
        }

        return true;
    }
}
