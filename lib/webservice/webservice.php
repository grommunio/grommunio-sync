<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grammm GmbH
 *
 * Provides an interface for administration tasks over a webservice
 */

class Webservice {
    private $server;

    /**
     * Handles a webservice command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     * @throws SoapFault
     */
    public function Handle($commandCode) {
        if (Request::GetDeviceType() !== "webservice" || Request::GetDeviceID() !== "webservice") {
            throw new FatalException("Invalid device id and type for webservice execution");
        }

        $user = (Request::GetImpersonatedUser()) ? Request::GetImpersonatedUser() : Request::GetGETUser();
        if ($user != Request::GetAuthUser()) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("Webservice::HandleWebservice('%s'): user '%s' executing action for user '%s'", $commandCode, Request::GetAuthUser(), $user));
        }

        // initialize non-wsdl soap server
        $this->server = new SoapServer(null, array('uri' => "http://grammm.com/webservice"));

        // the webservice command is handled by its class
        if ($commandCode == ZPush::COMMAND_WEBSERVICE_DEVICE) {
            // check if the authUser has admin permissions to get data on the GETUser's device
            if (ZPush::GetBackend()->Setup($user, true) == false) {
                throw new AuthenticationRequiredException(sprintf("Not enough privileges of '%s' to setup for user '%s': Permission denied", Request::GetAuthUser(), $user));
            }

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Webservice::HandleWebservice('%s'): executing WebserviceDevice service", $commandCode));
            $this->server->setClass("WebserviceDevice");
        }
        elseif ($commandCode == ZPush::COMMAND_WEBSERVICE_INFO) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Webservice::HandleWebservice('%s'): executing WebserviceInfo service", $commandCode));
            $this->server->setClass("WebserviceInfo");
        }
        elseif ($commandCode == ZPush::COMMAND_WEBSERVICE_USERS) {
            if (!defined("ALLOW_WEBSERVICE_USERS_ACCESS") || ALLOW_WEBSERVICE_USERS_ACCESS !== true) {
                throw new HTTPReturnCodeException("Access to the WebserviceUsers service is disabled in configuration. Enable setting ALLOW_WEBSERVICE_USERS_ACCESS", 403);
            }

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Webservice::HandleWebservice('%s'): executing WebserviceUsers service", $commandCode));

            if (ZPush::GetBackend()->Setup("SYSTEM", true) == false) {
                throw new AuthenticationRequiredException(sprintf("User '%s' has no admin privileges", Request::GetAuthUser()));
            }

            $this->server->setClass("WebserviceUsers");
        }

        $this->server->handle();

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Webservice::HandleWebservice('%s'): sucessfully sent %d bytes", $commandCode, ob_get_length()));
        return true;
    }
}
