<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2024 grommunio GmbH
 *
 * Several utility functions
 */

class Utils {
	/**
	 * Prints a variable as string
	 * If a boolean is sent, 'true' or 'false' is displayed.
	 *
	 * @param string $var
	 *
	 * @return string
	 */
	public static function PrintAsString($var) {
		return ($var) ? (($var === true) ? 'true' : $var) : (($var === false) ? 'false' : (($var === '') ? 'empty' : (($var === null) ? 'null' : $var)));
	}

	/**
	 * Splits a "domain\user" string into two values
	 * If the string contains only the user, domain is returned empty.
	 *
	 * @param string $domainuser
	 *
	 * @return array index 0: user  1: domain
	 */
	public static function SplitDomainUser($domainuser) {
		$pos = strrpos($domainuser, '\\');
		if ($pos === false) {
			$user = $domainuser;
			$domain = '';
		}
		else {
			$domain = substr($domainuser, 0, $pos);
			$user = substr($domainuser, $pos + 1);
		}

		return [$user, $domain];
	}

	/**
	 * Build an address string from the components.
	 *
	 * @param string $street  the street
	 * @param string $zip     the zip code
	 * @param string $city    the city
	 * @param string $state   the state
	 * @param string $country the country
	 *
	 * @return string the address string or null
	 */
	public static function BuildAddressString($street, $zip, $city, $state, $country) {
		$out = $country ?? "";

		$zcs = $zip ?? "";
		if ($city != "") {
			$zcs .= (($zcs) ? " " : "") . $city;
		}
		if ($state != "") {
			$zcs .= (($zcs) ? " " : "") . $state;
		}
		if ($zcs) {
			$out = $zcs . "\r\n" . $out;
		}

		if ($street != "") {
			$out = $street . (($out) ? "\r\n\r\n" . $out : "");
		}

		return $out ?? null;
	}

	/**
	 * Build the fileas string from the components according to the configuration.
	 *
	 * @param string $lastname
	 * @param string $firstname
	 * @param string $middlename
	 * @param string $company
	 *
	 * @return string fileas
	 */
	public static function BuildFileAs($lastname = "", $firstname = "", $middlename = "", $company = "") {
		if (defined('FILEAS_ORDER')) {
			$fileas = $lastfirst = $firstlast = "";
			$names = trim($firstname . " " . $middlename);
			$lastname = trim($lastname);
			$company = trim($company);

			// lastfirst is "lastname, firstname middlename"
			// firstlast is "firstname middlename lastname"
			if (strlen($lastname) > 0) {
				$lastfirst = $lastname;
				if (strlen($names) > 0) {
					$lastfirst .= ", {$names}";
					$firstlast = "{$names} {$lastname}";
				}
				else {
					$firstlast = $lastname;
				}
			}
			elseif (strlen($names) > 0) {
				$lastfirst = $firstlast = $names;
			}

			// if fileas with a company is selected
			// but company is empty then it will
			// fallback to firstlast or lastfirst
			// (depending on which is selected for company)
			switch (FILEAS_ORDER) {
				case SYNC_FILEAS_COMPANYONLY:
					if (strlen($company) > 0) {
						$fileas = $company;
					}
					elseif (strlen($firstlast) > 0) {
						$fileas = $lastfirst;
					}
					break;

				case SYNC_FILEAS_COMPANYLAST:
					if (strlen($company) > 0) {
						$fileas = $company;
						if (strlen($lastfirst) > 0) {
							$fileas .= "({$lastfirst})";
						}
					}
					elseif (strlen($lastfirst) > 0) {
						$fileas = $lastfirst;
					}
					break;

				case SYNC_FILEAS_COMPANYFIRST:
					if (strlen($company) > 0) {
						$fileas = $company;
						if (strlen($firstlast) > 0) {
							$fileas .= " ({$firstlast})";
						}
					}
					elseif (strlen($firstlast) > 0) {
						$fileas = $firstlast;
					}
					break;

				case SYNC_FILEAS_FIRSTCOMPANY:
					if (strlen($firstlast) > 0) {
						$fileas = $firstlast;
						if (strlen($company) > 0) {
							$fileas .= " ({$company})";
						}
					}
					elseif (strlen($company) > 0) {
						$fileas = $company;
					}
					break;

				case SYNC_FILEAS_LASTCOMPANY:
					if (strlen($lastfirst) > 0) {
						$fileas = $lastfirst;
						if (strlen($company) > 0) {
							$fileas .= " ({$company})";
						}
					}
					elseif (strlen($company) > 0) {
						$fileas = $company;
					}
					break;

				case SYNC_FILEAS_LASTFIRST:
					if (strlen($lastfirst) > 0) {
						$fileas = $lastfirst;
					}
					break;

				default:
					$fileas = $firstlast;
					break;
			}
			if (strlen($fileas) == 0) {
				SLog::Write(LOGLEVEL_DEBUG, "Fileas is empty.");
			}

			return $fileas;
		}
		SLog::Write(LOGLEVEL_DEBUG, "FILEAS_ORDER not defined. Add it to your config.php.");

		return null;
	}

	/**
	 * Extracts the basedate of the GlobalObjectID and the RecurStartTime.
	 *
	 * @param string $goid           OL compatible GlobalObjectID
	 * @param long   $recurStartTime
	 *
	 * @return long basedate
	 */
	public static function ExtractBaseDate($goid, $recurStartTime) {
		$hexbase = substr(bin2hex($goid), 32, 8);
		$day = hexdec(substr($hexbase, 6, 2));
		$month = hexdec(substr($hexbase, 4, 2));
		$year = hexdec(substr($hexbase, 0, 4));

		if ($day && $month && $year) {
			$h = $recurStartTime >> 12;
			$m = ($recurStartTime - $h * 4096) >> 6;
			$s = $recurStartTime - $h * 4096 - $m * 64;

			return gmmktime($h, $m, $s, $month, $day, $year);
		}

		return false;
	}

	/**
	 * Converts SYNC_FILTERTYPE into a timestamp.
	 *
	 * @param int $filtertype Filtertype
	 *
	 * @return long
	 */
	public static function GetCutOffDate($filtertype) {
		$back = Utils::GetFiltertypeInterval($filtertype);

		if ($back === false) {
			return 0; // unlimited
		}

		return time() - $back;
	}

	/**
	 * Returns the interval indicated by the filtertype.
	 *
	 * @param int $filtertype
	 *
	 * @return bool|long returns false on invalid filtertype
	 */
	public static function GetFiltertypeInterval($filtertype) {
		$back = false;

		switch ($filtertype) {
			case SYNC_FILTERTYPE_1DAY:
				$back = 60 * 60 * 24;
				break;

			case SYNC_FILTERTYPE_3DAYS:
				$back = 60 * 60 * 24 * 3;
				break;

			case SYNC_FILTERTYPE_1WEEK:
				$back = 60 * 60 * 24 * 7;
				break;

			case SYNC_FILTERTYPE_2WEEKS:
				$back = 60 * 60 * 24 * 14;
				break;

			case SYNC_FILTERTYPE_1MONTH:
				$back = 60 * 60 * 24 * 31;
				break;

			case SYNC_FILTERTYPE_3MONTHS:
				$back = 60 * 60 * 24 * 31 * 3;
				break;

			case SYNC_FILTERTYPE_6MONTHS:
				$back = 60 * 60 * 24 * 31 * 6;
				break;

			default:
				$back = false;
		}

		return $back;
	}

	/**
	 * Converts SYNC_TRUNCATION into bytes.
	 *
	 * @param int       SYNC_TRUNCATION
	 * @param mixed $truncation
	 *
	 * @return long
	 */
	public static function GetTruncSize($truncation) {
		switch ($truncation) {
			case SYNC_TRUNCATION_HEADERS:
				return 0;

			case SYNC_TRUNCATION_512B:
				return 512;

			case SYNC_TRUNCATION_1K:
				return 1024;

			case SYNC_TRUNCATION_2K:
				return 2 * 1024;

			case SYNC_TRUNCATION_5K:
				return 5 * 1024;

			case SYNC_TRUNCATION_10K:
				return 10 * 1024;

			case SYNC_TRUNCATION_20K:
				return 20 * 1024;

			case SYNC_TRUNCATION_50K:
				return 50 * 1024;

			case SYNC_TRUNCATION_100K:
				return 100 * 1024;

			case SYNC_TRUNCATION_ALL:
				return 1024 * 1024; // We'll limit to 1MB anyway

			default:
				return 1024; // Default to 1Kb
		}
	}

	/**
	 * Truncate an UTF-8 encoded string correctly.
	 *
	 * If it's not possible to truncate properly, an empty string is returned
	 *
	 * @param string $string   the string
	 * @param string $length   position where string should be cut
	 * @param bool   $htmlsafe doesn't cut html tags in half, doesn't ensure correct html - default: false
	 *
	 * @return string truncated string
	 */
	public static function Utf8_truncate($string, $length, $htmlsafe = false) {
		// skip empty strings
		if (empty($string)) {
			return "";
		}

		// make sure length is always an integer
		$length = (int) $length;

		// if the input string is shorter then the trunction, make sure it's valid UTF-8!
		if (strlen($string) <= $length) {
			$length = strlen($string) - 1;
		}

		// The intent is not to cut HTML tags in half which causes displaying issues.
		// The used method just tries to cut outside of tags, without checking tag validity and closing tags.
		if ($htmlsafe) {
			$offset = 0 - strlen($string) + $length;
			$validPos = strrpos($string, "<", $offset);
			if ($validPos > strrpos($string, ">", $offset)) {
				$length = $validPos;
			}
		}

		while ($length >= 0) {
			if ((ord($string[$length]) < 0x80) || (ord($string[$length]) >= 0xC0)) {
				return substr($string, 0, $length);
			}
			--$length;
		}

		return "";
	}

	/**
	 * Indicates if the specified folder type is a system folder.
	 *
	 * @param int $foldertype
	 *
	 * @return bool
	 */
	public static function IsSystemFolder($foldertype) {
		return (
			$foldertype == SYNC_FOLDER_TYPE_INBOX ||
			$foldertype == SYNC_FOLDER_TYPE_DRAFTS ||
			$foldertype == SYNC_FOLDER_TYPE_WASTEBASKET ||
			$foldertype == SYNC_FOLDER_TYPE_SENTMAIL ||
			$foldertype == SYNC_FOLDER_TYPE_OUTBOX ||
			$foldertype == SYNC_FOLDER_TYPE_TASK ||
			$foldertype == SYNC_FOLDER_TYPE_APPOINTMENT ||
			$foldertype == SYNC_FOLDER_TYPE_CONTACT ||
			$foldertype == SYNC_FOLDER_TYPE_NOTE ||
			$foldertype == SYNC_FOLDER_TYPE_JOURNAL
		) ? true : false;
	}

	/**
	 * Checks for valid email addresses
	 * The used regex actually only checks if a valid email address is part of the submitted string
	 * it also returns true for the mailbox format, but this is not checked explicitly.
	 *
	 * @param string $email address to be checked
	 *
	 * @return bool
	 */
	public static function CheckEmail($email) {
		return strpos($email, '@') !== false ? true : false;
	}

	/**
	 * Checks if a string is base64 encoded.
	 *
	 * @param string $string the string to be checked
	 *
	 * @return bool
	 */
	public static function IsBase64String($string) {
		return (bool) preg_match("#^([A-Za-z0-9+/]{4})*([A-Za-z0-9+/]{2}==|[A-Za-z0-9+\\/]{3}=|[A-Za-z0-9+/]{4})?$#", $string);
	}

	/**
	 * Returns a command string for a given command code.
	 *
	 * @param int $code
	 *
	 * @return string or false if code is unknown
	 */
	public static function GetCommandFromCode($code) {
		switch ($code) {
			case GSync::COMMAND_SYNC:                 return 'Sync';

			case GSync::COMMAND_SENDMAIL:             return 'SendMail';

			case GSync::COMMAND_SMARTFORWARD:         return 'SmartForward';

			case GSync::COMMAND_SMARTREPLY:           return 'SmartReply';

			case GSync::COMMAND_GETATTACHMENT:        return 'GetAttachment';

			case GSync::COMMAND_FOLDERSYNC:           return 'FolderSync';

			case GSync::COMMAND_FOLDERCREATE:         return 'FolderCreate';

			case GSync::COMMAND_FOLDERDELETE:         return 'FolderDelete';

			case GSync::COMMAND_FOLDERUPDATE:         return 'FolderUpdate';

			case GSync::COMMAND_MOVEITEMS:            return 'MoveItems';

			case GSync::COMMAND_GETITEMESTIMATE:      return 'GetItemEstimate';

			case GSync::COMMAND_MEETINGRESPONSE:      return 'MeetingResponse';

			case GSync::COMMAND_SEARCH:               return 'Search';

			case GSync::COMMAND_SETTINGS:             return 'Settings';

			case GSync::COMMAND_PING:                 return 'Ping';

			case GSync::COMMAND_ITEMOPERATIONS:       return 'ItemOperations';

			case GSync::COMMAND_PROVISION:            return 'Provision';

			case GSync::COMMAND_RESOLVERECIPIENTS:    return 'ResolveRecipients';

			case GSync::COMMAND_VALIDATECERT:         return 'ValidateCert';

				// Deprecated commands
			case GSync::COMMAND_GETHIERARCHY:         return 'GetHierarchy';

			case GSync::COMMAND_CREATECOLLECTION:     return 'CreateCollection';

			case GSync::COMMAND_DELETECOLLECTION:     return 'DeleteCollection';

			case GSync::COMMAND_MOVECOLLECTION:       return 'MoveCollection';

			case GSync::COMMAND_NOTIFY:               return 'Notify';

			case GSync::COMMAND_FIND:                 return 'Find';
		}

		return false;
	}

	/**
	 * Returns a command code for a given command.
	 *
	 * @param string $command
	 *
	 * @return int or false if command is unknown
	 */
	public static function GetCodeFromCommand($command) {
		switch ($command) {
			case 'Sync':                 return GSync::COMMAND_SYNC;

			case 'SendMail':             return GSync::COMMAND_SENDMAIL;

			case 'SmartForward':         return GSync::COMMAND_SMARTFORWARD;

			case 'SmartReply':           return GSync::COMMAND_SMARTREPLY;

			case 'GetAttachment':        return GSync::COMMAND_GETATTACHMENT;

			case 'FolderSync':           return GSync::COMMAND_FOLDERSYNC;

			case 'FolderCreate':         return GSync::COMMAND_FOLDERCREATE;

			case 'FolderDelete':         return GSync::COMMAND_FOLDERDELETE;

			case 'FolderUpdate':         return GSync::COMMAND_FOLDERUPDATE;

			case 'MoveItems':            return GSync::COMMAND_MOVEITEMS;

			case 'GetItemEstimate':      return GSync::COMMAND_GETITEMESTIMATE;

			case 'MeetingResponse':      return GSync::COMMAND_MEETINGRESPONSE;

			case 'Search':               return GSync::COMMAND_SEARCH;

			case 'Settings':             return GSync::COMMAND_SETTINGS;

			case 'Ping':                 return GSync::COMMAND_PING;

			case 'ItemOperations':       return GSync::COMMAND_ITEMOPERATIONS;

			case 'Provision':            return GSync::COMMAND_PROVISION;

			case 'ResolveRecipients':    return GSync::COMMAND_RESOLVERECIPIENTS;

			case 'ValidateCert':         return GSync::COMMAND_VALIDATECERT;

				// Deprecated commands
			case 'GetHierarchy':         return GSync::COMMAND_GETHIERARCHY;

			case 'CreateCollection':     return GSync::COMMAND_CREATECOLLECTION;

			case 'DeleteCollection':     return GSync::COMMAND_DELETECOLLECTION;

			case 'MoveCollection':       return GSync::COMMAND_MOVECOLLECTION;

			case 'Notify':               return GSync::COMMAND_NOTIFY;

			case 'Find':                 return GSync::COMMAND_FIND;
		}

		return false;
	}

	/**
	 * Normalize the given timestamp to the start of the day.
	 *
	 * @param long $timestamp
	 *
	 * @return long
	 */
	public static function getDayStartOfTimestamp($timestamp) {
		return $timestamp - ($timestamp % (60 * 60 * 24));
	}

	/**
	 * Returns a formatted string output from an optional timestamp.
	 * If no timestamp is sent, NOW is used.
	 *
	 * @param long $timestamp
	 *
	 * @return string
	 */
	public static function GetFormattedTime($timestamp = false) {
		if (!$timestamp) {
			return @strftime("%d/%m/%Y %H:%M:%S");
		}

		return @strftime("%d/%m/%Y %H:%M:%S", $timestamp);
	}

	/**
	 * Get charset name from a codepage.
	 *
	 * @see http://msdn.microsoft.com/en-us/library/dd317756(VS.85).aspx
	 *
	 * Table taken from common/codepage.cpp
	 *
	 * @param int codepage Codepage
	 * @param mixed $codepage
	 *
	 * @return string iconv-compatible charset name
	 */
	public static function GetCodepageCharset($codepage) {
		$codepages = [
			20106 => "DIN_66003",
			20108 => "NS_4551-1",
			20107 => "SEN_850200_B",
			950 => "big5",
			50221 => "csISO2022JP",
			51932 => "euc-jp",
			51936 => "euc-cn",
			51949 => "euc-kr",
			949 => "euc-kr",
			936 => "gb18030",
			52936 => "csgb2312",
			852 => "ibm852",
			866 => "ibm866",
			50220 => "iso-2022-jp",
			50222 => "iso-2022-jp",
			50225 => "iso-2022-kr",
			1252 => "windows-1252",
			28591 => "iso-8859-1",
			28592 => "iso-8859-2",
			28593 => "iso-8859-3",
			28594 => "iso-8859-4",
			28595 => "iso-8859-5",
			28596 => "iso-8859-6",
			28597 => "iso-8859-7",
			28598 => "iso-8859-8",
			28599 => "iso-8859-9",
			28603 => "iso-8859-13",
			28605 => "iso-8859-15",
			20866 => "koi8-r",
			21866 => "koi8-u",
			932 => "shift-jis",
			1200 => "unicode",
			1201 => "unicodebig",
			65000 => "utf-7",
			65001 => "utf-8",
			1250 => "windows-1250",
			1251 => "windows-1251",
			1253 => "windows-1253",
			1254 => "windows-1254",
			1255 => "windows-1255",
			1256 => "windows-1256",
			1257 => "windows-1257",
			1258 => "windows-1258",
			874 => "windows-874",
			20127 => "us-ascii",
		];

		if (isset($codepages[$codepage])) {
			return $codepages[$codepage];
		}

		// Defaulting to iso-8859-15 since it is more likely for someone to make a mistake in the codepage
		// when using west-european charsets then when using other charsets since utf-8 is binary compatible
		// with the bottom 7 bits of west-european
		return "iso-8859-15";
	}

	/**
	 * Converts a string encoded with codepage into an UTF-8 string.
	 *
	 * @param int    $codepage
	 * @param string $string
	 *
	 * @return string
	 */
	public static function ConvertCodepageStringToUtf8($codepage, $string) {
		if (function_exists("iconv")) {
			$charset = self::GetCodepageCharset($codepage);

			return iconv($charset, "utf-8", $string);
		}

		SLog::Write(LOGLEVEL_WARN, "Utils::ConvertCodepageStringToUtf8() 'iconv' is not available. Charset conversion skipped.");

		return $string;
	}

	/**
	 * Converts a string to another charset.
	 *
	 * @param int    $in
	 * @param int    $out
	 * @param string $string
	 *
	 * @return string
	 */
	public static function ConvertCodepage($in, $out, $string) {
		// do nothing if both charsets are the same
		if ($in == $out) {
			return $string;
		}

		if (function_exists("iconv")) {
			$inCharset = self::GetCodepageCharset($in);
			$outCharset = self::GetCodepageCharset($out);

			return iconv($inCharset, $outCharset, $string);
		}

		SLog::Write(LOGLEVEL_WARN, "Utils::ConvertCodepage() 'iconv' is not available. Charset conversion skipped.");

		return $string;
	}

	/**
	 * Returns the best match of preferred body preference types.
	 *
	 * @param array $bpTypes
	 *
	 * @return int
	 */
	public static function GetBodyPreferenceBestMatch($bpTypes) {
		if ($bpTypes === false) {
			return SYNC_BODYPREFERENCE_PLAIN;
		}
		// The bettter choice is HTML and then MIME in order to save bandwidth
		// because MIME is a complete message including the headers and attachments
		if (in_array(SYNC_BODYPREFERENCE_HTML, $bpTypes)) {
			return SYNC_BODYPREFERENCE_HTML;
		}
		if (in_array(SYNC_BODYPREFERENCE_MIME, $bpTypes)) {
			return SYNC_BODYPREFERENCE_MIME;
		}

		return SYNC_BODYPREFERENCE_PLAIN;
	}

	/**
	 * Returns AS-style LastVerbExecuted value from the server value.
	 *
	 * @param int $verb
	 *
	 * @return int
	 */
	public static function GetLastVerbExecuted($verb) {
		switch ($verb) {
			case NOTEIVERB_REPLYTOSENDER:   return AS_REPLYTOSENDER;

			case NOTEIVERB_REPLYTOALL:      return AS_REPLYTOALL;

			case NOTEIVERB_FORWARD:         return AS_FORWARD;
		}

		return 0;
	}

	/**
	 * Returns the local part from email address.
	 *
	 * @param string $email
	 *
	 * @return string
	 */
	public static function GetLocalPartFromEmail($email) {
		$pos = strpos($email, '@');
		if ($pos === false) {
			return $email;
		}

		return substr($email, 0, $pos);
	}

	/**
	 * Format bytes to a more human readable value.
	 *
	 * @param int $bytes
	 * @param int $precision
	 *
	 * @return string|void
	 */
	public static function FormatBytes($bytes, $precision = 2) {
		if ($bytes <= 0) {
			return '0 B';
		}

		$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB'];
		$base = log($bytes, 1024);
		$fBase = floor($base);
		$pow = pow(1024, $base - $fBase);

		return sprintf("%.{$precision}f %s", $pow, $units[$fBase]);
	}

	/**
	 * Returns folder origin identifier from its id.
	 *
	 * @param string $folderid
	 *
	 * @return bool|string matches values of DeviceManager::FLD_ORIGIN_*
	 */
	public static function GetFolderOriginFromId($folderid) {
		$origin = substr($folderid, 0, 1);

		switch ($origin) {
			case DeviceManager::FLD_ORIGIN_CONFIG:
			case DeviceManager::FLD_ORIGIN_GAB:
			case DeviceManager::FLD_ORIGIN_SHARED:
			case DeviceManager::FLD_ORIGIN_USER:
			case DeviceManager::FLD_ORIGIN_IMPERSONATED:
				return $origin;
		}
		SLog::Write(LOGLEVEL_WARN, sprintf("Utils->GetFolderOriginFromId(): Unknown folder origin for folder with id '%s'", $folderid));

		return false;
	}

	/**
	 * Returns folder origin as string from its id.
	 *
	 * @param string $folderid
	 *
	 * @return string
	 */
	public static function GetFolderOriginStringFromId($folderid) {
		$origin = substr($folderid, 0, 1);

		switch ($origin) {
			case DeviceManager::FLD_ORIGIN_CONFIG:
				return 'configured';

			case DeviceManager::FLD_ORIGIN_GAB:
				return 'GAB';

			case DeviceManager::FLD_ORIGIN_SHARED:
				return 'shared';

			case DeviceManager::FLD_ORIGIN_USER:
				return 'user';

			case DeviceManager::FLD_ORIGIN_IMPERSONATED:
				return 'impersonated';
		}
		SLog::Write(LOGLEVEL_WARN, sprintf("Utils->GetFolderOriginStringFromId(): Unknown folder origin for folder with id '%s'", $folderid));

		return 'unknown';
	}

	/**
	 * Splits the id into folder id and message id parts. A colon in the $id indicates
	 * that the id has folderid:messageid format.
	 *
	 * @param string $id
	 *
	 * @return array
	 */
	public static function SplitMessageId($id) {
		if (strpos($id, ':') !== false) {
			return explode(':', $id);
		}

		return [null, $id];
	}

	/**
	 * Transforms an AS timestamp into a unix timestamp.
	 *
	 * @param string $ts
	 *
	 * @return long
	 */
	public static function ParseDate($ts) {
		if (preg_match("/(\\d{4})[^0-9]*(\\d{2})[^0-9]*(\\d{2})(T(\\d{2})[^0-9]*(\\d{2})[^0-9]*(\\d{2})(.\\d+)?Z){0,1}$/", $ts, $matches)) {
			if ($matches[1] >= 2038) {
				$matches[1] = 2038;
				$matches[2] = 1;
				$matches[3] = 18;
				$matches[5] = $matches[6] = $matches[7] = 0;
			}

			if (!isset($matches[5])) {
				$matches[5] = 0;
			}
			if (!isset($matches[6])) {
				$matches[6] = 0;
			}
			if (!isset($matches[7])) {
				$matches[7] = 0;
			}

			return gmmktime($matches[5], $matches[6], $matches[7], $matches[2], $matches[3], $matches[1]);
		}

		return 0;
	}

	/**
	 * Transforms an unix timestamp into an AS timestamp or a human readable format.
	 *
	 * Oh yeah, this is beautiful. Exchange outputs date fields differently in calendar items
	 * and emails. We could just always send one or the other, but unfortunately nokia's 'Mail for
	 * exchange' depends on this quirk. So we have to send a different date type depending on where
	 * it's used. Sigh.
	 *
	 * @param int $ts
	 * @param int $type int (StreamerType) (optional) if not set a human readable format is returned
	 *
	 * @return string
	 */
	public static function FormatDate($ts, $type = "") {
		// fallback to a human readable format (used for logging)
		$formatString = "yyyy-MM-dd HH:mm:SS' UTC'";
		if ($type == Streamer::STREAMER_TYPE_DATE) {
			$formatString = "yyyyMMdd'T'HHmmSS'Z'";
		}
		elseif ($type == Streamer::STREAMER_TYPE_DATE_DASHES) {
			$formatString = "yyyy-MM-dd'T'HH:mm:SS'.000Z'";
		}

		$formatter = datefmt_create(
			'en_US',
			IntlDateFormatter::FULL,
			IntlDateFormatter::FULL,
			'UTC',
			IntlDateFormatter::GREGORIAN,
			$formatString
		);

		return datefmt_format($formatter, $ts);
	}

	/**
	 * Returns the appropriate SyncObjectResponse object based on message class.
	 *
	 * @param string $messageClass
	 *
	 * @return object
	 */
	public static function GetResponseFromMessageClass($messageClass) {
		$messageClass = strtolower($messageClass);

		switch ($messageClass) {
			case 'syncappointment':
				return new SyncAppointmentResponse();

			case 'synccontact':
				return new SyncContactResponse();

			case 'syncnote':
				return new SyncNoteResponse();

			case 'synctask':
				return new SyncTaskResponse();

			default:
				return new SyncMailResponse();
		}

		return new SyncMailResponse();
	}
}
