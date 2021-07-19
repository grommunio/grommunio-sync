<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Provides general information for an authenticated user.
 */

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
     * Returns the grommunio-sync version.
     *
     * @access public
     * @return string
     */
    public function About() {
        ZLog::Write(LOGLEVEL_INFO, sprintf("WebserviceInfo->About(): returning grommunio-sync version '%s'", @constant('GRAMMSYNC_VERSION')));
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
