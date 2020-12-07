<?php
/***********************************************
* File      :   webserviceinfo.php
* Project   :   Grammm-Sync
* Descr     :   Provides general information for an authenticated
*               user.
*
* Created   :   17.06.2016
*
* Copyright 2016 Zarafa Deutschland GmbH
* Copyright 2020 Grammm GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

class WebserviceInfo {

    /**
     * Returns a list of folders of the requested user.
     * If the user has not enough permissions an empty result is returned.
     *
     * @access public
     * @return array
     */
    public function ListUserFolders() {
        $user = Request::GetImpersonatedUser() ? Request::GetImpersonatedUser() : Request::GetGETUser();
        $output = array();
        $hasRights = ZPush::GetBackend()->Setup($user);
        ZLog::Write(LOGLEVEL_INFO, sprintf("WebserviceInfo::ListUserFolders(): permissions to open store '%s': %s", $user, Utils::PrintAsString($hasRights)));

        if ($hasRights) {
            $folders = ZPush::GetBackend()->GetHierarchy();
            ZPush::GetTopCollector()->AnnounceInformation(sprintf("Retrieved details of %d folders", count($folders)), true);

            foreach ($folders as $folder) {
                $folder->StripData();
                unset($folder->Store, $folder->flags, $folder->content, $folder->NoBackendFolder);
                $output[] = $folder;
            }
        }

        return $output;
    }

    /**
     * Returns the grammm-sync version.
     *
     * @access public
     * @return string
     */
    public function About() {
        ZLog::Write(LOGLEVEL_INFO, sprintf("WebserviceInfo->About(): returning grammm-sync version '%s'", @constant('GRAMMSYNC_VERSION')));
        return @constant('GRAMMSYNC_VERSION');
    }

    /**
     * Returns information about the user's store:
     * number of folders, store size, full name, email address.
     *
     * @access public
     * @return UserStoreInfo
     */
    public function GetUserStoreInfo() {
        $userStoreInfo = null;
        $user = Request::GetImpersonatedUser() ? Request::GetImpersonatedUser() : Request::GetGETUser();
        $hasRights = ZPush::GetBackend()->Setup($user);
        ZLog::Write(LOGLEVEL_INFO, sprintf("WebserviceInfo::GetUserStoreInfo(): permissions to open store '%s': %s", $user, Utils::PrintAsString($hasRights)));

        if ($hasRights) {
            $userStoreInfo = ZPush::GetBackend()->GetUserStoreInfo();
        }
        return $userStoreInfo;
    }
}
