<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * This is a backend for grommunio. It is an implementation of IBackend and also
 * implements ISearchProvider to search in the grommunio system. The backend
 * implements IStateMachine as well to save the devices' information in the
 * user's store and extends InterProcessData to access Redis.
 */

// include PHP-MAPI classes
include_once('backend/grommunio/mapi/mapi.util.php');
include_once('backend/grommunio/mapi/mapidefs.php');
include_once('backend/grommunio/mapi/mapitags.php');
include_once('backend/grommunio/mapi/mapicode.php');
include_once('backend/grommunio/mapi/mapiguid.php');


//setlocale to UTF-8 in order to support properties containing Unicode characters
setlocale(LC_CTYPE, "en_US.UTF-8");

class BackendGrommunio extends InterProcessData implements IBackend, ISearchProvider, IStateMachine  {
    private $mainUser;
    private $session;
    private $defaultstore;
    private $store;
    private $storeName;
    private $storeCache;
    private $notifications;
    private $changesSink;
    private $changesSinkFolders;
    private $changesSinkStores;
    private $wastebasket;
    private $addressbook;
    private $folderStatCache;
    private $impersonateUser;

    // KC config parameter for PR_EC_ENABLED_FEATURES / PR_EC_DISABLED_FEATURES
    const MOBILE_ENABLED = 'mobile';

    const MAXAMBIGUOUSRECIPIENTS = 9999;
    const FREEBUSYENUMBLOCKS = 50;
    const MAXFREEBUSYSLOTS = 32767; // max length of 32k for the MergedFreeBusy element is allowed
    const HALFHOURSECONDS = 1800;

    /**
     * Constructor of the grommunio Backend
     *
     * @access public
     */
    public function __construct() {
        $this->session = false;
        $this->store = false;
        $this->storeName = false;
        $this->storeCache = array();
        $this->notifications = false;
        $this->changesSink = false;
        $this->changesSinkFolders = array();
        $this->changesSinkStores = array();
        $this->changesSinkHierarchyHash = false;
        $this->wastebasket = false;
        $this->session = false;
        $this->folderStatCache = array();
        $this->impersonateUser = false;
        $this->stateFolder = null;

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio using PHP-MAPI version: %s - PHP version: %s", phpversion("mapi"), phpversion()));

        # Interprocessdata
        $this->allocate = 0;
        $this->type = "grommunio-sync:userdevices";
        $this->userDeviceData = "grommunio-sync:statefoldercache";
        parent::__construct();
    }

    /**
     * Indicates which StateMachine should be used
     *
     * @access public
     * @return boolean      BackendGrommunio uses own state machine
     */
    public function GetStateMachine() {
        return $this;
    }

    /**
     * Returns the BackendGrommunio as it implements the ISearchProvider interface
     * This could be overwritten by the global configuration
     *
     * @access public
     * @return object       Implementation of ISearchProvider
     */
    public function GetSearchProvider() {
        return $this;
    }

    /**
     * Indicates which AS version is supported by the backend.
     *
     * @access public
     * @return string       AS version constant
     */
    public function GetSupportedASVersion() {
        return ZPush::ASV_141;
    }

    /**
     * Authenticates the user with the configured grommunio server
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     * @throws AuthenticationRequiredException
     */
    public function Logon($user, $domain, $pass) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->Logon(): Trying to authenticate user '%s'..", $user));

        $this->mainUser = strtolower($user);
        // TODO the impersonated user should be passed directly to IBackend->Logon() - ZP-1351
        if (Request::GetImpersonatedUser()) {
            $this->impersonateUser = strtolower(Request::GetImpersonatedUser());
        }

        // check if we are impersonating someone
        // $defaultUser will be used for $this->defaultStore
        if ($this->impersonateUser !== false) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->Logon(): Impersonation active - authenticating: '%s' - impersonating '%s'", $this->mainUser, $this->impersonateUser));
            $defaultUser = $this->impersonateUser;
        }
        else {
            $defaultUser = $this->mainUser;
        }

        $deviceId = Request::GetDeviceID();

        try {
            // check if notifications are available in php-mapi
            if(function_exists('mapi_feature') && mapi_feature('LOGONFLAGS')) {
                // send grommunio-sync version and user agent to ZCP - ZP-589
                if (Utils::CheckMapiExtVersion('7.2.0')) {
                    $zpush_version = 'Grommunio-Sync_' . @constant('GROMMUNIOSYNC_VERSION');
                    $user_agent = ($deviceId) ? ZPush::GetDeviceManager()->GetUserAgent() : "unknown";
                    $this->session = @mapi_logon_zarafa($this->mainUser, $pass, MAPI_SERVER, null, null, 0, $zpush_version, $user_agent);
                }
                else {
                    $this->session = @mapi_logon_zarafa($this->mainUser, $pass, MAPI_SERVER, null, null, 0);
                }
                $this->notifications = true;
            }
            // old fashioned session
            else {
                $this->session = @mapi_logon_zarafa($this->mainUser, $pass, MAPI_SERVER);
                $this->notifications = false;
            }

            if (mapi_last_hresult()) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendGrommunio->Logon(): login failed with error code: 0x%X", mapi_last_hresult()));
                if (mapi_last_hresult() == MAPI_E_NETWORK_ERROR)
                    throw new ServiceUnavailableException("Error connecting to KC (login)");
            }
        }
        catch (MAPIException $ex) {
            throw new AuthenticationRequiredException($ex->getDisplayMessage());
        }

        if(!$this->session) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->Logon(): logon failed for user '%s'", $this->mainUser));
            $this->defaultstore = false;
            return false;
        }

        // Get/open default store
        $this->defaultstore = $this->openMessageStore($this->mainUser);

        // To impersonate, we overwrite the defaultstore. We still need to open it before we can do that.
        if ($this->impersonateUser) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->Logon(): Impersonating user '%s'", $defaultUser));
            $this->defaultstore = $this->openMessageStore($defaultUser);
        }

        if (mapi_last_hresult() == MAPI_E_FAILONEPROVIDER)
            throw new ServiceUnavailableException("Error connecting to KC (open store)");

        if($this->defaultstore === false)
            throw new AuthenticationRequiredException(sprintf("BackendGrommunio->Logon(): User '%s' has no default store", $defaultUser));

        $this->store = $this->defaultstore;
        $this->storeName = $defaultUser;

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->Logon(): User '%s' is authenticated%s", $this->mainUser, ($this->impersonateUser ? " impersonating '".$this->impersonateUser."'" : '')));

        $this->isZPushEnabled();

        // check if this is a Zarafa 7 store with unicode support
        MAPIUtils::IsUnicodeStore($this->store);

        // open the state folder
        $this->getStateFolder($deviceId);
        return true;
    }

    /**
     * Setup the backend to work on a specific store or checks ACLs there.
     * If only the $store is submitted, all Import/Export/Fetch/Etc operations should be
     * performed on this store (switch operations store).
     * If the ACL check is enabled, this operation should just indicate the ACL status on
     * the submitted store, without changing the store for operations.
     * For the ACL status, the currently logged on user MUST have access rights on
     *  - the entire store - admin access if no folderid is sent, or
     *  - on a specific folderid in the store (secretary/full access rights)
     *
     * The ACLcheck MUST fail if a folder of the authenticated user is checked!
     *
     * @param string        $store              target store, could contain a "domain\user" value
     * @param boolean       $checkACLonly       if set to true, Setup() should just check ACLs
     * @param string        $folderid           if set, only ACLs on this folderid are relevant
     *
     * @access public
     * @return boolean
     */
    public function Setup($store, $checkACLonly = false, $folderid = false) {
        list($user, $domain) = Utils::SplitDomainUser($store);

        if (!isset($this->mainUser))
            return false;

        $mainUser = $this->mainUser;
        // when impersonating we need to check against the impersonated user
        if ($this->impersonateUser) {
            $mainUser = $this->impersonateUser;
        }

        if ($user === false)
            $user = $mainUser;

        // This is a special case. A user will get his entire folder structure by the foldersync by default.
        // The ACL check is executed when an additional folder is going to be sent to the mobile.
        // Configured that way the user could receive the same folderid twice, with two different names.
        if ($mainUser == $user && $checkACLonly && $folderid && !$this->impersonateUser) {
            ZLog::Write(LOGLEVEL_DEBUG, "BackendGrommunio->Setup(): Checking ACLs for folder of the users defaultstore. Fail is forced to avoid folder duplications on mobile.");
            return false;
        }

        // get the users store
        $userstore = $this->openMessageStore($user);

        // only proceed if a store was found, else return false
        if ($userstore) {
            // only check permissions
            if ($checkACLonly == true) {
                // check for admin rights
                if (!$folderid) {
                    if ($user != $this->mainUser) {
                        if ($this->impersonateUser) {
                            $storeProps = mapi_getprops($userstore, array(PR_IPM_SUBTREE_ENTRYID));
                            $rights = $this->HasSecretaryACLs($userstore, '', $storeProps[PR_IPM_SUBTREE_ENTRYID]);
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->Setup(): Checking for secretary ACLs on root folder of impersonated store '%s': '%s'", $user, Utils::PrintAsString($rights)));
                        }
                        else {
                            $zarafauserinfo = @nsp_getuserinfo($this->mainUser);
                            $rights = (isset($zarafauserinfo['admin']) && $zarafauserinfo['admin'])?true:false;
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->Setup(): Checking for admin ACLs on store '%s': '%s'", $user, Utils::PrintAsString($rights)));
                        }
                    }
                    // the user has always full access to his own store
                    else {
                        $rights = true;
                        ZLog::Write(LOGLEVEL_DEBUG, "BackendGrommunio->Setup(): the user has always full access to his own store");
                    }


                    return $rights;
                }
                // check permissions on this folder
                else {
                    $rights = $this->HasSecretaryACLs($userstore, $folderid);
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->Setup(): Checking for secretary ACLs on '%s' of store '%s': '%s'", $folderid, $user, Utils::PrintAsString($rights)));
                    return $rights;
                }
            }

            // switch operations store
            // this should also be done if called with user = mainuser or user = false
            // which means to switch back to the default store
            else {
                // switch active store
                $this->store = $userstore;
                $this->storeName = $user;
                return true;
            }
        }
        return false;
    }

    /**
     * Logs off
     * Free/Busy information is updated for modified calendars
     * This is done after the synchronization process is completed
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        return true;
    }

    /**
     * Returns an array of SyncFolder types with the entire folder hierarchy
     * on the server (the array itself is flat, but refers to parents via the 'parent' property
     *
     * provides AS 1.0 compatibility
     *
     * @access public
     * @return array SYNC_FOLDER
     */
    public function GetHierarchy() {
        $folders = array();
        $mapiprovider = new MAPIProvider($this->session, $this->store);
        $storeProps = $mapiprovider->GetStoreProps();

        // for SYSTEM user open the public folders
        if (strtoupper($this->storeName) == "SYSTEM") {
            $rootfolder = mapi_msgstore_openentry($this->store, $storeProps[PR_IPM_PUBLIC_FOLDERS_ENTRYID]);
        }
        else {
            $rootfolder = mapi_msgstore_openentry($this->store);
        }

        $rootfolderprops = mapi_getprops($rootfolder, array(PR_SOURCE_KEY));

        $hierarchy =  mapi_folder_gethierarchytable($rootfolder, CONVENIENT_DEPTH);
        $rows = mapi_table_queryallrows($hierarchy, array(PR_DISPLAY_NAME, PR_PARENT_ENTRYID, PR_ENTRYID, PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY, PR_CONTAINER_CLASS, PR_ATTR_HIDDEN, PR_EXTENDED_FOLDER_FLAGS, PR_FOLDER_TYPE));
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetHierarchy(): fetched %d folders from MAPI", count($rows)));

        foreach ($rows as $row) {
            // do not display hidden and search folders
            if ((isset($row[PR_ATTR_HIDDEN]) && $row[PR_ATTR_HIDDEN]) ||
                (isset($row[PR_FOLDER_TYPE]) && $row[PR_FOLDER_TYPE] == FOLDER_SEARCH) ||
                // for SYSTEM user $row[PR_PARENT_SOURCE_KEY] == $rootfolderprops[PR_SOURCE_KEY] is true, but we need those folders
                (isset($row[PR_PARENT_SOURCE_KEY]) && $row[PR_PARENT_SOURCE_KEY] == $rootfolderprops[PR_SOURCE_KEY] && strtoupper($this->storeName) != "SYSTEM")) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetHierarchy(): ignoring folder '%s' as it's a hidden/search/root folder", (isset($row[PR_DISPLAY_NAME]) ? $row[PR_DISPLAY_NAME] : "unknown")));
                    continue;
            }
            $folder = $mapiprovider->GetFolder($row);
            if ($folder) {
                $folders[] = $folder;
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetHierarchy(): ignoring folder '%s' as MAPIProvider->GetFolder() did not return a SyncFolder object", (isset($row[PR_DISPLAY_NAME]) ? $row[PR_DISPLAY_NAME] : "unknown")));
            }
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetHierarchy(): processed %d folders, starting parent remap", count($folders)));
        // reloop the folders to make sure all parentids are mapped correctly
        $dm = ZPush::GetDeviceManager();
        foreach ($folders as $folder) {
            if ($folder->parentid !== "0") {
                // SYSTEM user's parentid points to $rootfolderprops[PR_SOURCE_KEY], but they need to be on the top level
                $folder->parentid = (strtoupper($this->storeName) == "SYSTEM" && $folder->parentid == bin2hex($rootfolderprops[PR_SOURCE_KEY])) ? '0' : $dm->GetFolderIdForBackendId($folder->parentid);
            }
        }

        return $folders;
    }

    /**
     * Returns the importer to process changes from the mobile
     * If no $folderid is given, hierarchy importer is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ImportChanges)
     */
    public function GetImporter($folderid = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetImporter() folderid: '%s'", Utils::PrintAsString($folderid)));
        if($folderid !== false) {
            // check if the user of the current store has permissions to import to this folderid
            if ($this->storeName != $this->mainUser && !$this->hasSecretaryACLs($this->store, $folderid)) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetImporter(): missing permissions on folderid: '%s'.", Utils::PrintAsString($folderid)));
                return false;
            }
            return new ImportChangesICS($this->session, $this->store, hex2bin($folderid));
        }
        else
            return new ImportChangesICS($this->session, $this->store);
    }

    /**
     * Returns the exporter to send changes to the mobile
     * If no $folderid is given, hierarchy exporter is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ExportChanges)
     * @throws StatusException
     */
    public function GetExporter($folderid = false) {
        if($folderid !== false) {
            // check if the user of the current store has permissions to export from this folderid
            if ($this->storeName != $this->mainUser && !$this->hasSecretaryACLs($this->store, $folderid)) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetExporter(): missing permissions on folderid: '%s'.", Utils::PrintAsString($folderid)));
                return false;
            }
            return new ExportChangesICS($this->session, $this->store, hex2bin($folderid));
        }
        else
            return new ExportChangesICS($this->session, $this->store);
    }

    /**
     * Sends an e-mail
     * This messages needs to be saved into the 'sent items' folder
     *
     * @param SyncSendMail  $sm     SyncSendMail object
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function SendMail($sm) {
        // Check if imtomapi function is available and use it to send the mime message.
        // It is available since ZCP 7.0.6
        // @see http://jira.zarafa.com/browse/ZCP-9508
        if (!(function_exists('mapi_feature') && mapi_feature('INETMAPI_IMTOMAPI'))) {
            throw new StatusException("BackendGrommunio->SendMail(): ZCP/KC version is too old, INETMAPI_IMTOMAPI is not available. Install at least ZCP version 7.0.6 or later.", SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED, null, LOGLEVEL_FATAL);
            return false;
        }
        $mimeLength = strlen($sm->mime);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->SendMail(): RFC822: %d bytes  forward-id: '%s' reply-id: '%s' parent-id: '%s' SaveInSent: '%s' ReplaceMIME: '%s'",
                                            $mimeLength, Utils::PrintAsString($sm->forwardflag), Utils::PrintAsString($sm->replyflag),
                                            Utils::PrintAsString((isset($sm->source->folderid) ? $sm->source->folderid : false)),
                                            Utils::PrintAsString(($sm->saveinsent)), Utils::PrintAsString(isset($sm->replacemime)) ));
        if ($mimeLength == 0) {
            throw new StatusException("BackendGrommunio->SendMail(): empty mail data", SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);
        }

        $sendMailProps = MAPIMapping::GetSendMailProperties();
        $sendMailProps = getPropIdsFromStrings($this->defaultstore, $sendMailProps);

        // Open the outbox and create the message there
        $storeprops = mapi_getprops($this->defaultstore, array($sendMailProps["outboxentryid"], $sendMailProps["ipmsentmailentryid"]));
        if(isset($storeprops[$sendMailProps["outboxentryid"]]))
            $outbox = mapi_msgstore_openentry($this->defaultstore, $storeprops[$sendMailProps["outboxentryid"]]);

        if(!$outbox)
            throw new StatusException(sprintf("BackendGrommunio->SendMail(): No Outbox found or unable to create message: 0x%X", mapi_last_hresult()), SYNC_COMMONSTATUS_SERVERERROR);

        $mapimessage = mapi_folder_createmessage($outbox);

        //message properties to be set
        $mapiprops = array();
        // only save the outgoing in sent items folder if the mobile requests it
        $mapiprops[$sendMailProps["sentmailentryid"]] = $storeprops[$sendMailProps["ipmsentmailentryid"]];

        ZLog::Write(LOGLEVEL_DEBUG, "BackendGrommunio->SendMail(): Use the mapi_inetmapi_imtomapi function");
        $ab = mapi_openaddressbook($this->session);
        mapi_inetmapi_imtomapi($this->session, $this->defaultstore, $ab, $mapimessage, $sm->mime, array());

        // Set the appSeqNr so that tracking tab can be updated for meeting request updates
        // @see http://jira.zarafa.com/browse/ZP-68
        $meetingRequestProps = MAPIMapping::GetMeetingRequestProperties();
        $meetingRequestProps = getPropIdsFromStrings($this->defaultstore, $meetingRequestProps);
        $props = mapi_getprops($mapimessage, array(PR_MESSAGE_CLASS, $meetingRequestProps["goidtag"], $sendMailProps["internetcpid"], $sendMailProps["body"], $sendMailProps["html"], $sendMailProps["rtf"], $sendMailProps["rtfinsync"]));

        // Convert sent message's body to UTF-8 if it was a HTML message.
        // @see http://jira.zarafa.com/browse/ZP-505 and http://jira.zarafa.com/browse/ZP-555
        if (isset($props[$sendMailProps["internetcpid"]]) && $props[$sendMailProps["internetcpid"]] != INTERNET_CPID_UTF8 && MAPIUtils::GetNativeBodyType($props) == SYNC_BODYPREFERENCE_HTML) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->SendMail(): Sent email cpid is not unicode (%d). Set it to unicode and convert email html body.", $props[$sendMailProps["internetcpid"]]));
            $mapiprops[$sendMailProps["internetcpid"]] = INTERNET_CPID_UTF8;

            $bodyHtml = MAPIUtils::readPropStream($mapimessage, PR_HTML);
            $bodyHtml = Utils::ConvertCodepageStringToUtf8($props[$sendMailProps["internetcpid"]], $bodyHtml);
            $mapiprops[$sendMailProps["html"]] = $bodyHtml;

            mapi_setprops($mapimessage, $mapiprops);
        }
        if (stripos($props[PR_MESSAGE_CLASS], "IPM.Schedule.Meeting.Resp.") === 0) {
            // search for calendar items using goid
            $mr = new Meetingrequest($this->defaultstore, $mapimessage);
            $appointments = $mr->findCalendarItems($props[$meetingRequestProps["goidtag"]]);
            if (is_array($appointments) && !empty($appointments)) {
                $app = mapi_msgstore_openentry($this->defaultstore, $appointments[0]);
                $appprops = mapi_getprops($app, array($meetingRequestProps["appSeqNr"]));
                if (isset($appprops[$meetingRequestProps["appSeqNr"]]) && $appprops[$meetingRequestProps["appSeqNr"]]) {
                    $mapiprops[$meetingRequestProps["appSeqNr"]] = $appprops[$meetingRequestProps["appSeqNr"]];
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->SendMail(): Set sequence number to:%d", $appprops[$meetingRequestProps["appSeqNr"]]));
                }
            }
        }

        // Delete the PR_SENT_REPRESENTING_* properties because some android devices
        // do not send neither From nor Sender header causing empty PR_SENT_REPRESENTING_NAME and
        // PR_SENT_REPRESENTING_EMAIL_ADDRESS properties and "broken" PR_SENT_REPRESENTING_ENTRYID
        // which results in spooler not being able to send the message.
        // @see http://jira.zarafa.com/browse/ZP-85
        mapi_deleteprops($mapimessage,
            array(  $sendMailProps["sentrepresentingname"], $sendMailProps["sentrepresentingemail"], $sendMailProps["representingentryid"],
                    $sendMailProps["sentrepresentingaddt"], $sendMailProps["sentrepresentinsrchk"]));

        if(isset($sm->source->itemid) && $sm->source->itemid) {
            // answering an email in a public/shared folder
            // TODO as the store is setup, we should actually user $this->store instead of $this->defaultstore - nevertheless we need to make sure this store is able to send mail (has an outbox)
            if (!$this->Setup(ZPush::GetAdditionalSyncFolderStore($sm->source->folderid)))
                throw new StatusException(sprintf("BackendGrommunio->SendMail() could not Setup() the backend for folder id '%s'", $sm->source->folderid), SYNC_COMMONSTATUS_SERVERERROR);

            $entryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($sm->source->folderid), hex2bin($sm->source->itemid));
            if ($entryid)
                $fwmessage = mapi_msgstore_openentry($this->store, $entryid);

            if (isset($fwmessage) && $fwmessage) {
                // update icon and last_verb when forwarding or replying message
                // reply-all (verb 103) is not supported, as we cannot really detect this case
                if ($sm->forwardflag) {
                    $updateProps = array(
                            PR_ICON_INDEX           => 262,
                            PR_LAST_VERB_EXECUTED   => 104,
                    );
                }
                elseif ($sm->replyflag) {
                    $updateProps = array(
                            PR_ICON_INDEX           => 261,
                            PR_LAST_VERB_EXECUTED   => 102,
                    );
                }
                if (isset($updateProps)) {
                    $updateProps[PR_LAST_VERB_EXECUTION_TIME] = time();
                    mapi_setprops($fwmessage, $updateProps);
                    mapi_savechanges($fwmessage);
                }

                // only attach the original message if the mobile does not send it itself
                if (!isset($sm->replacemime)) {
                    // get message's body in order to append forward or reply text
                    if (!isset($body)) {
                        $body = MAPIUtils::readPropStream($mapimessage, PR_BODY);
                    }
                    if (!isset($bodyHtml)) {
                        $bodyHtml = MAPIUtils::readPropStream($mapimessage, PR_HTML);
                    }
                    $cpid = mapi_getprops($fwmessage, array($sendMailProps["internetcpid"]));
                    if($sm->forwardflag) {
                        // attach the original attachments to the outgoing message
                        $this->copyAttachments($mapimessage, $fwmessage);
                    }

                    // regarding the conversion @see ZP-470
                    if (strlen($body) > 0) {
                        $fwbody = MAPIUtils::readPropStream($fwmessage, PR_BODY);
                        // if only the old message's cpid is set, convert from old charset to utf-8
                        if (isset($cpid[$sendMailProps["internetcpid"]]) && $cpid[$sendMailProps["internetcpid"]] != INTERNET_CPID_UTF8) {
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->SendMail(): convert plain forwarded message charset (only fw set) from '%s' to '65001'", $cpid[$sendMailProps["internetcpid"]]));
                            $fwbody = Utils::ConvertCodepageStringToUtf8($cpid[$sendMailProps["internetcpid"]], $fwbody);
                        }
                        // otherwise to the general conversion
                        else {
                            ZLog::Write(LOGLEVEL_DEBUG, "BackendGrommunio->SendMail(): no charset conversion done for plain forwarded message");
                            $fwbody = w2u($fwbody);
                        }

                        $mapiprops[$sendMailProps["body"]] = $body."\r\n\r\n".$fwbody;
                    }

                    if (strlen($bodyHtml) > 0) {
                        $fwbodyHtml = MAPIUtils::readPropStream($fwmessage, PR_HTML);
                        // if only new message's cpid is set, convert to UTF-8
                        if (isset($cpid[$sendMailProps["internetcpid"]]) && $cpid[$sendMailProps["internetcpid"]] != INTERNET_CPID_UTF8) {
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->SendMail(): convert html forwarded message charset (only fw set) from '%s' to '65001'", $cpid[$sendMailProps["internetcpid"]]));
                            $fwbodyHtml = Utils::ConvertCodepageStringToUtf8($cpid[$sendMailProps["internetcpid"]], $fwbodyHtml);
                        }
                        // otherwise to the general conversion
                        else {
                            ZLog::Write(LOGLEVEL_DEBUG, "BackendGrommunio->SendMail(): no charset conversion done for html forwarded message");
                            $fwbodyHtml = w2u($fwbodyHtml);
                        }

                        $mapiprops[$sendMailProps["html"]] = $bodyHtml."<br><br>".$fwbodyHtml;
                    }
                }
            }
            else {
                // no fwmessage could be opened and we need it because we do not replace mime
                if (!isset($sm->replacemime) || $sm->replacemime == false) {
                    throw new StatusException(sprintf("BackendGrommunio->SendMail(): Could not open message id '%s' in folder id '%s' to be replied/forwarded: 0x%X", $sm->source->itemid, $sm->source->folderid, mapi_last_hresult()), SYNC_COMMONSTATUS_ITEMNOTFOUND);
                }
            }
        }

        mapi_setprops($mapimessage, $mapiprops);
        mapi_savechanges($mapimessage);
        mapi_message_submitmessage($mapimessage);
        $hr = mapi_last_hresult();

        if ($hr) {
            switch ($hr) {
                case MAPI_E_STORE_FULL:
                    $code = SYNC_COMMONSTATUS_MAILBOXQUOTAEXCEEDED;
                    break;
                default:
                    $code = SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED;
            }
            throw new StatusException(sprintf("BackendGrommunio->SendMail(): Error saving/submitting the message to the Outbox: 0x%X", $hr), $code);
        }

        ZLog::Write(LOGLEVEL_DEBUG, "BackendGrommunio->SendMail(): email submitted");
        return true;
    }

    /**
     * Returns all available data of a single message
     *
     * @param string            $folderid
     * @param string            $id
     * @param ContentParameters $contentparameters flag
     *
     * @access public
     * @return object(SyncObject)
     * @throws StatusException
     */
    public function Fetch($folderid, $id, $contentparameters) {
        // SEARCH fetches with folderid == false and PR_ENTRYID as ID
        if (! $folderid) {
            $entryid = hex2bin($id);
            $sk = $id;
        }
        else {
            // id might be in the new longid format, so we have to split it here
            list($fsk, $sk) = Utils::SplitMessageId($id);
            // get the entry id of the message
            $entryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid), hex2bin($sk));

        }
        if(!$entryid)
            throw new StatusException(sprintf("BackendGrommunio->Fetch('%s','%s'): Error getting entryid: 0x%X", $folderid, $sk, mapi_last_hresult()), SYNC_STATUS_OBJECTNOTFOUND);

        // open the message
        $message = mapi_msgstore_openentry($this->store, $entryid);
        if(!$message)
            throw new StatusException(sprintf("BackendGrommunio->Fetch('%s','%s'): Error, unable to open message: 0x%X", $folderid, $sk, mapi_last_hresult()), SYNC_STATUS_OBJECTNOTFOUND);

        // convert the mapi message into a SyncObject and return it
        $mapiprovider = new MAPIProvider($this->session, $this->store);

        // override truncation
        $contentparameters->SetTruncation(SYNC_TRUNCATION_ALL);
        // TODO check for body preferences
        return $mapiprovider->GetMessage($message, $contentparameters);
    }

    /**
     * Returns the waste basket
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket() {
        if ($this->wastebasket) {
            return $this->wastebasket;
        }

        $storeprops = mapi_getprops($this->defaultstore, array(PR_IPM_WASTEBASKET_ENTRYID));
        if (isset($storeprops[PR_IPM_WASTEBASKET_ENTRYID])) {
            $wastebasket = mapi_msgstore_openentry($this->defaultstore, $storeprops[PR_IPM_WASTEBASKET_ENTRYID]);
            $wastebasketprops = mapi_getprops($wastebasket, array(PR_SOURCE_KEY));
            if (isset($wastebasketprops[PR_SOURCE_KEY])) {
                $this->wastebasket = bin2hex($wastebasketprops[PR_SOURCE_KEY]);
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetWasteBasket(): Got waste basket with id '%s'", $this->wastebasket));
                return $this->wastebasket;
            }
        }
        return false;
    }

    /**
     * Returns the content of the named attachment as stream
     *
     * @param string        $attname
     * @access public
     * @return SyncItemOperationsAttachment
     * @throws StatusException
     */
    public function GetAttachmentData($attname) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetAttachmentData('%s')", $attname));

        if(!strpos($attname, ":"))
            throw new StatusException(sprintf("BackendGrommunio->GetAttachmentData('%s'): Error, attachment requested for non-existing item", $attname), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        list($id, $attachnum, $parentEntryid) = explode(":", $attname);
        if (isset($parentEntryid)) {
            $this->Setup(ZPush::GetAdditionalSyncFolderStore($parentEntryid));
        }

        $entryid = hex2bin($id);
        $message = mapi_msgstore_openentry($this->store, $entryid);
        if(!$message)
            throw new StatusException(sprintf("BackendGrommunio->GetAttachmentData('%s'): Error, unable to open item for attachment data for id '%s' with: 0x%X", $attname, $id, mapi_last_hresult()), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        MAPIUtils::ParseSmime($this->session, $this->defaultstore, $this->getAddressbook(), $message);
        $attach = mapi_message_openattach($message, $attachnum);
        if(!$attach)
            throw new StatusException(sprintf("BackendGrommunio->GetAttachmentData('%s'): Error, unable to open attachment number '%s' with: 0x%X", $attname, $attachnum, mapi_last_hresult()), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        // get necessary attachment props
        $attprops = mapi_getprops($attach, array(PR_ATTACH_MIME_TAG, PR_ATTACH_MIME_TAG_W, PR_ATTACH_METHOD));
        $attachment = new SyncItemOperationsAttachment();
        // check if it's an embedded message and open it in such a case
        if (isset($attprops[PR_ATTACH_METHOD]) && $attprops[PR_ATTACH_METHOD] == ATTACH_EMBEDDED_MSG) {
            $embMessage = mapi_attach_openobj($attach);
            $addrbook = $this->getAddressbook();
            $stream = mapi_inetmapi_imtoinet($this->session, $addrbook, $embMessage, array('use_tnef' => -1));
            // set the default contenttype for this kind of messages
            $attachment->contenttype = "message/rfc822";
        }
        else
            $stream = mapi_openproperty($attach, PR_ATTACH_DATA_BIN, IID_IStream, 0, 0);

        if(!$stream)
            throw new StatusException(sprintf("BackendGrommunio->GetAttachmentData('%s'): Error, unable to open attachment data stream: 0x%X", $attname, mapi_last_hresult()), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        // put the mapi stream into a wrapper to get a standard stream
        $attachment->data = MAPIStreamWrapper::Open($stream);
        if (isset($attprops[PR_ATTACH_MIME_TAG]))
            $attachment->contenttype = $attprops[PR_ATTACH_MIME_TAG];
        elseif (isset($attprops[PR_ATTACH_MIME_TAG_W]))
            $attachment->contenttype = $attprops[PR_ATTACH_MIME_TAG_W];
            //TODO default contenttype
        return $attachment;
    }


    /**
     * Deletes all contents of the specified folder.
     * This is generally used to empty the trash (wastebasked), but could also be used on any
     * other folder.
     *
     * @param string        $folderid
     * @param boolean       $includeSubfolders      (opt) also delete sub folders, default true
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function EmptyFolder($folderid, $includeSubfolders = true) {
        $folderentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid));
        if (!$folderentryid)
            throw new StatusException(sprintf("BackendGrommunio->EmptyFolder('%s','%s'): Error, unable to open folder (no entry id)", $folderid, Utils::PrintAsString($includeSubfolders)), SYNC_ITEMOPERATIONSSTATUS_SERVERERROR);
        $folder = mapi_msgstore_openentry($this->store, $folderentryid);

        if (!$folder)
            throw new StatusException(sprintf("BackendGrommunio->EmptyFolder('%s','%s'): Error, unable to open parent folder (open entry)", $folderid, Utils::PrintAsString($includeSubfolders)), SYNC_ITEMOPERATIONSSTATUS_SERVERERROR);

        $flags = 0;
        if ($includeSubfolders)
            $flags = DEL_ASSOCIATED;

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->EmptyFolder('%s','%s'): emptying folder",$folderid, Utils::PrintAsString($includeSubfolders)));

        // empty folder!
        mapi_folder_emptyfolder($folder, $flags);
        if (mapi_last_hresult())
            throw new StatusException(sprintf("BackendGrommunio->EmptyFolder('%s','%s'): Error, mapi_folder_emptyfolder() failed: 0x%X", $folderid, Utils::PrintAsString($includeSubfolders), mapi_last_hresult()), SYNC_ITEMOPERATIONSSTATUS_SERVERERROR);

        return true;
    }

    /**
     * Processes a response to a meeting request.
     * CalendarID is a reference and has to be set if a new calendar item is created
     *
     * @param string        $requestid      id of the object containing the request
     * @param string        $folderid       id of the parent folder of $requestid
     * @param string        $response
     *
     * @access public
     * @return string       id of the created/updated calendar obj
     * @throws StatusException
     */
    public function MeetingResponse($requestid, $folderid, $response) {
        // Use standard meeting response code to process meeting request
        list($fid, $requestid) = Utils::SplitMessageId($requestid);
        $reqentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid), hex2bin($requestid));
        if (!$reqentryid)
            throw new StatusException(sprintf("BackendGrommunio->MeetingResponse('%s', '%s', '%s'): Error, unable to entryid of the message 0x%X", $requestid, $folderid, $response, mapi_last_hresult()), SYNC_MEETRESPSTATUS_INVALIDMEETREQ);

        $mapimessage = mapi_msgstore_openentry($this->store, $reqentryid);
        if(!$mapimessage)
            throw new StatusException(sprintf("BackendGrommunio->MeetingResponse('%s','%s', '%s'): Error, unable to open request message for response 0x%X", $requestid, $folderid, $response, mapi_last_hresult()), SYNC_MEETRESPSTATUS_INVALIDMEETREQ);

        // ios sends calendar item in MeetingResponse
        // @see https://jira.z-hub.io/browse/ZP-1524
        $folderClass = ZPush::GetDeviceManager()->GetFolderClassFromCacheByID($fid);
        // find the corresponding meeting request
        if ($folderClass != 'Email') {
            $props = MAPIMapping::GetMeetingRequestProperties();
            $props = getPropIdsFromStrings($this->store, $props);

            $messageprops = mapi_getprops($mapimessage, array($props["goidtag"]));
            $goid = $messageprops[$props["goidtag"]];

            $mapiprovider = new MAPIProvider($this->session, $this->store);
            $inboxprops = $mapiprovider->GetInboxProps();
            $folder = mapi_msgstore_openentry($this->store, $inboxprops[PR_ENTRYID]);

            // Find the item by restricting all items to the correct ID
            $restrict = array(RES_AND, array(
                array(RES_PROPERTY,
                    array(
                        RELOP => RELOP_EQ,
                        ULPROPTAG => $props["goidtag"],
                        VALUE => $goid
                    )
                )
            ));

            $inboxcontents = mapi_folder_getcontentstable($folder);

            $rows = mapi_table_queryallrows($inboxcontents, array(PR_ENTRYID), $restrict);
            if (empty($rows)) {
                throw new StatusException(sprintf("BackendGrommunio->MeetingResponse('%s','%s', '%s'): Error, meeting request not found in the inbox", $requestid, $folderid, $response), SYNC_MEETRESPSTATUS_INVALIDMEETREQ);
            }
            ZLog::Write(LOGLEVEL_DEBUG, "BackendGrommunio->MeetingResponse found meeting request in the inbox");
            $mapimessage = mapi_msgstore_openentry($this->store, $rows[0][PR_ENTRYID]);
            $reqentryid = $rows[0][PR_ENTRYID];
        }

        $meetingrequest = new Meetingrequest($this->store, $mapimessage, $this->session);

        if(!$meetingrequest->isMeetingRequest())
            throw new StatusException(sprintf("BackendGrommunio->MeetingResponse('%s','%s', '%s'): Error, attempt to respond to non-meeting request", $requestid, $folderid, $response), SYNC_MEETRESPSTATUS_INVALIDMEETREQ);

        if($meetingrequest->isLocalOrganiser())
            throw new StatusException(sprintf("BackendGrommunio->MeetingResponse('%s','%s', '%s'): Error, attempt to response to meeting request that we organized", $requestid, $folderid, $response), SYNC_MEETRESPSTATUS_INVALIDMEETREQ);

        // Process the meeting response. We don't have to send the actual meeting response
        // e-mail, because the device will send it itself. This seems not to be the case
        // anymore for the ios devices since at least version 12.4. grommunio-sync will send the
        // accepted email in such a case.
        // @see https://jira.z-hub.io/browse/ZP-1524
        $sendresponse = false;
        $deviceType = strtolower(Request::GetDeviceType());
        if ($deviceType == 'iphone' || $deviceType == 'ipad' || $deviceType == 'ipod') {
            $matches = array();
            if (preg_match("/^Apple-.*?\/(\d{4})\./", Request::GetUserAgent(), $matches) && isset($matches[1]) && $matches[1] >= 1607) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->MeetingResponse: iOS device %s->%s", Request::GetDeviceType(), Request::GetUserAgent()));
                $sendresponse = true;
            }
        }
        switch($response) {
            case 1:     // accept
            default:
                $entryid = $meetingrequest->doAccept(false, $sendresponse, false, false, false, false, true); // last true is the $userAction
                break;
            case 2:        // tentative
                $entryid = $meetingrequest->doAccept(true, $sendresponse, false, false, false, false, true); // last true is the $userAction
                break;
            case 3:        // decline
                $meetingrequest->doDecline(false);
                break;
        }

        // F/B will be updated on logoff

        // We have to return the ID of the new calendar item, so do that here
        $calendarid = "";
        $calFolderId = "";
        if (isset($entryid)) {
            $newitem = mapi_msgstore_openentry($this->store, $entryid);
            // new item might be in a delegator's store. ActiveSync does not support accepting them.
            if (!$newitem) {
                throw new StatusException(sprintf("BackendGrommunio->MeetingResponse('%s','%s', '%s'): Object with entryid '%s' was not found in user's store (0x%X). It might be in a delegator's store.", $requestid, $folderid, $response, bin2hex($entryid), mapi_last_hresult()), SYNC_MEETRESPSTATUS_SERVERERROR, null, LOGLEVEL_WARN);
            }

            $newprops = mapi_getprops($newitem, array(PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY));
            $calendarid = bin2hex($newprops[PR_SOURCE_KEY]);
            $calFolderId = bin2hex($newprops[PR_PARENT_SOURCE_KEY]);
        }

        // on recurring items, the MeetingRequest class responds with a wrong entryid
        if ($requestid == $calendarid) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->MeetingResponse('%s','%s', '%s'): returned calender id is the same as the requestid - re-searching", $requestid, $folderid, $response));

            if (empty($props)) {
                $props = MAPIMapping::GetMeetingRequestProperties();
                $props = getPropIdsFromStrings($this->store, $props);

                $messageprops = mapi_getprops($mapimessage, Array($props["goidtag"]));
                $goid = $messageprops[$props["goidtag"]];
            }

            $items = $meetingrequest->findCalendarItems($goid);

            if (is_array($items)) {
               $newitem = mapi_msgstore_openentry($this->store, $items[0]);
               $newprops = mapi_getprops($newitem, array(PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY));
               $calendarid = bin2hex($newprops[PR_SOURCE_KEY]);
               $calFolderId = bin2hex($newprops[PR_PARENT_SOURCE_KEY]);
               ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->MeetingResponse('%s','%s', '%s'): found other calendar entryid", $requestid, $folderid, $response));
            }

            if ($requestid == $calendarid)
                throw new StatusException(sprintf("BackendGrommunio->MeetingResponse('%s','%s', '%s'): Error finding the accepted meeting response in the calendar", $requestid, $folderid, $response), SYNC_MEETRESPSTATUS_INVALIDMEETREQ);
        }

        // delete meeting request from Inbox
        if ($folderClass == 'Email') {
            $folderentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid));
            $folder = mapi_msgstore_openentry($this->store, $folderentryid);
        }
        mapi_folder_deletemessages($folder, array($reqentryid), 0);

        $prefix = '';
        // prepend the short folderid of the target calendar: if available and short ids are used
        if ($calFolderId) {
            $shortFolderId = ZPush::GetDeviceManager()->GetFolderIdForBackendId($calFolderId);
            if ($calFolderId != $shortFolderId) {
                $prefix = $shortFolderId . ':';
            }
        }
        return $prefix . $calendarid;
    }

    /**
     * Indicates if the backend has a ChangesSink.
     * A sink is an active notification mechanism which does not need polling.
     * Since Zarafa 7.0.5 such a sink is available.
     * The grommunio backend uses this method to initialize the sink with mapi.
     *
     * @access public
     * @return boolean
     */
    public function HasChangesSink() {
        if (!$this->notifications) {
            ZLog::Write(LOGLEVEL_DEBUG, "BackendGrommunio->HasChangesSink(): sink is not available");
            return false;
        }

        $this->changesSink = @mapi_sink_create();

        if (! $this->changesSink || mapi_last_hresult()) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->HasChangesSink(): sink could not be created with  0x%X", mapi_last_hresult()));
            return false;
        }

        $this->changesSinkHierarchyHash = $this->getHierarchyHash();
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->HasChangesSink(): created - HierarchyHash: %s", $this->changesSinkHierarchyHash));

        // advise the main store and also to check if the connection supports it
        return $this->adviseStoreToSink($this->defaultstore);
    }

    /**
     * The folder should be considered by the sink.
     * Folders which were not initialized should not result in a notification
     * of IBackend->ChangesSink().
     *
     * @param string        $folderid
     *
     * @access public
     * @return boolean      false if entryid can not be found for that folder
     */
    public function ChangesSinkInitialize($folderid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->ChangesSinkInitialize(): folderid '%s'", $folderid));

        $entryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid));
        if (!$entryid)
            return false;

        // add entryid to the monitored folders
        $this->changesSinkFolders[$entryid] = $folderid;

        // advise the current store to the sink
        return $this->adviseStoreToSink($this->store);
    }

    /**
     * The actual ChangesSink.
     * For max. the $timeout value this method should block and if no changes
     * are available return an empty array.
     * If changes are available a list of folderids is expected.
     *
     * @param int           $timeout        max. amount of seconds to block
     *
     * @access public
     * @return array
     */
    public function ChangesSink($timeout = 30) {
        // clear the folder stats cache
        unset($this->folderStatCache);

        $notifications = array();
        $hierarchyNotifications = array();
        $sinkresult = @mapi_sink_timedwait($this->changesSink, $timeout * 1000);

        if (!is_array($sinkresult))
            throw new StatusException("BackendGrommunio->ChangesSink(): Sink returned invalid notification, aborting", SyncCollections::OBSOLETE_CONNECTION);

        // reverse array so that the changes on folders are before changes on messages and
        // it's possible to filter such notifications
        $sinkresult = array_reverse($sinkresult, true);
        foreach ($sinkresult as $sinknotif) {
            // add a notification on a folder
            if ($sinknotif['objtype'] == MAPI_FOLDER) {
                $hierarchyNotifications[$sinknotif['entryid']] = IBackend::HIERARCHYNOTIFICATION;
            }
            // change on a message, remove hierarchy notification
            if (isset($sinknotif['parentid']) && $sinknotif['objtype'] == MAPI_MESSAGE && isset($notifications[$sinknotif['parentid']])) {
                unset($hierarchyNotifications[$sinknotif['parentid']]);
            }

            // TODO check if adding $sinknotif['objtype'] = MAPI_MESSAGE wouldn't break anything
            // check if something in the monitored folders changed
            if (isset($sinknotif['parentid']) && array_key_exists($sinknotif['parentid'], $this->changesSinkFolders)) {
                $notifications[] = $this->changesSinkFolders[$sinknotif['parentid']];
            }
            // deletes and moves
            if (isset($sinknotif['oldparentid']) && array_key_exists($sinknotif['oldparentid'], $this->changesSinkFolders)) {
                $notifications[] = $this->changesSinkFolders[$sinknotif['oldparentid']];
            }
        }

        // validate hierarchy notifications by comparing the hierarchy hashes (too many false positives otherwise)
        if (!empty($hierarchyNotifications)) {
            $hash = $this->getHierarchyHash();
            if ($hash !== $this->changesSinkHierarchyHash) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->ChangesSink() Hierarchy notification, pending validation. New hierarchyHash: %s", $hash));
                $notifications[] = IBackend::HIERARCHYNOTIFICATION;
                $this->changesSinkHierarchyHash = $hash;
            }
        }
        return $notifications;
    }

    /**
     * Applies settings to and gets informations from the device.
     *
     * @param SyncObject    $settings (SyncOOF, SyncUserInformation, SyncRightsManagementTemplates possible)
     *
     * @access public
     * @return SyncObject       $settings
     */
    public function Settings($settings) {
        if ($settings instanceof SyncOOF) {
            $this->settingsOOF($settings);
        }

        if ($settings instanceof SyncUserInformation) {
            $this->settingsUserInformation($settings);
        }

        if ($settings instanceof SyncRightsManagementTemplates) {
            $this->settingsRightsManagementTemplates($settings);
        }

        return $settings;
    }

    /**
     * Resolves recipients
     *
     * @param SyncObject        $resolveRecipients
     *
     * @access public
     * @return SyncObject       $resolveRecipients
     */
    public function ResolveRecipients($resolveRecipients) {
        if ($resolveRecipients instanceof SyncResolveRecipients) {
            $resolveRecipients->status = SYNC_RESOLVERECIPSSTATUS_SUCCESS;
            $resolveRecipients->response = array();
            $resolveRecipientsOptions = new SyncResolveRecipientsOptions();
            $maxAmbiguousRecipients = self::MAXAMBIGUOUSRECIPIENTS;

            if (isset($resolveRecipients->options)) {
                $resolveRecipientsOptions = $resolveRecipients->options;
                // only limit ambiguous recipients if the client requests it.

                if (isset($resolveRecipientsOptions->maxambiguousrecipients) &&
                        $resolveRecipientsOptions->maxambiguousrecipients >= 0 &&
                        $resolveRecipientsOptions->maxambiguousrecipients <= self::MAXAMBIGUOUSRECIPIENTS) {
                    $maxAmbiguousRecipients = $resolveRecipientsOptions->maxambiguousrecipients;
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->ResolveRecipients(): The client requested %d max ambigous recipients to resolve.", $maxAmbiguousRecipients));
                }
            }

            foreach ($resolveRecipients->to as $i => $to) {
                $response = new SyncResolveRecipientsResponse();
                $response->to = $to;
                $response->status = SYNC_RESOLVERECIPSSTATUS_SUCCESS;

                // do not expand distlists here
                $recipient = $this->resolveRecipient($to, $maxAmbiguousRecipients, false);
                if (is_array($recipient) && !empty($recipient)) {
                    $response->recipientcount = 0;
                    foreach ($recipient as $entry) {
                        if ($entry instanceof SyncResolveRecipient) {
                            // certificates are already set. Unset them if they weren't required.
                            if (!isset($resolveRecipientsOptions->certificateretrieval)) {
                                unset($entry->certificates);
                            }
                            if (isset($resolveRecipientsOptions->availability)) {
                                if (!isset($resolveRecipientsOptions->starttime)) {
                                    // TODO error, the request must include a valid StartTime element value
                                }
                                $entry->availability = $this->getAvailability($to, $entry, $resolveRecipientsOptions);
                            }
                            if (isset($resolveRecipientsOptions->picture)) {
                                // TODO implement picture retrieval of the recipient
                            }
                            $response->recipientcount++;
                            $response->recipient[] = $entry;
                        }
                        elseif (is_int($recipient)) {
                            $response->status = $recipient;
                        }
                    }
                }

                $resolveRecipients->response[$i] = $response;
            }

            return $resolveRecipients;
        }

        ZLog::Write(LOGLEVEL_WARN, "BackendGrommunio->ResolveRecipients(): Not a valid SyncResolveRecipients object.");
        // return a SyncResolveRecipients object so that sync doesn't fail
        $r = new SyncResolveRecipients();
        $r->status = SYNC_RESOLVERECIPSSTATUS_PROTOCOLERROR;
        return $r;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Implementation of the ISearchProvider interface
     */

    /**
     * Indicates if a search type is supported by this SearchProvider
     * Currently only the type ISearchProvider::SEARCH_GAL (Global Address List) is implemented
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype) {
        return ($searchtype == ISearchProvider::SEARCH_GAL) || ($searchtype == ISearchProvider::SEARCH_MAILBOX);
    }

    /**
     * Searches the GAB of Grommunio
     * Can be overwitten globally by configuring a SearchBackend
     *
     * @param string                        $searchquery        string to be searched for
     * @param string                        $searchrange        specified searchrange
     * @param SyncResolveRecipientsPicture  $searchpicture      limitations for picture
     *
     * @access public
     * @return array        search results
     * @throws StatusException
     */
    public function GetGALSearchResults($searchquery, $searchrange, $searchpicture) {
        // only return users whose displayName or the username starts with $name
        //TODO: use PR_ANR for this restriction instead of PR_DISPLAY_NAME and PR_ACCOUNT
        $addrbook = $this->getAddressbook();
        // FIXME: create a function to get the adressbook contentstable
        if ($addrbook)
            $ab_entryid = mapi_ab_getdefaultdir($addrbook);
        if ($ab_entryid)
            $ab_dir = mapi_ab_openentry($addrbook, $ab_entryid);
        if ($ab_dir)
            $table = mapi_folder_getcontentstable($ab_dir);

        if (!$table)
            throw new StatusException(sprintf("BackendGrommunio->GetGALSearchResults(): could not open addressbook: 0x%X", mapi_last_hresult()), SYNC_SEARCHSTATUS_STORE_CONNECTIONFAILED);

        $restriction = MAPIUtils::GetSearchRestriction(u2w($searchquery));
        mapi_table_restrict($table, $restriction);
        mapi_table_sort($table, array(PR_DISPLAY_NAME => TABLE_SORT_ASCEND));

        if (mapi_last_hresult())
            throw new StatusException(sprintf("BackendGrommunio->GetGALSearchResults(): could not apply restriction: 0x%X", mapi_last_hresult()), SYNC_SEARCHSTATUS_STORE_TOOCOMPLEX);

        //range for the search results, default symbian range end is 50, wm 99,
        //so we'll use that of nokia
        $rangestart = 0;
        $rangeend = 50;

        if ($searchrange != '0') {
            $pos = strpos($searchrange, '-');
            $rangestart = substr($searchrange, 0, $pos);
            $rangeend = substr($searchrange, ($pos + 1));
        }
        $items = array();

        $querycnt = mapi_table_getrowcount($table);
        //do not return more results as requested in range
        $querylimit = (($rangeend + 1) < $querycnt) ? ($rangeend + 1) : $querycnt;

        if ($querycnt > 0)
            $abentries = mapi_table_queryrows($table, array(PR_ENTRYID, PR_ACCOUNT, PR_DISPLAY_NAME, PR_SMTP_ADDRESS, PR_BUSINESS_TELEPHONE_NUMBER, PR_GIVEN_NAME, PR_SURNAME, PR_MOBILE_TELEPHONE_NUMBER, PR_HOME_TELEPHONE_NUMBER, PR_TITLE, PR_COMPANY_NAME, PR_OFFICE_LOCATION, PR_EMS_AB_THUMBNAIL_PHOTO), $rangestart, $querylimit);

        for ($i = 0; $i < $querylimit; $i++) {
            if (!isset($abentries[$i][PR_SMTP_ADDRESS])) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->GetGALSearchResults(): The GAL entry '%s' does not have an email address and will be ignored.", w2u($abentries[$i][PR_DISPLAY_NAME])));
                continue;
            }

            $items[$i][SYNC_GAL_DISPLAYNAME] = w2u($abentries[$i][PR_DISPLAY_NAME]);

            if (strlen(trim($items[$i][SYNC_GAL_DISPLAYNAME])) == 0)
                $items[$i][SYNC_GAL_DISPLAYNAME] = w2u($abentries[$i][PR_ACCOUNT]);

            $items[$i][SYNC_GAL_ALIAS] = w2u($abentries[$i][PR_ACCOUNT]);
            //it's not possible not get first and last name of an user
            //from the gab and user functions, so we just set lastname
            //to displayname and leave firstname unset
            //this was changed in Zarafa 6.40, so we try to get first and
            //last name and fall back to the old behaviour if these values are not set
            if (isset($abentries[$i][PR_GIVEN_NAME]))
                $items[$i][SYNC_GAL_FIRSTNAME] = w2u($abentries[$i][PR_GIVEN_NAME]);
            if (isset($abentries[$i][PR_SURNAME]))
                $items[$i][SYNC_GAL_LASTNAME] = w2u($abentries[$i][PR_SURNAME]);

            if (!isset($items[$i][SYNC_GAL_LASTNAME])) $items[$i][SYNC_GAL_LASTNAME] = $items[$i][SYNC_GAL_DISPLAYNAME];

            $items[$i][SYNC_GAL_EMAILADDRESS] = w2u($abentries[$i][PR_SMTP_ADDRESS]);
            //check if an user has an office number or it might produce warnings in the log
            if (isset($abentries[$i][PR_BUSINESS_TELEPHONE_NUMBER]))
                $items[$i][SYNC_GAL_PHONE] = w2u($abentries[$i][PR_BUSINESS_TELEPHONE_NUMBER]);
            //check if an user has a mobile number or it might produce warnings in the log
            if (isset($abentries[$i][PR_MOBILE_TELEPHONE_NUMBER]))
                $items[$i][SYNC_GAL_MOBILEPHONE] = w2u($abentries[$i][PR_MOBILE_TELEPHONE_NUMBER]);
            //check if an user has a home number or it might produce warnings in the log
            if (isset($abentries[$i][PR_HOME_TELEPHONE_NUMBER]))
                $items[$i][SYNC_GAL_HOMEPHONE] = w2u($abentries[$i][PR_HOME_TELEPHONE_NUMBER]);

            if (isset($abentries[$i][PR_COMPANY_NAME]))
                $items[$i][SYNC_GAL_COMPANY] = w2u($abentries[$i][PR_COMPANY_NAME]);

            if (isset($abentries[$i][PR_TITLE]))
                $items[$i][SYNC_GAL_TITLE] = w2u($abentries[$i][PR_TITLE]);

            if (isset($abentries[$i][PR_OFFICE_LOCATION]))
                $items[$i][SYNC_GAL_OFFICE] = w2u($abentries[$i][PR_OFFICE_LOCATION]);

            if ($searchpicture !== false && isset($abentries[$i][PR_EMS_AB_THUMBNAIL_PHOTO])) {
                $items[$i][SYNC_GAL_PICTURE] = StringStreamWrapper::Open($abentries[$i][PR_EMS_AB_THUMBNAIL_PHOTO]);
            }
        }
        $nrResults = count($items);
        $items['range'] = ($nrResults > 0) ? $rangestart.'-'.($nrResults - 1) : '0-0';
        $items['searchtotal'] = $nrResults;
        return $items;
    }

    /**
     * Searches for the emails on the server
     *
     * @param ContentParameter $cpo
     *
     * @return array
     */
    public function GetMailboxSearchResults($cpo) {
        $searchFolder = $this->getSearchFolder();
        $searchRestriction = $this->getSearchRestriction($cpo);
        $searchRange = explode('-', $cpo->GetSearchRange());
        $searchFolderId = $cpo->GetSearchFolderid();
        $searchFolders = array();
        // search only in required folders
        if (!empty($searchFolderId)) {
            $searchFolderEntryId = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($searchFolderId));
            $searchFolders[] = $searchFolderEntryId;
        }
        // if no folder was required then search in the entire store
        else {
            $tmp = mapi_getprops($this->store, array(PR_ENTRYID,PR_DISPLAY_NAME,PR_IPM_SUBTREE_ENTRYID));
            $searchFolders[] = $tmp[PR_IPM_SUBTREE_ENTRYID];
        }
        $items = array();
        $flags = 0;
        // if subfolders are required, do a recursive search
        if ($cpo->GetSearchDeepTraversal()) {
            $flags |= SEARCH_RECURSIVE;
        }

        mapi_folder_setsearchcriteria($searchFolder, $searchRestriction, $searchFolders, $flags);

        $table = mapi_folder_getcontentstable($searchFolder);
        $searchStart = time();
        // do the search and wait for all the results available
        while (time() - $searchStart < SEARCH_WAIT) {
            $searchcriteria = mapi_folder_getsearchcriteria($searchFolder);
            if(($searchcriteria["searchstate"] & SEARCH_REBUILD) == 0)
                break; // Search is done
            sleep(1);
        }

        // if the search range is set limit the result to it, otherwise return all found messages
        $rows = (is_array($searchRange) && isset($searchRange[0], $searchRange[1])) ?
            mapi_table_queryrows($table, array(PR_ENTRYID), $searchRange[0], $searchRange[1] - $searchRange[0] + 1) :
            mapi_table_queryrows($table, array(PR_ENTRYID), 0, SEARCH_MAXRESULTS);

        $cnt = count($rows);
        $items['searchtotal'] = $cnt;
        $items["range"] = $cpo->GetSearchRange();
        for ($i = 0; $i < $cnt; $i++) {
            $items[$i]['class'] = 'Email';
            $items[$i]['longid'] = bin2hex($rows[$i][PR_ENTRYID]);
            //$items[$i]['folderid'] = bin2hex($rows[$i][PR_PARENT_SOURCE_KEY]);
        }
        return $items;
    }

    /**
    * Terminates a search for a given PID
    *
    * @param int $pid
    *
    * @return boolean
    */
    public function TerminateSearch($pid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->TerminateSearch(): terminating search for pid %d", $pid));
        if (!isset($this->store) || $this->store === false) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->TerminateSearch(): The store is not available. It is not possible to remove search folder with pid %d", $pid));
            return false;
        }

        $storeProps = mapi_getprops($this->store, array(PR_STORE_SUPPORT_MASK, PR_FINDER_ENTRYID));
        if (($storeProps[PR_STORE_SUPPORT_MASK] & STORE_SEARCH_OK) != STORE_SEARCH_OK) {
            ZLog::Write(LOGLEVEL_WARN, "BackendGrommunio->TerminateSearch(): Store doesn't support search folders. Public store doesn't have FINDER_ROOT folder");
            return false;
        }

        $finderfolder = mapi_msgstore_openentry($this->store, $storeProps[PR_FINDER_ENTRYID]);
        if(mapi_last_hresult() != NOERROR) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->TerminateSearch(): Unable to open search folder (0x%X)", mapi_last_hresult()));
            return false;
        }

        $hierarchytable = mapi_folder_gethierarchytable($finderfolder);
        mapi_table_restrict($hierarchytable,
            array(RES_CONTENT,
                array(
                    FUZZYLEVEL      => FL_PREFIX,
                    ULPROPTAG       => PR_DISPLAY_NAME,
                    VALUE           => array(PR_DISPLAY_NAME=>"grommunio-sync Search Folder ".$pid)
                )
            ),
            TBL_BATCH);

        $folders = mapi_table_queryallrows($hierarchytable, array(PR_ENTRYID, PR_DISPLAY_NAME, PR_LAST_MODIFICATION_TIME));
        foreach($folders as $folder) {
            mapi_folder_deletefolder($finderfolder, $folder[PR_ENTRYID]);
        }
        return true;
    }

    /**
     * Disconnects from the current search provider
     *
     * @access public
     * @return boolean
     */
    public function Disconnect() {
        return true;
    }

    /**
     * Returns the MAPI store ressource for a folderid
     * This is not part of IBackend but necessary for the ImportChangesICS->MoveMessage() operation if
     * the destination folder is not in the default store
     * Note: The current backend store might be changed as IBackend->Setup() is executed
     *
     * @param string        $store              target store, could contain a "domain\user" value - if emtpy default store is returned
     * @param string        $folderid
     *
     * @access public
     * @return Ressource/boolean
     */
    public function GetMAPIStoreForFolderId($store, $folderid) {
        if ($store == false) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetMAPIStoreForFolderId('%s', '%s'): no store specified, returning default store", $store, $folderid));
            return $this->defaultstore;
        }

        // setup the correct store
        if ($this->Setup($store, false, $folderid)) {
            return $this->store;
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->GetMAPIStoreForFolderId('%s', '%s'): store is not available", $store, $folderid));
            return false;
        }
    }

    /**
     * Returns the email address and the display name of the user. Used by autodiscover.
     *
     * @param string        $username           The username
     *
     * @access public
     * @return Array
     */
    public function GetUserDetails($username) {
        ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendGrommunio->GetUserDetails for '%s'.", $username));
        $zarafauserinfo = @nsp_getuserinfo($username);
        $userDetails['emailaddress'] = (isset($zarafauserinfo['primary_email']) && $zarafauserinfo['primary_email']) ? $zarafauserinfo['primary_email'] : false;
        $userDetails['fullname'] = (isset($zarafauserinfo['fullname']) && $zarafauserinfo['fullname']) ? $zarafauserinfo['fullname'] : false;
        return $userDetails;
    }

    /**
     * Returns the username of the currently active user
     *
     * @access public
     * @return String
     */
    public function GetCurrentUsername() {
        return $this->storeName;
    }

    /**
     * Returns the impersonated user name.
     *
     * @access public
     * @return string or false if no user is impersonated
     */
    public function GetImpersonatedUser() {
        return $this->impersonateUser;
    }

    /**
     * Returns the authenticated user name.
     *
     * @access public
     * @return string
     */
    public function GetMainUser() {
        return $this->mainUser;
    }

    /**
     * Indicates if the Backend supports folder statistics.
     *
     * @access public
     * @return boolean
     */
    public function HasFolderStats() {
        return true;
    }

    /**
     * Returns a status indication of the folder.
     * If there are changes in the folder, the returned value must change.
     * The returned values are compared with '===' to determine if a folder needs synchronization or not.
     *
     * @param string $store         the store where the folder resides
     * @param string $folderid      the folder id
     *
     * @access public
     * @return string
     */
    public function GetFolderStat($store, $folderid) {
        list($user, $domain) = Utils::SplitDomainUser($store);
        if ($user === false) {
            $user = $this->mainUser;
            if ($this->impersonateUser) {
                $user = $this->impersonateUser;
            }
        }

        if (!isset($this->folderStatCache[$user])) {
            $this->folderStatCache[$user] = array();
        }

        // if there is nothing in the cache for a store, load the data for all folders of it
        if (empty($this->folderStatCache[$user])) {
            // get the store
            $userstore = $this->openMessageStore($user);
            $rootfolder = mapi_msgstore_openentry($userstore);
            $hierarchy =  mapi_folder_gethierarchytable($rootfolder, CONVENIENT_DEPTH);
            $rows = mapi_table_queryallrows($hierarchy, array(PR_SOURCE_KEY, PR_LOCAL_COMMIT_TIME_MAX, PR_CONTENT_COUNT, PR_CONTENT_UNREAD, PR_DELETED_MSG_COUNT));

            if (count($rows) == 0) {
                ZLog::Write(LOGLEVEL_INFO, sprintf("BackendGrommunio->GetFolderStat(): could not access folder statistics for user '%s'. Probably missing 'read' permissions on the root folder! Folders of this store will be synchronized ONCE per hour only!", $user));
            }

            foreach($rows as $folder) {
                $commit_time = isset($folder[PR_LOCAL_COMMIT_TIME_MAX])? $folder[PR_LOCAL_COMMIT_TIME_MAX] : "0000000000";
                $content_count = isset($folder[PR_CONTENT_COUNT])? $folder[PR_CONTENT_COUNT] : -1;
                $content_unread = isset($folder[PR_CONTENT_UNREAD])? $folder[PR_CONTENT_UNREAD] : -1;
                $content_deleted = isset($folder[PR_DELETED_MSG_COUNT])? $folder[PR_DELETED_MSG_COUNT] : -1;

                $this->folderStatCache[$user][bin2hex($folder[PR_SOURCE_KEY])] = $commit_time ."/". $content_count ."/". $content_unread ."/". $content_deleted;
            }
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetFolderStat() fetched status information of %d folders for store '%s'", count($this->folderStatCache[$user]), $user));
        }

        if (isset($this->folderStatCache[$user][$folderid])) {
            return $this->folderStatCache[$user][$folderid];
        }
        else {
            // a timestamp that changes once per hour is returned in case there is no data found for this folder. It will be synchronized only once per hour.
            return gmdate("Y-m-d-H");
        }
    }

    /**
     * Returns information about the user's store:
     * number of folders, store size, full name, email address.
     *
     * @access public
     * @return UserStoreInfo
     */
    public function GetUserStoreInfo() {
        $userStoreInfo = new UserStoreInfo();

        $rootfolder = mapi_msgstore_openentry($this->store);
        $hierarchy =  mapi_folder_gethierarchytable($rootfolder, CONVENIENT_DEPTH);
        // Do not take hidden and system folders into account
        // TODO make this restriction generic and use for hierarchy?
        $restrict = array(RES_AND, array(
                            array(  RES_PROPERTY,
                                array(  RELOP => RELOP_NE,
                                        ULPROPTAG => PR_ATTR_HIDDEN,
                                        VALUE => true),
                            ),
                            array(  RES_PROPERTY,
                                array(  RELOP => RELOP_EQ,
                                        ULPROPTAG => PR_FOLDER_TYPE,
                                        VALUE => FOLDER_GENERIC),
                            ),
                            array(  RES_EXIST,
                                array(  ULPROPTAG => PR_CONTAINER_CLASS),
                            ),
                        ));
        mapi_table_restrict($hierarchy, $restrict);
        $foldercount = mapi_table_getrowcount($hierarchy);

        $storeProps = mapi_getprops($this->store, array(PR_MESSAGE_SIZE_EXTENDED));
        $storesize = isset($storeProps[PR_MESSAGE_SIZE_EXTENDED]) ? $storeProps[PR_MESSAGE_SIZE_EXTENDED] : 0;

        $userDetails = $this->GetUserDetails($this->impersonateUser ?: $this->mainUser);
        $userStoreInfo->SetData($foldercount, $storesize, $userDetails['fullname'], $userDetails['emailaddress']);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetUserStoreInfo(): user %s (%s) store size is %d bytes and contains %d folders",
                Utils::PrintAsString($userDetails['fullname']), Utils::PrintAsString($userDetails['emailaddress']), $storesize, $foldercount));

        return $userStoreInfo;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Implementation of the IStateMachine interface
     */

    /**
     * Gets a hash value indicating the latest dataset of the named
     * state with a specified key and counter.
     * If the state is changed between two calls of this method
     * the returned hash should be different
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param string    $counter            (opt)
     *
     * @access public
     * @return string
     * @throws StateNotFoundException
     */
    public function GetStateHash($devid, $type, $key = false, $counter = false) {
        try {
            $stateMessage = $this->getStateMessage($devid, $type, $key, $counter);
            $stateMessageProps = mapi_getprops($stateMessage, [PR_LAST_MODIFICATION_TIME]);
            if (isset($stateMessageProps[PR_LAST_MODIFICATION_TIME])) {
                return $stateMessageProps[PR_LAST_MODIFICATION_TIME];
            }
        }
        catch (StateNotFoundException $e) { }
        return "0";
    }

    /**
     * Gets a state for a specified key and counter.
     * This method sould call IStateMachine->CleanStates()
     * to remove older states (same key, previous counters)
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param string    $counter            (opt)
     * @param string    $cleanstates        (opt)
     *
     * @access public
     * @return mixed
     * @throws StateNotFoundException, StateInvalidException, UnavailableException
     */
    public function GetState($devid, $type, $key = false, $counter = false, $cleanstates = true) {
        if ($counter && $cleanstates) {
            $this->CleanStates($devid, $type, $key, $counter);
            // also clean Failsave state for previous counter
            if ($key == false) {
                $this->CleanStates($devid, $type, IStateMachine::FAILSAVE, $counter);
            }
        }
        $stateMessage = $this->getStateMessage($devid, $type, $key, $counter);
        $state = base64_decode(MAPIUtils::readPropStream($stateMessage, PR_BODY));

        if ($state && $state[0] === '{') {
            $jsonDec = json_decode($state);
            if (isset($jsonDec->gsSyncStateClass)) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->GetState(): top class '%s'", $jsonDec->gsSyncStateClass));
                $gsObj = new $jsonDec->gsSyncStateClass;
                $gsObj->jsonDeserialize($jsonDec);
                $gsObj->postUnserialize();
            }
        }
        return isset($gsObj) && is_object($gsObj) ? $gsObj : $state;
    }

    /**
     * Writes ta state to for a key and counter
     *
     * @param mixed     $state
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param int       $counter            (opt)
     *
     * @access public
     * @return boolean
     * @throws StateInvalidException, UnavailableException
     */
    public function SetState($state, $devid, $type, $key = false, $counter = false) {
        return $this->setStateMessage($state, $devid, $type, $key, $counter);
    }

    /**
     * Cleans up all older states.
     * If called with a $counter, all states previous state counter can be removed.
     * If additionally the $thisCounterOnly flag is true, only that specific counter will be removed.
     * If called without $counter, all keys (independently from the counter) can be removed.
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key
     * @param string    $counter            (opt)
     * @param string    $thisCounterOnly    (opt) if provided, the exact counter only will be removed
     *
     * @access public
     * @return
     * @throws StateInvalidException
     */
    public function CleanStates($devid, $type, $key, $counter = false, $thisCounterOnly = false) {
        if (!$this->stateFolder) {
            $this->getStateFolder($devid);
            if (!$this->stateFolder) {
                throw new StateNotFoundException(sprintf("BackendGrommunio->getStateMessage(): Could not locate the state folder for device '%s'",
                $devid));
            }
        }
        $messageName = rtrim((($key !== false) ? $key."-" : "") . (($type !== "") ? $type : ""), "-");
        $restriction = $this->getStateMessageRestriction($messageName, $counter, $thisCounterOnly);
        $stateFolderContents = mapi_folder_getcontentstable($this->stateFolder, MAPI_ASSOCIATED);
        if ($stateFolderContents) {
            mapi_table_restrict($stateFolderContents, $restriction);
            $rowCnt = mapi_table_getrowcount($stateFolderContents);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->CleanStates(): Found %d states to clean (%s)", $rowCnt, $messageName));
            if ($rowCnt > 0) {
                $rows = mapi_table_queryallrows($stateFolderContents, [PR_ENTRYID]);
                $entryids = [];
                foreach($rows as $row) {
                    $entryids[] = $row[PR_ENTRYID];
                }
                mapi_folder_deletemessages($this->stateFolder, $entryids, DELETE_HARD_DELETE);
            }
        }
    }

    /**
     * Links a user to a device
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return boolean     indicating if the user was added or not (existed already)
     */
    public function LinkUserDevice($username, $devid) {
        $device = [$devid => time()];
        $this->setDeviceUserData($this->type, $device, $username, -1, $subkey=-1, $doCas="merge");
        return false;
    }

    /**
     * Unlinks a device from a user
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return boolean
     */
    public function UnLinkUserDevice($username, $devid) {
        //TODO: Implement
        return false;
    }

    /**
     * Returns the current version of the state files
     * grommunio:  This is not relevant atm. IStateMachine::STATEVERSION_02 will match ZPush::GetLatestStateVersion().
     *          If it might be required to update states in the future, this could be implemented on a store level,
     *          where states are then migrated "on-the-fly"
     *          or
     *          in a global settings where all states in all stores are migrated once.
     *
     * @access public
     * @return int
     */
    public function GetStateVersion() {
        return IStateMachine::STATEVERSION_02;
    }

    /**
     * Sets the current version of the state files
     *
     * @param int       $version            the new supported version
     *
     * @access public
     * @return boolean
     */
    public function SetStateVersion($version) {
        return true;
    }

    /**
     * Returns all available states for a device id
     *
     * @param string    $devid              the device id
     *
     * @access public
     * @return array(mixed)
     */
    public function GetAllStatesForDevice($devid) {

    }

    /**
     * Returns MAPIFolder object which contains the state information.
     * Creates this folder if it is not available yet.
     *
     * @param string    $devid              the device id
     *
     * @access private
     * @return MAPIFolder
     */
    private function getStateFolder($devid) {
        // Options request doesn't send device id
        if (strlen($devid) == 0) {
            return false;
        }
        // Try to get the state folder id from redis
        if (!$this->stateFolder) {
            $folderentryid = $this->getDeviceUserData($this->userDeviceData, $devid, $this->mainUser, "statefolder");
            if ($folderentryid) {
                $this->stateFolder = mapi_msgstore_openentry($this->store, hex2bin($folderentryid));
            }
        }

        // fallback code
        if (!$this->stateFolder) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("BackendGrommunio->getStateFolder(): state folder not set. Use fallback"));
            $rootfolder = mapi_msgstore_openentry($this->store);
            $hierarchy = mapi_folder_gethierarchytable($rootfolder, CONVENIENT_DEPTH);
            $restriction = $this->getStateFolderRestriction($devid);
            // restrict the hierarchy to the grommunio-sync search folder only
            mapi_table_restrict($hierarchy, $restriction);
            $rowCnt = mapi_table_getrowcount($hierarchy);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->getStateFolder(): found %d device state folders", $rowCnt));
            if ($rowCnt == 1) {
                $hierarchyRows = mapi_table_queryrows($hierarchy, [PR_ENTRYID], 0, 1);
                $this->stateFolder = mapi_msgstore_openentry($this->store, $hierarchyRows[0][PR_ENTRYID]);
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->getStateFolder(): %s", bin2hex($hierarchyRows[0][PR_ENTRYID])));
                // put found id in redis
                if ($devid) {
                    $this->setDeviceUserData($this->userDeviceData, bin2hex($hierarchyRows[0][PR_ENTRYID]), $devid, $this->mainUser, "statefolder");
                }
            }
            elseif ($rowCnt == 0) {
                // legacy code: create the hidden state folder and the device subfolder
                // this should happen when the user configures the device (autodiscover or first sync if no autodiscover)

                $hierarchy = mapi_folder_gethierarchytable($rootfolder, CONVENIENT_DEPTH);
                $restriction = $this->getStateFolderRestriction(STORE_STATE_FOLDER);
                mapi_table_restrict($hierarchy, $restriction);
                $rowCnt = mapi_table_getrowcount($hierarchy);
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->getStateFolder(): found %d store state folders", $rowCnt));
                if ($rowCnt == 1) {
                    $hierarchyRows = mapi_table_queryrows($hierarchy, [PR_ENTRYID], 0, 1);
                    $stateFolder = mapi_msgstore_openentry($this->store, $hierarchyRows[0][PR_ENTRYID]);
                }
                elseif ($rowCnt == 0) {
                    $stateFolder = mapi_folder_createfolder($rootfolder, STORE_STATE_FOLDER, "");
                    mapi_setprops($stateFolder, array(PR_ATTR_HIDDEN => true));
                }
                else {
                    // TODO: handle this
                }
                if (isset($stateFolder) && $stateFolder) {
                    $devStateFolder = mapi_folder_createfolder($stateFolder, $devid, "");
                    $devStateFolderProps = mapi_getprops($devStateFolder);
                    $this->stateFolder = mapi_msgstore_openentry($this->store, $devStateFolderProps[PR_ENTRYID]);
                    mapi_setprops($this->stateFolder, array(PR_ATTR_HIDDEN => true));
                    // we don't cache the entryid in redis, because this will happen on the next request anyway
                }
                else {
                    // TODO: unable to create state folder - throw exception
                }
            }
            else {
                // This case is rather unlikely that there would be several
                // hidden folders having PR_DISPLAY_NAME the same as device id.

                // TODO: get the hierarchy table again, get entry id of STORE_STATE_FOLDER
                // and compare it to the parent id of those folders.
            }
        }
        return $this->stateFolder;
    }

    /**
     * Returns the associated MAPIMessage which contains the state information.
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param string    $counter            state counter

     *
     * @access private
     * @return MAPIMessage
     * @throws StateNotFoundException
     */
    private function getStateMessage($devid, $type, $key, $counter) {
        if (!$this->stateFolder) {
            $this->getStateFolder(Request::GetDeviceID());
            if (!$this->stateFolder) {
                throw new StateNotFoundException(sprintf("BackendGrommunio->getStateMessage(): Could not locate the state folder for device '%s'",
                $devid));
            }
        }
        $messageName = rtrim((($key !== false) ? $key."-" : "") . (($type !== "") ? $type : ""), "-");
        $restriction = $this->getStateMessageRestriction($messageName, $counter, true);
        $stateFolderContents = mapi_folder_getcontentstable($this->stateFolder, MAPI_ASSOCIATED);
        if ($stateFolderContents) {
            mapi_table_restrict($stateFolderContents, $restriction);
            $rowCnt = mapi_table_getrowcount($stateFolderContents);
            if ($rowCnt == 1) {
                $stateFolderRows = mapi_table_queryrows($stateFolderContents, [PR_ENTRYID], 0, 1);
                return mapi_msgstore_openentry($this->store, $stateFolderRows[0][PR_ENTRYID]);
            }
            elseif($rowCnt > 1) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->getStateMessage(): Found several (%d) states for '%s'", $rowCnt, $messageName));
            }
        }
        throw new StateNotFoundException(sprintf("BackendGrommunio->getStateMessage(): Could not locate the state message '%s-%s'",
            $messageName, Utils::PrintAsString($counter)));
    }

    /**
     * Writes ta state to for a key and counter
     *
     * @param mixed     $state
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param int       $counter            (opt)
     *
     * @access public
     * @return boolean
     * @throws StateInvalidException, UnavailableException
     */
    private function setStateMessage($state, $devid, $type, $key = false, $counter = false) {
        if (!$this->stateFolder) {
            throw new StateNotFoundException(sprintf("BackendGrommunio->setStateMessage(): Could not locate the state folder for device '%s'", $devid));
        }
        try {
            $stateMessage = $this->getStateMessage($devid, $type, $key, $counter);
        }
        catch (StateNotFoundException $e) {
            // if message is not available, try to create a new one
            $stateMessage = mapi_folder_createmessage($this->stateFolder, MAPI_ASSOCIATED);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->setStateMessage(): mapi_folder_createmessage 0x%08X", mapi_last_hresult()));

            $messageName = rtrim((($key !== false) ? $key."-" : "") . (($type !== "") ? $type : ""), "-");
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->setStateMessage(): creating new state message '%s-%d'", $messageName, is_int($counter) ? $counter : 0));
            mapi_setprops($stateMessage, [PR_DISPLAY_NAME => $messageName, PR_MESSAGE_CLASS => 'IPM.Note.GrommunioState']);
        }
        if (isset($stateMessage)) {
            $jsonEncodedState = is_object($state) || is_array($state) ? json_encode($state, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE) : $state;

            $encodedState = base64_encode($jsonEncodedState);
            $encodedStateLength = strlen($encodedState);
            mapi_setprops($stateMessage, [PR_LAST_VERB_EXECUTED => is_int($counter) ? $counter : 0]);
            $stream = mapi_openproperty($stateMessage, PR_BODY, IID_IStream, STGM_DIRECT, MAPI_CREATE | MAPI_MODIFY);
            mapi_stream_setsize($stream, $encodedStateLength);
            mapi_stream_write($stream, $encodedState);
            mapi_stream_commit($stream);
            mapi_savechanges($stateMessage);

            return $encodedStateLength;
        }
        return false;
    }

    /**
     * Returns the restriction for the state folder name.
     *
     * @param string    $folderName         the state folder name
     *
     * @access private
     * @return array
     */
    private function getStateFolderRestriction($folderName) {
        return [RES_AND, [
            [   RES_PROPERTY,
                [   RELOP => RELOP_EQ,
                    ULPROPTAG => PR_DISPLAY_NAME,
                    VALUE => $folderName
                ],
            ],
            [   RES_PROPERTY,
                [   RELOP => RELOP_EQ,
                    ULPROPTAG => PR_ATTR_HIDDEN,
                    VALUE => true
                ],
            ]
        ]];
    }

    /**
     * Returns the restriction for the associated message in the state folder.
     *
     * @param string    $messageName        the message name
     * @param string    $counter            counter
     * @param string    $thisCounterOnly    (opt) if provided, restrict to the exact counter
     *
     * @access private
     * @return array
     */
    private function getStateMessageRestriction($messageName, $counter, $thisCounterOnly = false) {
        return [RES_AND, [
            [   RES_PROPERTY,
                [   RELOP => RELOP_EQ,
                    ULPROPTAG => PR_DISPLAY_NAME,
                    VALUE => $messageName
                ],
            ],
            [   RES_PROPERTY,
                [   RELOP => RELOP_EQ,
                    ULPROPTAG => PR_MESSAGE_CLASS,
                    VALUE => 'IPM.Note.GrommunioState'
                ],
            ],
            [   RES_PROPERTY,
                [   RELOP => $thisCounterOnly ? RELOP_EQ : RELOP_LT,
                    ULPROPTAG => PR_LAST_VERB_EXECUTED,
                    VALUE => $counter
                ],
            ]
        ]];
    }

    /**----------------------------------------------------------------------------------------------------------
     * Private methods
     */

    /**
     * Returns a hash representing changes in the hierarchy of the main user.
     * It changes if a folder is added, renamed or deleted.
     *
     * @access private
     * @return string
     */
    private function getHierarchyHash() {
        $rootfolder = mapi_msgstore_openentry($this->defaultstore);
        $hierarchy =  mapi_folder_gethierarchytable($rootfolder, CONVENIENT_DEPTH);
        return md5(serialize(mapi_table_queryallrows($hierarchy, array(PR_DISPLAY_NAME, PR_PARENT_ENTRYID))));
    }


    /**
     * Advises a store to the changes sink
     *
     * @param mapistore $store              store to be advised
     *
     * @access private
     * @return boolean
     */
    private function adviseStoreToSink($store) {
        // check if we already advised the store
        if (!in_array($store, $this->changesSinkStores)) {
            mapi_msgstore_advise($store, null, fnevObjectModified | fnevObjectCreated | fnevObjectMoved | fnevObjectDeleted, $this->changesSink);

            if (mapi_last_hresult()) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->adviseStoreToSink(): failed to advised store '%s' with code 0x%X. Polling will be performed.", $store, mapi_last_hresult()));
                return false;
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->adviseStoreToSink(): advised store '%s'", $store));
                $this->changesSinkStores[] = $store;
            }
        }
        return true;
    }

    /**
     * Open the store marked with PR_DEFAULT_STORE = TRUE
     * if $return_public is set, the public store is opened
     *
     * @param string    $user               User which store should be opened
     *
     * @access public
     * @return boolean
     */
    private function openMessageStore($user) {
        // During PING requests the operations store has to be switched constantly
        // the cache prevents the same store opened several times
        if (isset($this->storeCache[$user]))
           return  $this->storeCache[$user];

        $entryid = false;
        $return_public = false;

        if (strtoupper($user) == 'SYSTEM')
            $return_public = true;

        // loop through the storestable if authenticated user of public folder
        if ($user == $this->mainUser || $return_public === true) {
            // Find the default store
            $storestables = mapi_getmsgstorestable($this->session);
            $result = mapi_last_hresult();

            if ($result == NOERROR){
                $rows = mapi_table_queryallrows($storestables, array(PR_ENTRYID, PR_DEFAULT_STORE, PR_MDB_PROVIDER));

                foreach($rows as $row) {
                    if(!$return_public && isset($row[PR_DEFAULT_STORE]) && $row[PR_DEFAULT_STORE] == true) {
                        $entryid = $row[PR_ENTRYID];
                        break;
                    }
                    if ($return_public && isset($row[PR_MDB_PROVIDER]) && $row[PR_MDB_PROVIDER] == ZARAFA_STORE_PUBLIC_GUID) {
                        $entryid = $row[PR_ENTRYID];
                        break;
                    }
                }
            }
        }
        else
            $entryid = @mapi_msgstore_createentryid($this->defaultstore, $user);

        if($entryid) {
            $store = @mapi_openmsgstore($this->session, $entryid);

            if (!$store) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->openMessageStore('%s'): Could not open store", $user));
                return false;
            }

            // add this store to the cache
            if (!isset($this->storeCache[$user]))
                $this->storeCache[$user] = $store;

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->openMessageStore('%s'): Found '%s' store: '%s'", $user, (($return_public)?'PUBLIC':'DEFAULT'),$store));
            return $store;
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->openMessageStore('%s'): No store found for this user", $user));
            return false;
        }
    }

    /**
     * Checks if the logged in user has secretary permissions on a folder.
     *
     * @param ressource $store
     * @param string $folderid
     *
     * @access public
     * @return boolean
     */
    public function HasSecretaryACLs($store, $folderid, $entryid = false) {
        if (!$entryid) {
            $entryid = mapi_msgstore_entryidfromsourcekey($store, hex2bin($folderid));
            if (!$entryid) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->HasSecretaryACLs(): error, no entryid resolved for %s on store %s", $folderid, $store));
                return false;
            }
        }

        $folder = mapi_msgstore_openentry($store, $entryid);
        if (!$folder) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->HasSecretaryACLs(): error, could not open folder with entryid %s on store %s", bin2hex($entryid), $store));
            return false;
        }

        $props = mapi_getprops($folder, array(PR_RIGHTS));
        if (isset($props[PR_RIGHTS]) &&
            ($props[PR_RIGHTS] & ecRightsReadAny) &&
            ($props[PR_RIGHTS] & ecRightsCreate) &&
            ($props[PR_RIGHTS] & ecRightsEditOwned) &&
            ($props[PR_RIGHTS] & ecRightsDeleteOwned) &&
            ($props[PR_RIGHTS] & ecRightsEditAny) &&
            ($props[PR_RIGHTS] & ecRightsDeleteAny) &&
            ($props[PR_RIGHTS] & ecRightsFolderVisible) ) {
            return true;
        }
        return false;
    }

    /**
     * The meta function for out of office settings.
     *
     * @param SyncObject $oof
     *
     * @access private
     * @return void
     */
    private function settingsOOF(&$oof) {
        //if oof state is set it must be set of oof and get otherwise
        if (isset($oof->oofstate)) {
            $this->settingsOofSet($oof);
        }
        else {
            $this->settingsOofGet($oof);
        }
    }

    /**
     * Gets the out of office settings
     *
     * @param SyncObject $oof
     *
     * @access private
     * @return void
     */
    private function settingsOofGet(&$oof) {
        $oofprops = mapi_getprops($this->defaultstore, array(PR_EC_OUTOFOFFICE, PR_EC_OUTOFOFFICE_MSG, PR_EC_OUTOFOFFICE_SUBJECT, PR_EC_OUTOFOFFICE_FROM, PR_EC_OUTOFOFFICE_UNTIL));
        $oof->oofstate = SYNC_SETTINGSOOF_DISABLED;
        $oof->Status = SYNC_SETTINGSSTATUS_SUCCESS;
        if ($oofprops != false) {
            $oof->oofstate = isset($oofprops[PR_EC_OUTOFOFFICE]) ? ($oofprops[PR_EC_OUTOFOFFICE] ? SYNC_SETTINGSOOF_GLOBAL : SYNC_SETTINGSOOF_DISABLED) : SYNC_SETTINGSOOF_DISABLED;
            //TODO external and external unknown
            $oofmessage = new SyncOOFMessage();
            $oofmessage->appliesToInternal = "";
            $oofmessage->enabled = $oof->oofstate;
            $oofmessage->replymessage = (isset($oofprops[PR_EC_OUTOFOFFICE_MSG])) ? w2u($oofprops[PR_EC_OUTOFOFFICE_MSG]) : "";
            $oofmessage->bodytype = $oof->bodytype;
            unset($oofmessage->appliesToExternal, $oofmessage->appliesToExternalUnknown);
            $oof->oofmessage[] = $oofmessage;

            // check whether time based out of office is set
            if ($oof->oofstate == SYNC_SETTINGSOOF_GLOBAL && isset($oofprops[PR_EC_OUTOFOFFICE_FROM], $oofprops[PR_EC_OUTOFOFFICE_UNTIL])) {
                $now = time();
                if ($now > $oofprops[PR_EC_OUTOFOFFICE_FROM] && $now > $oofprops[PR_EC_OUTOFOFFICE_UNTIL]) {
                    // Out of office is set but the date is in the past. Set the state to disabled.
                    // @see https://jira.z-hub.io/browse/ZP-1188 for details
                    $oof->oofstate = SYNC_SETTINGSOOF_DISABLED;
                    @mapi_setprops($this->defaultstore, array(PR_EC_OUTOFOFFICE => false));
                    @mapi_deleteprops($this->defaultstore, array(PR_EC_OUTOFOFFICE_FROM, PR_EC_OUTOFOFFICE_UNTIL));
                    ZLog::Write(LOGLEVEL_INFO, "BackendGrommunio->settingsOofGet(): Out of office is set but the from and until are in the past. Disabling out of office.");
                }
                elseif ($oofprops[PR_EC_OUTOFOFFICE_FROM] < $oofprops[PR_EC_OUTOFOFFICE_UNTIL]) {
                    $oof->oofstate = SYNC_SETTINGSOOF_TIMEBASED;
                    $oof->starttime = $oofprops[PR_EC_OUTOFOFFICE_FROM];
                    $oof->endtime = $oofprops[PR_EC_OUTOFOFFICE_UNTIL];
                }
                else {
                    ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->settingsOofGet(): Time based out of office set but end time ('%s') is before startime ('%s').",
                        date("Y-m-d H:i:s", $oofprops[PR_EC_OUTOFOFFICE_FROM]), date("Y-m-d H:i:s", $oofprops[PR_EC_OUTOFOFFICE_UNTIL])));
                    $oof->Status = SYNC_SETTINGSSTATUS_PROTOCOLLERROR;
                }
            }
            elseif ($oof->oofstate == SYNC_SETTINGSOOF_GLOBAL && (isset($oofprops[PR_EC_OUTOFOFFICE_FROM]) || isset($oofprops[PR_EC_OUTOFOFFICE_UNTIL]))) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->settingsOofGet(): Time based out of office set but either start time ('%s') or end time ('%s') is missing.",
                    (isset($oofprops[PR_EC_OUTOFOFFICE_FROM]) ? date("Y-m-d H:i:s", $oofprops[PR_EC_OUTOFOFFICE_FROM]) : 'empty'),
                    (isset($oofprops[PR_EC_OUTOFOFFICE_UNTIL]) ? date("Y-m-d H:i:s", $oofprops[PR_EC_OUTOFOFFICE_UNTIL]) : 'empty')));
                $oof->Status = SYNC_SETTINGSSTATUS_PROTOCOLLERROR;
            }
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, "BackendGrommunio->Unable to get out of office information");
        }

        //unset body type for oof in order not to stream it
        unset($oof->bodytype);
    }

    /**
     * Sets the out of office settings.
     *
     * @param SyncObject $oof
     *
     * @access private
     * @return void
     */
    private function settingsOofSet(&$oof) {
        $oof->Status = SYNC_SETTINGSSTATUS_SUCCESS;
        $props = array();
        if ($oof->oofstate == SYNC_SETTINGSOOF_GLOBAL || $oof->oofstate == SYNC_SETTINGSOOF_TIMEBASED) {
            $props[PR_EC_OUTOFOFFICE] = true;
            foreach ($oof->oofmessage as $oofmessage) {
                if (isset($oofmessage->appliesToInternal)) {
                    $props[PR_EC_OUTOFOFFICE_MSG] = isset($oofmessage->replymessage) ? u2w($oofmessage->replymessage) : "";
                    $props[PR_EC_OUTOFOFFICE_SUBJECT] = "Out of office";
                }
            }
            if ($oof->oofstate == SYNC_SETTINGSOOF_TIMEBASED) {
                if(isset($oof->starttime) && isset($oof->endtime)) {
                    $props[PR_EC_OUTOFOFFICE_FROM] = $oof->starttime;
                    $props[PR_EC_OUTOFOFFICE_UNTIL] = $oof->endtime;
                }
                elseif (isset($oof->starttime) || isset($oof->endtime)) {
                    $oof->Status = SYNC_SETTINGSSTATUS_PROTOCOLLERROR;
                }
            }
            else {
                $deleteProps = array(PR_EC_OUTOFOFFICE_FROM, PR_EC_OUTOFOFFICE_UNTIL);
            }
        }
        elseif($oof->oofstate == SYNC_SETTINGSOOF_DISABLED) {
            $props[PR_EC_OUTOFOFFICE] = false;
            $deleteProps = array(PR_EC_OUTOFOFFICE_FROM, PR_EC_OUTOFOFFICE_UNTIL);
        }

        if (!empty($props)) {
            @mapi_setprops($this->defaultstore, $props);
            $result = mapi_last_hresult();
            if ($result != NOERROR) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendGrommunio->settingsOofSet(): Setting oof information failed (%X)", $result));
                return false;
            }
        }

        if (!empty($deleteProps)) {
            @mapi_deleteprops($this->defaultstore, $deleteProps);
        }

        return true;
    }

    /**
     * Gets the user's email address from server
     *
     * @param SyncObject $userinformation
     *
     * @access private
     * @return void
     */
    private function settingsUserInformation(&$userinformation) {
        if (!isset($this->defaultstore) || !isset($this->mainUser)) {
            ZLog::Write(LOGLEVEL_ERROR, "BackendGrommunio->settingsUserInformation(): The store or user are not available for getting user information");
            return false;
        }
        $user = nsp_getuserinfo($this->mainUser);
        if ($user != false) {
            $userinformation->Status = SYNC_SETTINGSSTATUS_USERINFO_SUCCESS;
            if (Request::GetProtocolVersion() >= 14.1) {
                $account = new SyncAccount();
                $emailaddresses = new SyncEmailAddresses();
                $emailaddresses->smtpaddress[] = $user["primary_email"];
                $emailaddresses->primarysmtpaddress = $user["primary_email"];
                $account->emailaddresses = $emailaddresses;
                $userinformation->accounts[] = $account;
            }
            else {
                $userinformation->emailaddresses[] = $user["primary_email"];
            }
            return true;
        }
        ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendGrommunio->settingsUserInformation(): Getting user information failed: nsp_getuserinfo(%X)", mapi_last_hresult()));
        return false;
    }

    /**
     * Gets the rights management templates from the server.
     *
     * @param SyncObject $rmTemplates
     *
     * @access private
     * @return void
     */
    private function settingsRightsManagementTemplates(&$rmTemplates) {
        /* Currently there is no information rights management feature in
         * the grommunio backend, so just return the status and empty
         * SyncRightsManagementTemplates tag.
         * Once it's available, it would be something like:

        $rmTemplate = new SyncRightsManagementTemplate();
        $rmTemplate->id = "some-template-id-eg-guid";
        $rmTemplate->name = "Template name";
        $rmTemplate->description = "What does the template do. E.g. it disables forward and reply.";
        $rmTemplates->rmtemplates[] = $rmTemplate;
         */
        $rmTemplates->Status = SYNC_COMMONSTATUS_IRMFEATUREDISABLED;
        $rmTemplates->rmtemplates = array();
    }

    /**
     * Sets the importance and priority of a message from a RFC822 message headers.
     *
     * @param int $xPriority
     * @param array $mapiprops
     *
     * @return void
     */
    private function getImportanceAndPriority($xPriority, &$mapiprops, $sendMailProps) {
        switch($xPriority) {
            case 1:
            case 2:
                $priority = PRIO_URGENT;
                $importance = IMPORTANCE_HIGH;
                break;
            case 4:
            case 5:
                $priority = PRIO_NONURGENT;
                $importance = IMPORTANCE_LOW;
                break;
            case 3:
            default:
                $priority = PRIO_NORMAL;
                $importance = IMPORTANCE_NORMAL;
                break;
        }
        $mapiprops[$sendMailProps["importance"]] = $importance;
        $mapiprops[$sendMailProps["priority"]] = $priority;
    }

    /**
     * Copies attachments from one message to another.
     *
     * @param MAPIMessage $toMessage
     * @param MAPIMessage $fromMessage
     *
     * @return void
     */
    private function copyAttachments(&$toMessage, $fromMessage) {
        $attachtable = mapi_message_getattachmenttable($fromMessage);
        $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM));

        foreach($rows as $row) {
            if(isset($row[PR_ATTACH_NUM])) {
                $attach = mapi_message_openattach($fromMessage, $row[PR_ATTACH_NUM]);
                $newattach = mapi_message_createattach($toMessage);
                mapi_copyto($attach, array(), array(), $newattach, 0);
                mapi_savechanges($newattach);
            }
        }
    }

   /**
    * Function will create a search folder in FINDER_ROOT folder
    * if folder exists then it will open it
    *
    * @see createSearchFolder($store, $openIfExists = true) function in the webaccess
    *
    * @return mapiFolderObject $folder created search folder
    */
    private function getSearchFolder() {
        // create new or open existing search folder
        $searchFolderRoot = $this->getSearchFoldersRoot($this->store);
        if($searchFolderRoot === false) {
            // error in finding search root folder
            // or store doesn't support search folders
            return false;
        }

        $searchFolder = $this->createSearchFolder($searchFolderRoot);

        if($searchFolder !== false && mapi_last_hresult() == NOERROR) {
            return $searchFolder;
        }
        return false;
    }

   /**
    * Function will open FINDER_ROOT folder in root container
    * public folder's don't have FINDER_ROOT folder
    *
    * @see getSearchFoldersRoot($store) function in the webaccess
    *
    * @return mapiFolderObject root folder for search folders
    */
    private function getSearchFoldersRoot() {
        // check if we can create search folders
        $storeProps = mapi_getprops($this->store, array(PR_STORE_SUPPORT_MASK, PR_FINDER_ENTRYID));
        if(($storeProps[PR_STORE_SUPPORT_MASK] & STORE_SEARCH_OK) != STORE_SEARCH_OK) {
            ZLog::Write(LOGLEVEL_WARN, "BackendGrommunio->getSearchFoldersRoot(): Store doesn't support search folders. Public store doesn't have FINDER_ROOT folder");
            return false;
        }

        // open search folders root
        $searchRootFolder = mapi_msgstore_openentry($this->store, $storeProps[PR_FINDER_ENTRYID]);
        if(mapi_last_hresult() != NOERROR) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->getSearchFoldersRoot(): Unable to open search folder (0x%X)", mapi_last_hresult()));
            return false;
        }

        return $searchRootFolder;
    }


    /**
     * Creates a search folder if it not exists or opens an existing one
     * and returns it.
     *
     * @param mapiFolderObject $searchFolderRoot
     *
     * @return mapiFolderObject
     */
    private function createSearchFolder($searchFolderRoot) {
        $folderName = "grommunio-sync Search Folder ".@getmypid();
        $searchFolders = mapi_folder_gethierarchytable($searchFolderRoot);
        $restriction = array(
            RES_CONTENT,
            array(
                    FUZZYLEVEL      => FL_PREFIX,
                    ULPROPTAG       => PR_DISPLAY_NAME,
                    VALUE           => array(PR_DISPLAY_NAME=>$folderName)
            )
        );
        //restrict the hierarchy to the grommunio-sync search folder only
        mapi_table_restrict($searchFolders, $restriction);
        if (mapi_table_getrowcount($searchFolders)) {
            $searchFolder = mapi_table_queryrows($searchFolders, array(PR_ENTRYID), 0, 1);

            return mapi_msgstore_openentry($this->store, $searchFolder[0][PR_ENTRYID]);
        }
        return mapi_folder_createfolder($searchFolderRoot, $folderName, null, 0, FOLDER_SEARCH);
    }

    /**
     * Creates a search restriction
     *
     * @param ContentParameter $cpo
     * @return array
     */
    private function getSearchRestriction($cpo) {
        $searchText = $cpo->GetSearchFreeText();

        $searchGreater = strtotime($cpo->GetSearchValueGreater());
        $searchLess = strtotime($cpo->GetSearchValueLess());

        if (version_compare(phpversion(),'5.3.4') < 0) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->getSearchRestriction(): Your system's PHP version (%s) might not correctly process unicode strings. Search containing such characters might not return correct results. It is recommended to update to at least PHP 5.3.4. See ZP-541 for more information.", phpversion()));
        }
        // split the search on whitespache and look for every word
        $searchText = preg_split("/\W+/u", $searchText);
        $searchProps = array(PR_BODY, PR_SUBJECT, PR_DISPLAY_TO, PR_DISPLAY_CC, PR_SENDER_NAME, PR_SENDER_EMAIL_ADDRESS, PR_SENT_REPRESENTING_NAME, PR_SENT_REPRESENTING_EMAIL_ADDRESS);
        $resAnd = array();
        foreach($searchText as $term) {
            $resOr = array();

            foreach($searchProps as $property) {
                array_push($resOr,
                    array(RES_CONTENT,
                        array(
                            FUZZYLEVEL => FL_SUBSTRING|FL_IGNORECASE,
                            ULPROPTAG => $property,
                            VALUE => u2w($term)
                        )
                    )
                );
            }
            array_push($resAnd, array(RES_OR, $resOr));
        }

        // add time range restrictions
        if ($searchGreater) {
            array_push($resAnd, array(RES_PROPERTY, array(RELOP => RELOP_GE, ULPROPTAG => PR_MESSAGE_DELIVERY_TIME, VALUE => array(PR_MESSAGE_DELIVERY_TIME => $searchGreater)))); // RES_AND;
        }
        if ($searchLess) {
            array_push($resAnd, array(RES_PROPERTY, array(RELOP => RELOP_LE, ULPROPTAG => PR_MESSAGE_DELIVERY_TIME, VALUE => array(PR_MESSAGE_DELIVERY_TIME => $searchLess))));
        }
        $mapiquery = array(RES_AND, $resAnd);

        return $mapiquery;
    }

    /**
     * Resolve recipient based on his email address.
     *
     * @param string $to
     * @param int $maxAmbiguousRecipients
     * @param boolean $expandDistlist
     *
     * @return SyncResolveRecipient|boolean
     */
    private function resolveRecipient($to, $maxAmbiguousRecipients, $expandDistlist = true) {
        $recipient = $this->resolveRecipientGAL($to, $maxAmbiguousRecipients, $expandDistlist);

        if ($recipient !== false) {
            return $recipient;
        }

        $recipient = $this->resolveRecipientContact($to, $maxAmbiguousRecipients);

        if ($recipient !== false) {
            return $recipient;
        }

        return false;
    }

    /**
     * Resolves recipient from the GAL and gets his certificates.
     *
     * @param string $to
     * @param int $maxAmbiguousRecipients
     * @param boolean $expandDistlist
     * @return array|boolean
     */
    private function resolveRecipientGAL($to, $maxAmbiguousRecipients, $expandDistlist = true) {
        ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendGrommunio->resolveRecipientGAL(): Resolving recipient '%s' in GAL", $to));
        $addrbook = $this->getAddressbook();
        // FIXME: create a function to get the adressbook contentstable
        $ab_entryid = mapi_ab_getdefaultdir($addrbook);
        if ($ab_entryid)
            $ab_dir = mapi_ab_openentry($addrbook, $ab_entryid);
        if ($ab_dir)
            $table = mapi_folder_getcontentstable($ab_dir);

        if (!$table) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->resolveRecipientGAL(): Unable to open addressbook:0x%X", mapi_last_hresult()));
            return false;
        }

        $restriction = MAPIUtils::GetSearchRestriction(u2w($to));
        mapi_table_restrict($table, $restriction);

        $querycnt = mapi_table_getrowcount($table);
        if ($querycnt > 0) {
            $recipientGal = array();
            $rowsToQuery = $maxAmbiguousRecipients;
            // some devices request 0 ambiguous recipients
            if ($querycnt == 1 && $maxAmbiguousRecipients == 0) {
                $rowsToQuery = 1;
            }
            elseif ($querycnt > 1 && $maxAmbiguousRecipients == 0) {
                ZLog::Write(LOGLEVEL_INFO, sprintf("BackendGrommunio->resolveRecipientGAL(): GAL search found %d recipients but the device hasn't requested ambiguous recipients", $querycnt));
                return $recipientGal;
            }
            elseif ($querycnt > 1 && $maxAmbiguousRecipients == 1) {
                $rowsToQuery = $querycnt;
            }
            // get the certificate every time because caching the certificate is less expensive than opening addressbook entry again
            $abentries = mapi_table_queryrows($table, array(PR_ENTRYID, PR_DISPLAY_NAME, PR_EMS_AB_TAGGED_X509_CERT, PR_OBJECT_TYPE, PR_SMTP_ADDRESS), 0, $rowsToQuery);
            for ($i = 0, $nrEntries = count($abentries); $i < $nrEntries; $i++) {
                if (strcasecmp($abentries[$i][PR_SMTP_ADDRESS], $to) !== 0 && $maxAmbiguousRecipients == 1) {
                    ZLog::Write(LOGLEVEL_INFO, sprintf("BackendGrommunio->resolveRecipientGAL(): maxAmbiguousRecipients is 1 and found non-matching user (to '%s' found: '%s')", $to, $abentries[$i][PR_SMTP_ADDRESS]));
                    continue;
                }
                if ($abentries[$i][PR_OBJECT_TYPE] == MAPI_DISTLIST) {
                    // check whether to expand dist list
                    if ($expandDistlist) {
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->resolveRecipientGAL(): '%s' is a dist list. Expand it to members.", $to));
                        $distList = mapi_ab_openentry($addrbook, $abentries[$i][PR_ENTRYID]);
                        $distListContent = mapi_folder_getcontentstable($distList);
                        $distListMembers = mapi_table_queryallrows($distListContent, array(PR_ENTRYID, PR_DISPLAY_NAME, PR_EMS_AB_TAGGED_X509_CERT));
                        for ($j = 0, $nrDistListMembers = mapi_table_getrowcount($distListContent); $j < $nrDistListMembers; $j++) {
                            ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendGrommunio->resolveRecipientGAL(): distlist's '%s' member", $to, $distListMembers[$j][PR_DISPLAY_NAME]));
                            $recipientGal[] = $this->createResolveRecipient(SYNC_RESOLVERECIPIENTS_TYPE_GAL, $to, $distListMembers[$j], $nrDistListMembers);
                        }
                    }
                    else {
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGrommunio->resolveRecipientGAL(): '%s' is a dist list, but return it as is.", $to));
                        $recipientGal[] = $this->createResolveRecipient(SYNC_RESOLVERECIPIENTS_TYPE_GAL, $abentries[$i][PR_SMTP_ADDRESS], $abentries[$i]);
                    }
                }
                elseif ($abentries[$i][PR_OBJECT_TYPE] == MAPI_MAILUSER) {
                    $recipientGal[] = $this->createResolveRecipient(SYNC_RESOLVERECIPIENTS_TYPE_GAL, $abentries[$i][PR_SMTP_ADDRESS], $abentries[$i]);
                }
            }

            ZLog::Write(LOGLEVEL_WBXML, "BackendGrommunio->resolveRecipientGAL(): Found a recipient in GAL");
            return $recipientGal;
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->resolveRecipientGAL(): No recipient found for: '%s' in GAL", $to));
            return SYNC_RESOLVERECIPSSTATUS_RESPONSE_UNRESOLVEDRECIP;
        }
        return false;
    }

    /**
     * Resolves recipient from the contact list and gets his certificates.
     *
     * @param string $to
     * @param int $maxAmbiguousRecipients
     *
     * @return array|boolean
     */
    private function resolveRecipientContact($to, $maxAmbiguousRecipients) {
        ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendGrommunio->resolveRecipientContact(): Resolving recipient '%s' in user's contacts", $to));
        // go through all contact folders of the user and
        // check if there's a contact with the given email address
        $root = mapi_msgstore_openentry($this->defaultstore);
        if (!$root) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendGrommunio->resolveRecipientContact(): Unable to open default store: 0x%X", mapi_last_hresult()));
        }
        $rootprops = mapi_getprops($root, array(PR_IPM_CONTACT_ENTRYID));
        $contacts = $this->getContactsFromFolder($this->defaultstore, $rootprops[PR_IPM_CONTACT_ENTRYID], $to);
        $recipients = array();

        if ($contacts !== false) {
            ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendGrommunio->resolveRecipientContact(): Found %d contacts in main contacts folder.", count($contacts)));
            // create resolve recipient object
            foreach ($contacts as $contact) {
                $recipients[] = $this->createResolveRecipient(SYNC_RESOLVERECIPIENTS_TYPE_CONTACT, $to, $contact);
            }
        }

        $contactfolder = mapi_msgstore_openentry($this->defaultstore, $rootprops[PR_IPM_CONTACT_ENTRYID]);
        $subfolders = MAPIUtils::GetSubfoldersForType($contactfolder, "IPF.Contact");
        if ($subfolders !== false) {
            foreach($subfolders as $folder) {
                $contacts = $this->getContactsFromFolder($this->defaultstore, $folder[PR_ENTRYID], $to);
                if ($contacts !== false) {
                    ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendGrommunio->resolveRecipientContact(): Found %d contacts in contacts' subfolder.", count($contacts)));
                    foreach ($contacts as $contact) {
                        $recipients[] = $this->createResolveRecipient(SYNC_RESOLVERECIPIENTS_TYPE_CONTACT, $to, $contact);
                    }
                }
            }
        }

        // search contacts in public folders
        $storestables = mapi_getmsgstorestable($this->session);
        $result = mapi_last_hresult();

        if ($result == NOERROR){
            $rows = mapi_table_queryallrows($storestables, array(PR_ENTRYID, PR_DEFAULT_STORE, PR_MDB_PROVIDER));
            foreach($rows as $row) {
                if (isset($row[PR_MDB_PROVIDER]) && $row[PR_MDB_PROVIDER] == ZARAFA_STORE_PUBLIC_GUID) {
                    // TODO refactor public store
                    $publicstore = mapi_openmsgstore($this->session, $row[PR_ENTRYID]);
                    $publicfolder = mapi_msgstore_openentry($publicstore);

                    $subfolders = MAPIUtils::GetSubfoldersForType($publicfolder, "IPF.Contact");
                    if ($subfolders !== false) {
                        foreach($subfolders as $folder) {
                            $contacts = $this->getContactsFromFolder($publicstore, $folder[PR_ENTRYID], $to);
                            if ($contacts !== false) {
                                ZLog::Write(LOGLEVEL_WBXML, sprintf("BackendGrommunio->resolveRecipientContact(): Found %d contacts in public contacts folder.", count($contacts)));
                                foreach ($contacts as $contact) {
                                    $recipients[] = $this->createResolveRecipient(SYNC_RESOLVERECIPIENTS_TYPE_CONTACT, $to, $contact);
                                }
                            }
                        }
                    }
                    break;
                }
            }
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGrommunio->resolveRecipientContact(): Unable to open public store: 0x%X", $result));
        }

        if (empty($recipients)) {
            $contactProperties = array();
            $contactProperties[PR_DISPLAY_NAME] = $to;
            $contactProperties[PR_USER_X509_CERTIFICATE] = false;

            $recipients[] = $this->createResolveRecipient(SYNC_RESOLVERECIPIENTS_TYPE_CONTACT, $to, $contactProperties);
        }
        return $recipients;
    }

    /**
     * Creates SyncResolveRecipientsCertificates object for ResolveRecipients
     *
     * @param binary $certificates
     * @param int $recipientCount
     *
     * @return SyncResolveRecipientsCertificates
     */
    private function getCertificates($certificates, $recipientCount = 0) {
        $cert = new SyncResolveRecipientsCertificates();
        if ($certificates === false) {
            $cert->status = SYNC_RESOLVERECIPSSTATUS_CERTIFICATES_NOVALIDCERT;
            return $cert;
        }
        $cert->status = SYNC_RESOLVERECIPSSTATUS_SUCCESS;
        $cert->certificatecount = count ($certificates);
        $cert->recipientcount = $recipientCount;
        $cert->certificate = array();
        foreach ($certificates as $certificate) {
            $cert->certificate[] = base64_encode($certificate);
        }
        return $cert;
    }

    /**
     * Creates SyncResolveRecipient object for ResolveRecipientsResponse.
     * @param int $type
     * @param string $email
     * @param array $recipientProperties
     * @param int $recipientCount
     *
     * @return SyncResolveRecipient
     */
    private function createResolveRecipient($type, $email, $recipientProperties, $recipientCount = 0) {
        $recipient = new SyncResolveRecipient();
        $recipient->type = $type;
        $recipient->displayname = u2w($recipientProperties[PR_DISPLAY_NAME]);
        $recipient->emailaddress = $email;

        if ($type == SYNC_RESOLVERECIPIENTS_TYPE_GAL) {
            $certificateProp = PR_EMS_AB_TAGGED_X509_CERT;
        }
        elseif ($type == SYNC_RESOLVERECIPIENTS_TYPE_CONTACT) {
            $certificateProp = PR_USER_X509_CERTIFICATE;
        }
        else {
            $certificateProp = null;
        }

        if (isset($recipientProperties[$certificateProp]) && is_array($recipientProperties[$certificateProp]) && !empty($recipientProperties[$certificateProp])) {
            $certificates = $this->getCertificates($recipientProperties[$certificateProp], $recipientCount);
        }
        else {
            $certificates = $this->getCertificates(false);
            ZLog::Write(LOGLEVEL_INFO, sprintf("BackendGrommunio->createResolveRecipient(): No certificate found for '%s' (requested email address: '%s')", $recipientProperties[PR_DISPLAY_NAME], $email));
        }
        $recipient->certificates = $certificates;

        if (isset($recipientProperties[PR_ENTRYID])) {
            $recipient->id = $recipientProperties[PR_ENTRYID];
        }
        return $recipient;
    }

    /**
     * Gets the availability of a user for the given time window.
     *
     * @param string $to
     * @param SyncResolveRecipient $resolveRecipient
     * @param SyncResolveRecipientsOptions $resolveRecipientsOptions
     *
     * @access private
     * @return SyncResolveRecipientsAvailability
     */
    private function getAvailability($to, $resolveRecipient, $resolveRecipientsOptions) {
        $availability = new SyncResolveRecipientsAvailability();
        $availability->status = SYNC_RESOLVERECIPSSTATUS_AVAILABILITY_SUCCESS;

        if (!isset($resolveRecipient->id)) {
            // TODO this shouldn't happen but try to get the recipient in such a case
        }

        $start = strtotime($resolveRecipientsOptions->availability->starttime);
        $end = strtotime($resolveRecipientsOptions->availability->endtime);
        // Each digit in the MergedFreeBusy indicates the free/busy status for the user for every 30 minute interval.
        $timeslots = intval(ceil(($end - $start) / self::HALFHOURSECONDS));

        if ($timeslots > self::MAXFREEBUSYSLOTS) {
            throw new StatusException("BackendGrommunio->getAvailability(): the requested free busy range is too large.", SYNC_RESOLVERECIPSSTATUS_PROTOCOLERROR);
        }

        $mergedFreeBusy = str_pad(fbNoData, $timeslots, fbNoData);

        $retval = mapi_getuseravailability($this->session, $resolveRecipient->id, $start, $end);
        ZLog::Write(LOGLEVEL_INFO, sprintf("BackendGrommunio->getAvailability(): free busy '%s'", print_r($retval, 1)));

        if (!empty($retval)) {
            $freebusy = json_decode($retval, true);
            // freebusy is available, assume that the user is free
            $mergedFreeBusy = str_pad(fbFree, $timeslots, fbFree);
            foreach ($freebusy['events'] as $event) {
                // calculate which timeslot of mergedFreeBusy should be replaced.
                $startSlot = intval(floor(($event['StartTime'] - $start) / self::HALFHOURSECONDS));
                $endSlot = intval(floor(($event['EndTime'] - $start) / self::HALFHOURSECONDS));
                // if event started at a multiple of half an hour from requested freebusy time and
                // its duration is also a multiple of half an hour
                // then it's necessary to reduce endSlot by one
                if ((($event['StartTime'] - $start) % self::HALFHOURSECONDS == 0) && (($event['EndTime'] - $event['StartTime']) % self::HALFHOURSECONDS == 0)) {
                    $endSlot--;
                }
                $fbType = Utils::GetFbStatusFromType($event['BusyType']);
                for ($i = $startSlot; $i <= $endSlot && $i < $timeslots; $i++) {
                    // only set the new slot's free busy status if it's higher than the current one
                    if ($fbType > $mergedFreeBusy[$i]) {
                        $mergedFreeBusy[$i] = $fbType;
                    }
                }
            }
        }
        $availability->mergedfreebusy = $mergedFreeBusy;
        return $availability;
    }

    /**
     * Returns contacts matching given email address from a folder.
     *
     * @param MAPIStore $store
     * @param binary $folderEntryid
     * @param string $email
     *
     * @return array|boolean
     */
    private function getContactsFromFolder($store, $folderEntryid, $email) {
        $folder = mapi_msgstore_openentry($store, $folderEntryid);
        $folderContent = mapi_folder_getcontentstable($folder);
        mapi_table_restrict($folderContent, MAPIUtils::GetEmailAddressRestriction($store, $email));
        // TODO max limit
        if (mapi_table_getrowcount($folderContent) > 0) {
            return mapi_table_queryallrows($folderContent, array(PR_DISPLAY_NAME, PR_USER_X509_CERTIFICATE, PR_ENTRYID));
        }
        return false;
    }

    /**
     * Get MAPI addressbook object
     *
     * @access private
     * @return MAPIAddressbook object to be used with mapi_ab_* or false on failure
     */
    private function getAddressbook() {
        if (isset($this->addressbook) && $this->addressbook) {
            return $this->addressbook;
        }
        $this->addressbook = mapi_openaddressbook($this->session);
        $result = mapi_last_hresult();
        if ($result && $this->addressbook === false) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendGrommunio->getAddressbook error opening addressbook 0x%X", $result));
            return false;
        }
        return $this->addressbook;
    }

    /**
     * Checks if the user is not disabled for grommunio-sync.
     *
     * @access private
     * @throws FatalException if user is disabled for grommunio-sync
     *
     * @return boolean
     */
    private function isZPushEnabled() {
        $addressbook = $this->getAddressbook();
        // this check needs to be performed on the store of the main (authenticated) user
        $store = $this->storeCache[$this->mainUser];
        $userEntryid = mapi_getprops($store, array(PR_MAILBOX_OWNER_ENTRYID));
        $mailuser = mapi_ab_openentry($addressbook, $userEntryid[PR_MAILBOX_OWNER_ENTRYID]);
        $enabledFeatures = mapi_getprops($mailuser, array(PR_EC_DISABLED_FEATURES));
        if (isset($enabledFeatures[PR_EC_DISABLED_FEATURES]) && is_array($enabledFeatures[PR_EC_DISABLED_FEATURES])) {
            $mobileDisabled = in_array(self::MOBILE_ENABLED, $enabledFeatures[PR_EC_DISABLED_FEATURES]);
            $deviceId = Request::GetDeviceID();
            // Checks for deviceId present in zarafaDisabledFeatures LDAP array attribute. Check is performed case insensitive.
            $deviceIdDisabled = ( ($deviceId !==null) && in_array($deviceId, array_map('strtolower', $enabledFeatures[PR_EC_DISABLED_FEATURES])) )? true : false;
            if ($mobileDisabled) {
                throw new FatalException("User is disabled for grommunio-sync.");
            }
            elseif ($deviceIdDisabled) {
                throw new FatalException(sprintf("User has deviceId %s disabled for usage with grommunio-sync.", $deviceId));
            }
        }
        return true;
    }
}
