<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * This file provides/loads the handlers for the different commands. The
 * request handlers are optimised so that as little as possible data is
 * kept-in-memory, and all output data is directly streamed to the client,
 * while also streaming input data from the client.
 */

abstract class RequestProcessor {
    static protected $backend;
    static protected $deviceManager;
    static protected $topCollector;
    static protected $decoder;
    static protected $encoder;
    static protected $userIsAuthenticated;
    static protected $specialHeaders;
    static protected $waitTime = 0;

    /**
     * Authenticates the remote user
     * The sent HTTP authentication information is used to on Backend->Logon().
     * As second step the GET-User verified by Backend->Setup() for permission check
     * Request::GetGETUser() is usually the same as the Request::GetAuthUser().
     * If the GETUser is different from the AuthUser, the AuthUser MUST HAVE admin
     * permissions on GETUsers data store. Only then the Setup() will be successful.
     * This allows the user 'john' to do operations as user 'joe' if he has sufficient privileges.
     *
     * @access public
     * @return
     * @throws AuthenticationRequiredException
     */
    static public function Authenticate() {
        self::$userIsAuthenticated = false;

        // when a certificate is sent, allow authentication only as the certificate owner
        if(defined("CERTIFICATE_OWNER_PARAMETER") && isset($_SERVER[CERTIFICATE_OWNER_PARAMETER]) && strtolower($_SERVER[CERTIFICATE_OWNER_PARAMETER]) != strtolower(Request::GetAuthUser()))
            throw new AuthenticationRequiredException(sprintf("Access denied. Access is allowed only for the certificate owner '%s'", $_SERVER[CERTIFICATE_OWNER_PARAMETER]));

        if (Request::GetImpersonatedUser() && strcasecmp(Request::GetAuthUser(), Request::GetImpersonatedUser()) !== 0) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("RequestProcessor->Authenticate(): Impersonation active - authenticating: '%s' - impersonating '%s'", Request::GetAuthUser(), Request::GetImpersonatedUser()));
        }

        $backend = ZPush::GetBackend();
        if($backend->Logon(Request::GetAuthUser(), Request::GetAuthDomain(), Request::GetAuthPassword()) == false)
            throw new AuthenticationRequiredException("Access denied. Username or password incorrect");

        // mark this request as "authenticated"
        self::$userIsAuthenticated = true;
    }

    /**
     * Indicates if the user was "authenticated"
     *
     * @access public
     * @return boolean
     */
    static public function isUserAuthenticated() {
        if (!isset(self::$userIsAuthenticated))
            return false;
        return self::$userIsAuthenticated;
    }

    /**
     * Initialize the RequestProcessor
     *
     * @access public
     * @return
     */
    static public function Initialize() {
        self::$backend = ZPush::GetBackend();
        self::$deviceManager = ZPush::GetDeviceManager(false);
        self::$topCollector = ZPush::GetTopCollector();

        if (!ZPush::CommandNeedsPlainInput(Request::GetCommandCode()))
            self::$decoder = new WBXMLDecoder(Request::GetInputStream());

        self::$encoder = new WBXMLEncoder(Request::GetOutputStream(), Request::GetGETAcceptMultipart());
        self::$waitTime = 0;
    }

    /**
     * Loads the command handler and processes a command sent from the mobile
     *
     * @access public
     * @return boolean
     */
    static public function HandleRequest() {
        $handler = ZPush::GetRequestHandlerForCommand(Request::GetCommandCode());

        // if there is an error decoding wbxml, consume remaining data and include it in the WBXMLException
        try {
            if (!$handler->Handle(Request::GetCommandCode())) {
                throw new WBXMLException(sprintf("Unknown error in %s->Handle()", get_class($handler)));
            }
        }
        catch (Exception $ex) {
            // Log 10 KB of the WBXML data
            ZLog::Write(LOGLEVEL_FATAL, "WBXML 10K debug data: " . Request::GetInputAsBase64(10240), false);
            throw $ex;
        }

        // also log WBXML in happy case
        if (ZLog::IsWbxmlDebugEnabled()) {
            // Log 4 KB in the happy case
            ZLog::Write(LOGLEVEL_WBXML, "WBXML-IN : ". Request::GetInputAsBase64(4096), false);
        }
        return true;
    }

    /**
     * Returns any additional headers which should be sent to the mobile
     *
     * @access public
     * @return array
     */
    static public function GetSpecialHeaders() {
        if (!isset(self::$specialHeaders) || !is_array(self::$specialHeaders))
            return array();

        return self::$specialHeaders;
    }

    /**
     * Returns the amount of seconds RequestProcessor waited e.g. during Ping.
     *
     * @access public
     * @return int
     */
    public static function GetWaitTime() {
        return self::$waitTime;
    }

    /**
     * Handles a command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    abstract public function Handle($commandCode);
}
