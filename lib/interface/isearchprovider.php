<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * The ISearchProvider interface for searching
 * functionalities on the mobile.
 */

interface ISearchProvider {
	public const SEARCH_GAL = "GAL";
	public const SEARCH_MAILBOX = "MAILBOX";
	public const SEARCH_DOCUMENTLIBRARY = "DOCUMENTLIBRARY";

	/**
	 * Constructor.
	 *
	 * @param mixed $searchtype
	 *
	 * @throws StatusException, FatalException
	 */

	/**
	 * Indicates if a search type is supported by this SearchProvider
	 * Currently only the type SEARCH_GAL (Global Address List) is implemented.
	 *
	 * @param string $searchtype
	 *
	 * @return bool
	 */
	public function SupportsType($searchtype);

	/**
	 * Searches the GAL.
	 *
	 * @param string                       $searchquery   string to be searched for
	 * @param string                       $searchrange   specified searchrange
	 * @param SyncResolveRecipientsPicture $searchpicture limitations for picture
	 *
	 * @return array search results
	 *
	 * @throws StatusException
	 */
	public function GetGALSearchResults($searchquery, $searchrange, $searchpicture);

	/**
	 * Searches for the emails on the server.
	 *
	 * @param ContentParameter $cpo
	 *
	 * @return array
	 */
	public function GetMailboxSearchResults($cpo);

	/**
	 * Terminates a search for a given PID.
	 *
	 * @param int $pid
	 *
	 * @return bool
	 */
	public function TerminateSearch($pid);

	/**
	 * Disconnects from the current search provider.
	 *
	 * @return bool
	 */
	public function Disconnect();
}
