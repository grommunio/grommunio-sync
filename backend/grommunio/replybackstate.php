<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Holds the state of the ReplyBackImExporter and also the ICS state to
 * continue on later
 */

class ReplyBackState extends StateObject {
    protected $unsetdata = array(
            'replybackstate' => array(),
            'icsstate' => "",
    );

    /**
     * Returns a ReplyBackState from a state.
     *
     * @param mixed $state
     * @return ReplyBackState
     */
    static public function FromState($state) {
        if (strpos($state, 'ReplyBackState') !== false) {
            return unserialize($state);
        }
        else {
            $s = new ReplyBackState();
            $s->SetICSState($state);
            $s->SetReplyBackState(array());
            return $s;
        }
    }

    /**
     * Gets the state from a ReplyBackState object.
     *
     * @param mixed $state
     */
    static public function ToState($state) {
        $rbs = $state->GetReplyBackState();
        if (!empty($rbs)) {
            return serialize($state);
        }
        else {
            return $state->GetICSState();
        }
    }
}
