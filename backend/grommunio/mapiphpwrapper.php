<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * The ICS importer is very MAPI specific and needs to be wrapped, because we
 * want all MAPI code to be separate from the rest of grommunio-sync. To do so all
 * MAPI dependency are removed in this class. All the other importers are based
 * on IChanges, not MAPI.
 */

/**
 * This is the PHP wrapper which strips MAPI information from
 * the import interface of ICS. We get all the information about messages
 * from MAPI here which are sent to the next importer, which will
 * convert the data into WBXML which is streamed to the PDA
 */

class PHPWrapper {
    private $importer;
    private $mapiprovider;
    private $store;
    private $contentparameters;
    private $folderid;
    private $prefix;


    /**
     * Constructor of the PHPWrapper
     *
     * @param ressource         $session
     * @param ressource         $store
     * @param IImportChanges    $importer       incoming changes from ICS are forwarded here.
     * @param string            $folderid       the folder this wrapper was configured for.
     *
     * @access public
     * @return
     */
    public function __construct($session, $store, $importer, $folderid) {
        $this->importer = &$importer;
        $this->store = $store;
        $this->mapiprovider = new MAPIProvider($session, $this->store);
        $this->folderid = $folderid;
        $this->prefix = '';

        if ($folderid) {
            $folderidHex = bin2hex($folderid);
            $folderid = ZPush::GetDeviceManager()->GetFolderIdForBackendId($folderidHex);
            if ($folderid != $folderidHex) {
                $this->prefix = $folderid . ':';
            }
        }
    }

    /**
     * Configures additional parameters used for content synchronization
     *
     * @param ContentParameters         $contentparameters
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function ConfigContentParameters($contentparameters) {
        $this->contentparameters = $contentparameters;
    }

    /**
     * Implement MAPI interface
     */
    public function Config($stream, $flags = 0) {}
    public function GetLastError($hresult, $ulflags, &$lpmapierror) {}
    public function UpdateState($stream) { }

    /**
     * Imports a single message
     *
     * @param array         $props
     * @param long          $flags
     * @param object        $retmapimessage
     *
     * @access public
     * @return long
     */
    public function ImportMessageChange($props, $flags, $retmapimessage) {
        $sourcekey = $props[PR_SOURCE_KEY];
        $parentsourcekey = $props[PR_PARENT_SOURCE_KEY];
        $entryid = mapi_msgstore_entryidfromsourcekey($this->store, $parentsourcekey, $sourcekey);

        if(!$entryid)
            return SYNC_E_IGNORE;

        $mapimessage = mapi_msgstore_openentry($this->store, $entryid);
        try {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("PHPWrapper->ImportMessageChange(): Getting message from MAPIProvider, sourcekey: '%s', parentsourcekey: '%s', entryid: '%s'", bin2hex($sourcekey), bin2hex($parentsourcekey), bin2hex($entryid)));

            $message = $this->mapiprovider->GetMessage($mapimessage, $this->contentparameters);

            // strip or do not send private messages from shared folders to the device
            if (MAPIUtils::IsMessageSharedAndPrivate($this->folderid, $mapimessage)) {
                if ($message->SupportsPrivateStripping()) {
                    ZLog::Write(LOGLEVEL_DEBUG, "PHPWrapper->ImportMessageChange(): stripping data of private message from a shared folder");
                    $message->StripData(Streamer::STRIP_PRIVATE_DATA);
                }
                else {
                    ZLog::Write(LOGLEVEL_DEBUG, "PHPWrapper->ImportMessageChange(): ignoring private message from a shared folder");
                    return SYNC_E_IGNORE;
                }
            }
        }
        catch (SyncObjectBrokenException $mbe) {
            $brokenSO = $mbe->GetSyncObject();
            if (!$brokenSO) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("PHPWrapper->ImportMessageChange(): Catched SyncObjectBrokenException but broken SyncObject available"));
            }
            else {
                if (!isset($brokenSO->id)) {
                    $brokenSO->id = "Unknown ID";
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("PHPWrapper->ImportMessageChange(): Catched SyncObjectBrokenException but no ID of object set"));
                }
                ZPush::GetDeviceManager()->AnnounceIgnoredMessage(false, $brokenSO->id, $brokenSO);
            }
            // tell MAPI to ignore the message
            return SYNC_E_IGNORE;
        }


        // substitute the MAPI SYNC_NEW_MESSAGE flag by a grommunio-sync propietary flag
        if ($flags == SYNC_NEW_MESSAGE) $message->flags = SYNC_NEWMESSAGE;
        else $message->flags = $flags;

        $this->importer->ImportMessageChange($this->prefix.bin2hex($sourcekey), $message);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("PHPWrapper->ImportMessageChange(): change for: '%s'", $this->prefix.bin2hex($sourcekey)));

        // Tell MAPI it doesn't need to do anything itself, as we've done all the work already.
        return SYNC_E_IGNORE;
    }

    /**
     * Imports a list of messages to be deleted
     *
     * @param long          $flags
     * @param array         $sourcekeys     array with sourcekeys
     *
     * @access public
     * @return
     */
    public function ImportMessageDeletion($flags, $sourcekeys) {
        $amount = count($sourcekeys);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("PHPWrapper->ImportMessageDeletion(): Received %d remove requests from ICS", $amount));

        foreach($sourcekeys as $sourcekey) {
            // TODO if we would know that ICS is removing the message because it's outside the sync interval, we could send a $asSoftDelete = true to the importer. Could they pass that via $flags?
            $this->importer->ImportMessageDeletion($this->prefix.bin2hex($sourcekey));
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("PHPWrapper->ImportMessageDeletion(): delete for :'%s'", $this->prefix.bin2hex($sourcekey)));
        }
    }

    /**
     * Imports a list of messages to be deleted
     *
     * @param mixed         $readstates     sourcekeys and message flags
     *
     * @access public
     * @return
     */
    public function ImportPerUserReadStateChange($readstates) {
        foreach($readstates as $readstate) {
            $this->importer->ImportMessageReadFlag($this->prefix.bin2hex($readstate["sourcekey"]), $readstate["flags"] & MSGFLAG_READ);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("PHPWrapper->ImportPerUserReadStateChange(): read for :'%s'", $this->prefix.bin2hex($readstate["sourcekey"])));
        }
    }

    /**
     * Imports a message move
     * this is never called by ICS
     *
     * @access public
     * @return
     */
    public function ImportMessageMove($sourcekeysrcfolder, $sourcekeysrcmessage, $message, $sourcekeydestmessage, $changenumdestmessage) {
        // Never called
    }

    /**
     * Imports a single folder change
     *
     * @param array         $props     properties of the changed folder
     *
     * @access public
     * @return
     */
    function ImportFolderChange($props) {
        $folder = $this->mapiprovider->GetFolder($props);

        // do not import folder if there is something "wrong" with it
        if ($folder === false)
            return 0;

        $this->importer->ImportFolderChange($folder);
        return 0;
    }

    /**
     * Imports a list of folders which are to be deleted
     *
     * @param long          $flags
     * @param mixed         $sourcekeys array with sourcekeys
     *
     * @access public
     * @return
     */
    function ImportFolderDeletion($flags, $sourcekeys) {
        foreach ($sourcekeys as $sourcekey) {
            $this->importer->ImportFolderDeletion(SyncFolder::GetObject(ZPush::GetDeviceManager()->GetFolderIdForBackendId(bin2hex($sourcekey))));
        }
        return 0;
    }
}
