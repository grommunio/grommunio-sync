<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * HierarchyCache implementation
 */

class HierarchyCache extends StateObject {
    protected $changed = false;
    protected $data;
    private $dataOld;

    /**
     * Constructor of the HierarchyCache
     *
     * @access public
     * @return
     */
    public function __construct() {
        $this->data = array();
        $this->dataOld = $this->data;
        $this->changed = true;
    }

    /**
     * Indicates if the cache was changed
     *
     * @access public
     * @return boolean
     */
    public function IsStateChanged() {
        return $this->changed;
    }

    /**
     * Copy current data to memory
     *
     * @access public
     * @return boolean
     */
    public function CopyOldState() {
        $this->dataOld = $this->data;
        $this->changed = false;
        return true;
    }

    /**
     * Returns the SyncFolder object for a folder id
     * If $oldstate is set, then the data from the previous state is returned
     *
     * @param string    $serverid
     * @param boolean   $oldstate       (optional) by default false
     *
     * @access public
     * @return SyncObject/boolean       false if not found
     */
    public function GetFolder($serverid, $oldState = false) {
        if (!$oldState && array_key_exists($serverid, $this->data)) {
            return $this->data[$serverid];
        }
        else if ($oldState && array_key_exists($serverid, $this->dataOld)) {
            return $this->dataOld[$serverid];
        }
        return false;
    }

    /**
     * Adds a folder to the HierarchyCache
     *
     * @param SyncObject    $folder
     *
     * @access public
     * @return boolean
     */
    public function AddFolder($folder) {
        ZLog::Write(LOGLEVEL_DEBUG, "HierarchyCache: AddFolder() serverid: {$folder->serverid} displayname: {$folder->displayname}");

        // on update the $folder does most of the times not contain a type
        // we copy the value in this case to the new $folder object
        if (isset($this->data[$folder->serverid]) && (!isset($folder->type) || $folder->type == false) && isset($this->data[$folder->serverid]->type)) {
            $folder->type = $this->data[$folder->serverid]->type;
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HierarchyCache: AddFolder() is an update: used type '%s' from old object", $folder->type));
        }

        // add/update
        $this->data[$folder->serverid] = $folder;
        $this->changed = true;

        return true;
    }

    /**
     * Removes a folder to the HierarchyCache
     *
     * @param string    $serverid           id of folder to be removed
     *
     * @access public
     * @return boolean
     */
    public function DelFolder($serverid) {
        $ftype = $this->GetFolder($serverid);

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HierarchyCache: DelFolder() serverid: '%s' - type: '%s'", $serverid, $ftype->type));
        unset($this->data[$serverid]);
        $this->changed = true;
        return true;
    }

    /**
     * Imports a folder array to the HierarchyCache
     *
     * @param array     $folders            folders to the HierarchyCache
     *
     * @access public
     * @return boolean
     */
    public function ImportFolders($folders) {
        if (!is_array($folders))
            return false;

        $this->data = array();

        foreach ($folders as $folder) {
            if (!isset($folder->type))
                continue;
            $this->AddFolder($folder);
        }
        return true;
    }

    /**
     * Exports all folders from the HierarchyCache
     *
     * @param boolean   $oldstate           (optional) by default false
     *
     * @access public
     * @return array
     */
    public function ExportFolders($oldstate = false) {
        if ($oldstate === false)
            return $this->data;
        else
            return $this->dataOld;
    }

    /**
     * Returns all folder objects which were deleted in this operation
     *
     * @access public
     * @return array        with SyncFolder objects
     */
    public function GetDeletedFolders() {
        // diffing the Olddata with data we know if folders were deleted
        return array_diff_key($this->dataOld, $this->data);
    }

    /**
     * Returns some statistics about the HierarchyCache
     *
     * @access public
     * @return string
     */
    public function GetStat() {
        return sprintf("HierarchyCache is %s - Cached objects: %d", ((isset($this->data))?"up":"down"), ((isset($this->data))?count($this->data):"0"));
    }

    /**
     * Removes internal data from the object, so this data can not be exposed.
     *
     * @access public
     * @return boolean
     */
    public function StripData() {
        unset($this->changed);
        unset($this->dataOld);
        foreach ($this->data as $id => $folder) {
            $folder->StripData();
        }
        return true;
    }

    /**
     * Returns objects which should be persistent
     * called before serialization
     *
     * @access public
     * @return array
     */
    public function __sleep() {
        return array("data");
    }

}
