<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * WBXML definitions
 */

define('EN_TYPE', 1);
define('EN_TAG', 2);
define('EN_CONTENT', 3);
define('EN_FLAGS', 4);
define('EN_ATTRIBUTES', 5);

define('EN_TYPE_STARTTAG', 1);
define('EN_TYPE_ENDTAG', 2);
define('EN_TYPE_CONTENT', 3);

define('EN_FLAGS_CONTENT', 1);
define('EN_FLAGS_ATTRIBUTES', 2);

class WBXMLDefs {
	public const WBXML_SWITCH_PAGE = 0x00;
	public const WBXML_END = 0x01;
	public const WBXML_ENTITY = 0x02; // not used in ActiveSync
	public const WBXML_STR_I = 0x03;
	public const WBXML_LITERAL = 0x04; // not used in ActiveSync
	public const WBXML_EXT_I_0 = 0x40; // not used in ActiveSync
	public const WBXML_EXT_I_1 = 0x41; // not used in ActiveSync
	public const WBXML_EXT_I_2 = 0x42; // not used in ActiveSync
	public const WBXML_PI = 0x43; // not used in ActiveSync
	public const WBXML_LITERAL_C = 0x44; // not used in ActiveSync
	public const WBXML_EXT_T_0 = 0x80; // not used in ActiveSync
	public const WBXML_EXT_T_1 = 0x81; // not used in ActiveSync
	public const WBXML_EXT_T_2 = 0x82; // not used in ActiveSync
	public const WBXML_STR_T = 0x83; // not used in ActiveSync
	public const WBXML_LITERAL_A = 0x84; // not used in ActiveSync
	public const WBXML_EXT_0 = 0xC0; // not used in ActiveSync
	public const WBXML_EXT_1 = 0xC1; // not used in ActiveSync
	public const WBXML_EXT_2 = 0xC2; // not used in ActiveSync
	public const WBXML_OPAQUE = 0xC3;
	public const WBXML_LITERAL_AC = 0xC4; // not used in ActiveSync
	public const WBXML_WITH_ATTRIBUTES = 0x80; // not used in ActiveSync
	public const WBXML_WITH_CONTENT = 0x40;

	/**
	 * The WBXML DTDs.
	 */
	protected $dtd = [
		"codes" => [
			// AirSync
			0 => [
				0x05 => "Synchronize",
				0x06 => "Replies", // Responses
				0x07 => "Add",
				0x08 => "Modify", // Change
				0x09 => "Remove", // Delete
				0x0A => "Fetch",
				0x0B => "SyncKey",
				0x0C => "ClientEntryId", // ClientId
				0x0D => "ServerEntryId", // ServerId
				0x0E => "Status",
				0x0F => "Folder", // collection
				0x10 => "FolderType", // class
				0x11 => "Version", // deprecated
				0x12 => "FolderId", // CollectionId
				0x13 => "GetChanges",
				0x14 => "MoreAvailable",
				0x15 => "WindowSize", // WindowSize - MaxItems before version 2
				0x16 => "Perform", // Commands
				0x17 => "Options",
				0x18 => "FilterType",
				0x19 => "Truncation", // 2.0 and 2.5
				0x1A => "RtfTruncation", // 2.0 and 2.5
				0x1B => "Conflict",
				0x1C => "Folders", // Collections
				0x1D => "Data",
				0x1E => "DeletesAsMoves",
				0x1F => "NotifyGUID", // 2.0 and 2.5
				0x20 => "Supported",
				0x21 => "SoftDelete",
				0x22 => "MIMESupport",
				0x23 => "MIMETruncation",
				0x24 => "Wait", // Since 12.1
				0x25 => "Limit", // Since 12.1
				0x26 => "Partial", // Since 12.1
				0x27 => "ConversationMode", // Since 14.0
				0x28 => "MaxItems", // Since 14.0
				0x29 => "HeartbeatInterval", // Since 14.0 Either this tag or the Wait tag can be present, but not both.
			],
			// POOMCONTACTS
			1 => [
				0x05 => "Anniversary",
				0x06 => "AssistantName",
				0x07 => "AssistnamePhoneNumber", // AssistantTelephoneNumber
				0x08 => "Birthday",
				0x09 => "Body", // 2.5, AirSyncBase Body is used since version 12.0
				0x0A => "BodySize", // 2.0 and 2.5, AirSyncBase is used since version 12.0
				0x0B => "BodyTruncated", // 2.0 and 2.5, AirSyncBase is used since version 12.0
				0x0C => "Business2PhoneNumber",
				0x0D => "BusinessCity",
				0x0E => "BusinessCountry",
				0x0F => "BusinessPostalCode",
				0x10 => "BusinessState",
				0x11 => "BusinessStreet",
				0x12 => "BusinessFaxNumber",
				0x13 => "BusinessPhoneNumber",
				0x14 => "CarPhoneNumber",
				0x15 => "Categories",
				0x16 => "Category",
				0x17 => "Children",
				0x18 => "Child",
				0x19 => "CompanyName",
				0x1A => "Department",
				0x1B => "Email1Address",
				0x1C => "Email2Address",
				0x1D => "Email3Address",
				0x1E => "FileAs",
				0x1F => "FirstName",
				0x20 => "Home2PhoneNumber",
				0x21 => "HomeCity",
				0x22 => "HomeCountry",
				0x23 => "HomePostalCode",
				0x24 => "HomeState",
				0x25 => "HomeStreet",
				0x26 => "HomeFaxNumber",
				0x27 => "HomePhoneNumber",
				0x28 => "JobTitle",
				0x29 => "LastName",
				0x2A => "MiddleName",
				0x2B => "MobilePhoneNumber",
				0x2C => "OfficeLocation",
				0x2D => "OtherCity",
				0x2E => "OtherCountry",
				0x2F => "OtherPostalCode",
				0x30 => "OtherState",
				0x31 => "OtherStreet",
				0x32 => "PagerNumber",
				0x33 => "RadioPhoneNumber",
				0x34 => "Spouse",
				0x35 => "Suffix",
				0x36 => "Title",
				0x37 => "WebPage",
				0x38 => "YomiCompanyName",
				0x39 => "YomiFirstName",
				0x3A => "YomiLastName",
				0x3B => "Rtf", // CompressedRTF - 2.5, deprecated
				0x3C => "Picture",
				0x3D => "Alias", // Since 14.0
				0x3E => "WeightedRank", // Since 14.0
			],
			// POOMMAIL
			2 => [
				0x05 => "Attachment", // AirSyncBase Attachments is used since 12.0
				0x06 => "Attachments", // AirSyncBase Attachments is used since 12.0
				0x07 => "AttName", // AirSyncBase Attachments is used since 12.0
				0x08 => "AttSize", // AirSyncBase Attachments is used since 12.0
				0x09 => "AttOid", // AirSyncBase Attachments is used since 12.0
				0x0A => "AttMethod", // AirSyncBase Attachments is used since 12.0
				0x0B => "AttRemoved", // AirSyncBase Attachments is used since 12.0
				0x0C => "Body", // AirSyncBase Body is used since 12.0
				0x0D => "BodySize", // AirSyncBase Body is used since 12.0
				0x0E => "BodyTruncated", // AirSyncBase Body is used since 12.0
				0x0F => "DateReceived",
				0x10 => "DisplayName", // AirSyncBase Attachments is used since 12.0
				0x11 => "DisplayTo",
				0x12 => "Importance",
				0x13 => "MessageClass",
				0x14 => "Subject",
				0x15 => "Read",
				0x16 => "To",
				0x17 => "Cc",
				0x18 => "From",
				0x19 => "Reply-To",
				0x1A => "AllDayEvent",
				0x1B => "Categories", // Since 14.0
				0x1C => "Category", // Since 14.0
				0x1D => "DtStamp",
				0x1E => "EndTime",
				0x1F => "InstanceType",
				0x20 => "BusyStatus",
				0x21 => "Location", // 2.5, 12.0, 12.1, 14.0 and 14.1. Since 16.0 AirSyncBase Location is used.
				0x22 => "MeetingRequest",
				0x23 => "Organizer",
				0x24 => "RecurrenceId",
				0x25 => "Reminder",
				0x26 => "ResponseRequested",
				0x27 => "Recurrences",
				0x28 => "Recurrence",
				0x29 => "Type",
				0x2A => "Until",
				0x2B => "Occurrences",
				0x2C => "Interval",
				0x2D => "DayOfWeek",
				0x2E => "DayOfMonth",
				0x2F => "WeekOfMonth",
				0x30 => "MonthOfYear",
				0x31 => "StartTime",
				0x32 => "Sensitivity",
				0x33 => "TimeZone",
				0x34 => "GlobalObjId", // 2.5, 12.0, 12.1, 14.0 and 14.1. UID of Calendar (Code page 4) is used since 16.0
				0x35 => "ThreadTopic",
				0x36 => "MIMEData", // 2.5
				0x37 => "MIMETruncated", // 2.5
				0x38 => "MIMESize", // 2.5
				0x39 => "InternetCPID",
				0x3A => "Flag", // Since 12.0
				0x3B => "FlagStatus", // Since 12.0
				0x3C => "ContentClass", // Since 12.0
				0x3D => "FlagType", // Since 12.0
				0x3E => "CompleteTime", // Since 12.0
				0x3F => "DisallowNewTimeProposal", // Since 14.0
			],
			// AirNotify
			3 => [ // Code page 3 is no longer in use, however, tokens 05 through 17 have been defined. 20100501
				0x05 => "Notify",
				0x06 => "Notification",
				0x07 => "Version",
				0x08 => "Lifetime",
				0x09 => "DeviceInfo",
				0x0A => "Enable",
				0x0B => "Folder",
				0x0C => "ServerEntryId",
				0x0D => "DeviceAddress",
				0x0E => "ValidCarrierProfiles",
				0x0F => "CarrierProfile",
				0x10 => "Status",
				0x11 => "Replies",
				//                        0x05 => "Version='1.1'",
				0x12 => "Devices",
				0x13 => "Device",
				0x14 => "Id",
				0x15 => "Expiry",
				0x16 => "NotifyGUID",
			],
			// POOMCAL
			4 => [
				0x05 => "Timezone",
				0x06 => "AllDayEvent",
				0x07 => "Attendees",
				0x08 => "Attendee",
				0x09 => "Email",
				0x0A => "Name",
				0x0B => "Body", // AirSyncBase Body is used since 12.0
				0x0C => "BodyTruncated", // AirSyncBase Body is used since 12.0
				0x0D => "BusyStatus",
				0x0E => "Categories",
				0x0F => "Category",
				0x10 => "Rtf", // 2.5 - deprecated
				0x11 => "DtStamp",
				0x12 => "EndTime",
				0x13 => "Exception",
				0x14 => "Exceptions",
				0x15 => "Deleted",
				0x16 => "ExceptionStartTime", // 2.5, 12.0, 12.1, 14.0 and 14.1.
				0x17 => "Location", // 2.5, 12.0, 12.1, 14.0 and 14.1. Since 16.0 AirSyncBase Location is used.
				0x18 => "MeetingStatus",
				0x19 => "OrganizerEmail",
				0x1A => "OrganizerName",
				0x1B => "Recurrence",
				0x1C => "Type",
				0x1D => "Until",
				0x1E => "Occurrences",
				0x1F => "Interval",
				0x20 => "DayOfWeek",
				0x21 => "DayOfMonth",
				0x22 => "WeekOfMonth",
				0x23 => "MonthOfYear",
				0x24 => "Reminder",
				0x25 => "Sensitivity",
				0x26 => "Subject",
				0x27 => "StartTime",
				0x28 => "UID",
				0x29 => "Attendee_Status", // Since 12.0
				0x2A => "Attendee_Type", // Since 12.0
				0x2B => "Attachment", // Not defined / deprecated
				0x2C => "Attachments", // Not defined / deprecated
				0x2D => "AttName", // Not defined / deprecated
				0x2E => "AttSize", // Not defined / deprecated
				0x2F => "AttOid", // Not defined / deprecated
				0x30 => "AttMethod", // Not defined / deprecated
				0x31 => "AttRemoved", // Not defined / deprecated
				0x32 => "DisplayName", // Not defined / deprecated
				0x33 => "DisallowNewTimeProposal", // Since 14.0
				0x34 => "ResponseRequested", // Since 14.0
				0x35 => "AppointmentReplyTime", // Since 14.0
				0x36 => "ResponseType", // Since 14.0
				0x37 => "CalendarType", // Since 14.0
				0x38 => "IsLeapMonth", // Since 14.0
				0x39 => "FirstDayOfWeek", // Since 14.1
				0x3A => "OnlineMeetingConfLink", // Since 14.1
				0x3B => "OnlineMeetingExternalLink", // Since 14.1
				0x3C => "ClientUid", // Since 16.0
			],
			// Move
			5 => [
				0x05 => "Moves",
				0x06 => "Move",
				0x07 => "SrcMsgId",
				0x08 => "SrcFldId",
				0x09 => "DstFldId",
				0x0A => "Response",
				0x0B => "Status",
				0x0C => "DstMsgId",
			],
			// GetItemEstimate
			6 => [
				0x05 => "GetItemEstimate",
				0x06 => "Version", // deprecated
				0x07 => "Folders", // Collections
				0x08 => "Folder", // Collection
				0x09 => "FolderType", // AirSync Class(SYNC_FOLDERTYPE) is used since AS 14.0
				0x0A => "FolderId", // CollectionId
				0x0B => "DateTime", // deprecated
				0x0C => "Estimate",
				0x0D => "Response",
				0x0E => "Status",
			],
			// FolderHierarchy
			7 => [
				0x05 => "Folders", // 2.5, 12.0 and 12.1
				0x06 => "Folder", // 2.5, 12.0 and 12.1
				0x07 => "DisplayName",
				0x08 => "ServerEntryId", // ServerId
				0x09 => "ParentId",
				0x0A => "Type",
				0x0B => "Response", // deprecated
				0x0C => "Status",
				0x0D => "ContentClass", // deprecated
				0x0E => "Changes",
				0x0F => "Add",
				0x10 => "Remove",
				0x11 => "Update",
				0x12 => "SyncKey",
				0x13 => "FolderCreate",
				0x14 => "FolderDelete",
				0x15 => "FolderUpdate",
				0x16 => "FolderSync",
				0x17 => "Count",
				0x18 => "Version", // 2.0 - not defined in 20100501
			],
			// MeetingResponse
			8 => [
				0x05 => "CalendarId",
				0x06 => "FolderId", // CollectionId
				0x07 => "MeetingResponse",
				0x08 => "RequestId",
				0x09 => "Request",
				0x0A => "Result",
				0x0B => "Status",
				0x0C => "UserResponse",
				0x0D => "Version", // 2.0 - not defined in 20100501
				0x0E => "InstanceId", // Since AS 14.1
				0x10 => "ProposedStartTime", // Since 16.1
				0x11 => "ProposedEndTime", // Since 16.1
				0x12 => "SendResponse", // Since 16.0
			],
			// POOMTASKS
			9 => [
				0x05 => "Body", // AirSyncBase Body is used since 12.0
				0x06 => "BodySize", // AirSyncBase Body is used since 12.0
				0x07 => "BodyTruncated", // AirSyncBase Body is used since 12.0
				0x08 => "Categories",
				0x09 => "Category",
				0x0A => "Complete",
				0x0B => "DateCompleted",
				0x0C => "DueDate",
				0x0D => "UtcDueDate",
				0x0E => "Importance",
				0x0F => "Recurrence",
				0x10 => "Type",
				0x11 => "Start",
				0x12 => "Until",
				0x13 => "Occurrences",
				0x14 => "Interval",
				0x15 => "DayOfMonth",
				0x16 => "DayOfWeek",
				0x17 => "WeekOfMonth",
				0x18 => "MonthOfYear",
				0x19 => "Regenerate",
				0x1A => "DeadOccur",
				0x1B => "ReminderSet",
				0x1C => "ReminderTime",
				0x1D => "Sensitivity",
				0x1E => "StartDate",
				0x1F => "UtcStartDate",
				0x20 => "Subject",
				0x21 => "Rtf", // deprecated
				0x22 => "OrdinalDate", // Since 12.0
				0x23 => "SubOrdinalDate", // Since 12.0
				0x24 => "CalendarType", // Since 14.0
				0x25 => "IsLeapMonth", // Since 14.0
				0x26 => "FirstDayOfWeek", // Since 14.1
			],
			// ResolveRecipients
			0xA => [
				0x05 => "ResolveRecipients",
				0x06 => "Response",
				0x07 => "Status",
				0x08 => "Type",
				0x09 => "Recipient",
				0x0A => "DisplayName",
				0x0B => "EmailAddress",
				0x0C => "Certificates",
				0x0D => "Certificate",
				0x0E => "MiniCertificate",
				0x0F => "Options",
				0x10 => "To",
				0x11 => "CertificateRetrieval",
				0x12 => "RecipientCount",
				0x13 => "MaxCertificates",
				0x14 => "MaxAmbiguousRecipients",
				0x15 => "CertificateCount",
				0x16 => "Availability", // Since 14.0
				0x17 => "StartTime", // Since 14.0
				0x18 => "EndTime", // Since 14.0
				0x19 => "MergedFreeBusy", // Since 14.0
				0x1A => "Picture", // Since 14.1
				0x1B => "MaxSize", // Since 14.1
				0x1C => "Data", // Since 14.1
				0x1D => "MaxPictures", // Since 14.1
			],
			// ValidateCert
			0xB => [
				0x05 => "ValidateCert",
				0x06 => "Certificates",
				0x07 => "Certificate",
				0x08 => "CertificateChain",
				0x09 => "CheckCRL",
				0x0A => "Status",
			],
			// POOMCONTACTS2
			0xC => [
				0x05 => "CustomerId",
				0x06 => "GovernmentId",
				0x07 => "IMAddress",
				0x08 => "IMAddress2",
				0x09 => "IMAddress3",
				0x0A => "ManagerName",
				0x0B => "CompanyMainPhone",
				0x0C => "AccountName",
				0x0D => "NickName",
				0x0E => "MMS",
			],
			// Ping
			0xD => [
				0x05 => "Ping",
				0x06 => "AutdState", // (Not used by protocol)
				0x07 => "Status",
				0x08 => "LifeTime", // HeartbeatInterval
				0x09 => "Folders",
				0x0A => "Folder",
				0x0B => "ServerEntryId", // Id
				0x0C => "FolderType", // Class
				0x0D => "MaxFolders",
				0x0E => "Version", // not defined / deprecated
			],
			// Provision
			0xE => [
				0x05 => "Provision",
				0x06 => "Policies",
				0x07 => "Policy",
				0x08 => "PolicyType",
				0x09 => "PolicyKey",
				0x0A => "Data",
				0x0B => "Status",
				0x0C => "RemoteWipe",
				0x0D => "EASProvisionDoc", // Since 12.0
				0x0E => "DevicePasswordEnabled", // Since 12.0
				0x0F => "AlphanumericDevicePasswordRequired", // Since 12.0
				// 0x10 => "DeviceEncryptionEnabled", // Since 12.1
				0x10 => "RequireStorageCardEncryption", // Since 12.1
				0x11 => "PasswordRecoveryEnabled", // Since 12.0
				0x12 => "DocumentBrowseEnabled", // not defined / deprecated
				0x13 => "AttachmentsEnabled", // Since 12.0
				0x14 => "MinDevicePasswordLength", // Since 12.0
				0x15 => "MaxInactivityTimeDeviceLock", // Since 12.0
				0x16 => "MaxDevicePasswordFailedAttempts", // Since 12.0
				0x17 => "MaxAttachmentSize", // Since 12.0
				0x18 => "AllowSimpleDevicePassword", // Since 12.0
				0x19 => "DevicePasswordExpiration", // Since 12.0
				0x1A => "DevicePasswordHistory", // Since 12.0
				0x1B => "AllowStorageCard", // Since 12.1
				0x1C => "AllowCamera", // Since 12.1
				0x1D => "RequireDeviceEncryption", // Since 12.1
				0x1E => "AllowUnsignedApplications", // Since 12.1
				0x1F => "AllowUnsignedInstallationPackages", // Since 12.1
				0x20 => "MinDevicePasswordComplexCharacters", // Since 12.1
				0x21 => "AllowWiFi", // Since 12.1
				0x22 => "AllowTextMessaging", // Since 12.1
				0x23 => "AllowPOPIMAPEmail", // Since 12.1
				0x24 => "AllowBluetooth", // Since 12.1
				0x25 => "AllowIrDA", // Since 12.1
				0x26 => "RequireManualSyncWhenRoaming", // Since 12.1
				0x27 => "AllowDesktopSync", // Since 12.1
				0x28 => "MaxCalendarAgeFilter", // Since 12.1
				0x29 => "AllowHTMLEmail", // Since 12.1
				0x2A => "MaxEmailAgeFilter", // Since 12.1
				0x2B => "MaxEmailBodyTruncationSize", // Since 12.1
				0x2C => "MaxEmailHTMLBodyTruncationSize", // Since 12.1
				0x2D => "RequireSignedSMIMEMessages", // Since 12.1
				0x2E => "RequireEncryptedSMIMEMessages", // Since 12.1
				0x2F => "RequireSignedSMIMEAlgorithm", // Since 12.1
				0x30 => "RequireEncryptionSMIMEAlgorithm", // Since 12.1
				0x31 => "AllowSMIMEEncryptionAlgorithmNegotiation", // Since 12.1
				0x32 => "AllowSMIMESoftCerts", // Since 12.1
				0x33 => "AllowBrowser", // Since 12.1
				0x34 => "AllowConsumerEmail", // Since 12.1
				0x35 => "AllowRemoteDesktop", // Since 12.1
				0x36 => "AllowInternetSharing", // Since 12.1
				0x37 => "UnapprovedInROMApplicationList", // Since 12.1
				0x38 => "ApplicationName", // Since 12.1
				0x39 => "ApprovedApplicationList", // Since 12.1
				0x3A => "Hash", // Since 12.1
				0x3B => "AccountOnlyRemoteWipe", // Since 16.1
			],
			// Search
			0xF => [
				0x05 => "Search",
				0x07 => "Store",
				0x08 => "Name",
				0x09 => "Query",
				0x0A => "Options",
				0x0B => "Range",
				0x0C => "Status",
				0x0D => "Response",
				0x0E => "Result",
				0x0F => "Properties",
				0x10 => "Total",
				0x11 => "EqualTo",
				0x12 => "Value",
				0x13 => "And",
				0x14 => "Or",
				0x15 => "FreeText",
				0x17 => "DeepTraversal",
				0x18 => "LongId",
				0x19 => "RebuildResults",
				0x1A => "LessThan",
				0x1B => "GreaterThan",
				0x1C => "Schema",
				0x1D => "Supported",
				0x1E => "UserName", // Since 12.1
				0x1F => "Password", // Since 12.1
				0x20 => "ConversationId", // Since 14.0
				0x21 => "Picture", // Since 14.1
				0x22 => "MaxSize", // Since 14.1
				0x23 => "MaxPictures", // Since 14.1
			],
			// GAL
			0x10 => [
				0x05 => "DisplayName",
				0x06 => "Phone",
				0x07 => "Office",
				0x08 => "Title",
				0x09 => "Company",
				0x0A => "Alias",
				0x0B => "FirstName",
				0x0C => "LastName",
				0x0D => "HomePhone",
				0x0E => "MobilePhone",
				0x0F => "EmailAddress",
				0x10 => "Picture", // Since 14.1
				0x11 => "Status", // Since 14.1
				0x12 => "Data", // Since 14.1
			],
			// AirSyncBase
			0x11 => [ // Since 12.0
				0x05 => "BodyPreference",
				0x06 => "Type",
				0x07 => "TruncationSize",
				0x08 => "AllOrNone",
				0x0A => "Body",
				0x0B => "Data",
				0x0C => "EstimatedDataSize",
				0x0D => "Truncated",
				0x0E => "Attachments",
				0x0F => "Attachment",
				0x10 => "DisplayName",
				0x11 => "FileReference",
				0x12 => "Method",
				0x13 => "ContentId",
				0x14 => "ContentLocation",
				0x15 => "IsInline",
				0x16 => "NativeBodyType",
				0x17 => "ContentType",
				0x18 => "Preview", // Since 14.0
				0x19 => "BodyPartPreference", // Since 14.1
				0x1A => "BodyPart", // Since 14.1
				0x1B => "Status", // Since 14.1
				0x1C => "Add", // Since 16.0
				0x1D => "Delete", // Since 16.0
				0x1E => "ClientId", // Since 16.0
				0x1F => "Content", // Since 16.0
				0x20 => "Location", // Since 16.0
				0x21 => "Annotation", // Since 16.0
				0x22 => "Street", // Since 16.0
				0x23 => "City", // Since 16.0
				0x24 => "State", // Since 16.0
				0x25 => "Country", // Since 16.0
				0x26 => "PostalCode", // Since 16.0
				0x27 => "Latitude", // Since 16.0
				0x28 => "Longitude", // Since 16.0
				0x29 => "Accuracy", // Since 16.0
				0x2A => "Altitude", // Since 16.0
				0x2B => "AltitudeAccuracy", // Since 16.0
				0x2C => "LocationUri", // Since 16.0
				0x2D => "InstanceId", // Since 16.0
			],
			// Settings
			0x12 => [ // Since 12.0
				0x05 => "Settings",
				0x06 => "Status",
				0x07 => "Get",
				0x08 => "Set",
				0x09 => "Oof",
				0x0A => "OofState",
				0x0B => "StartTime",
				0x0C => "EndTime",
				0x0D => "OofMessage",
				0x0E => "AppliesToInternal",
				0x0F => "AppliesToExternalKnown",
				0x10 => "AppliesToExternalUnknown",
				0x11 => "Enabled",
				0x12 => "ReplyMessage",
				0x13 => "BodyType",
				0x14 => "DevicePassword",
				0x15 => "Password",
				0x16 => "DeviceInformation",
				0x17 => "Model",
				0x18 => "IMEI",
				0x19 => "FriendlyName",
				0x1A => "OS",
				0x1B => "OSLanguage",
				0x1C => "PhoneNumber",
				0x1D => "UserInformation",
				0x1E => "EmailAddresses",
				0x1F => "SmtpAddress",
				0x20 => "UserAgent", // Since 12.1
				0x21 => "EnableOutboundSMS", // Since 14.0
				0x22 => "MobileOperator", // Since 14.0
				0x23 => "PrimarySmtpAddress", // Since 14.1
				0x24 => "Accounts", // Since 14.1
				0x25 => "Account", // Since 14.1
				0x26 => "AccountId", // Since 14.1
				0x27 => "AccountName", // Since 14.1
				0x28 => "UserDisplayName", // Since 14.1
				0x29 => "SendDisabled", // Since 14.1
				0x2B => "RightsManagementInformation", // Since 14.1
			],
			// DocumentLibrary
			0x13 => [ // Since 12.0
				0x05 => "LinkId",
				0x06 => "DisplayName",
				0x07 => "IsFolder",
				0x08 => "CreationDate",
				0x09 => "LastModifiedDate",
				0x0A => "IsHidden",
				0x0B => "ContentLength",
				0x0C => "ContentType",
			],
			// ItemOperations
			0x14 => [ // Since 12.0
				0x05 => "ItemOperations",
				0x06 => "Fetch",
				0x07 => "Store",
				0x08 => "Options",
				0x09 => "Range",
				0x0A => "Total",
				0x0B => "Properties",
				0x0C => "Data",
				0x0D => "Status",
				0x0E => "Response",
				0x0F => "Version",
				0x10 => "Schema",
				0x11 => "Part",
				0x12 => "EmptyFolderContents",
				0x13 => "DeleteSubFolders",
				0x14 => "UserName", // Since 12.1
				0x15 => "Password", // Since 12.1
				0x16 => "Move", // Since 14.0
				0x17 => "DstFldId", // Since 14.0
				0x18 => "ConversationId", // Since 14.0
				0x19 => "MoveAlways", // Since 14.0
			],
			// ComposeMail
			0x15 => [ // Since 14.0
				0x05 => "SendMail",
				0x06 => "SmartForward",
				0x07 => "SmartReply",
				0x08 => "SaveInSentItems",
				0x09 => "ReplaceMime",
				0x0A => "Type", // not used
				0x0B => "Source",
				0x0C => "FolderId",
				0x0D => "ItemId",
				0x0E => "LongId",
				0x0F => "InstanceId",
				0x10 => "MIME",
				0x11 => "ClientId",
				0x12 => "Status",
				0x13 => "AccountId", // Since 14.1
				0x15 => "Forwardees", // Since 16.0
				0x16 => "Forwardee", // Since 16.0
				0x17 => "Name", // Since 16.0
				0x18 => "Email", // Since 16.0
			],
			// POOMMAIL2
			0x16 => [ // Since 14.0
				0x05 => "UmCallerId",
				0x06 => "UmUserNotes",
				0x07 => "UmAttDuration",
				0x08 => "UmAttOrder",
				0x09 => "ConversationId",
				0x0A => "ConversationIndex",
				0x0B => "LastVerbExecuted",
				0x0C => "LastVerbExecutionTime",
				0x0D => "ReceivedAsBcc",
				0x0E => "Sender",
				0x0F => "CalendarType",
				0x10 => "IsLeapMonth",
				0x11 => "AccountId", // Since 14.1
				0x12 => "FirstDayOfWeek", // Since 14.1
				0x13 => "MeetingMessageType", // Since 14.1
				0x15 => "IsDraft", // Since 16.0
				0x16 => "Bcc", // Since 16.0
				0x17 => "Send", // Since 16.0
			],
			// Notes
			0x17 => [ // Since 14.0
				0x05 => "Subject",
				0x06 => "MessageClass",
				0x07 => "LastModifiedDate",
				0x08 => "Categories",
				0x09 => "Category",
			],
			// RightsManagement
			0x18 => [ // Since 14.1
				0x05 => "RightsManagementSupport",
				0x06 => "RightsManagementTemplates",
				0x07 => "RightsManagementTemplate",
				0x08 => "RightsManagementLicense",
				0x09 => "EditAllowed",
				0x0A => "ReplyAllowed",
				0x0B => "ReplyAllAllowed",
				0x0C => "ForwardAllowed",
				0x0D => "ModifyRecipientsAllowed",
				0x0E => "ExtractAllowed",
				0x0F => "PrintAllowed",
				0x10 => "ExportAllowed",
				0x11 => "ProgrammaticAccessAllowed",
				0x12 => "Owner",
				0x13 => "ContentExpiryDate",
				0x14 => "TemplateID",
				0x15 => "TemplateName",
				0x16 => "TemplateDescription",
				0x17 => "ContentOwner",
				0x18 => "RemoveRightsManagementProtection",
			],
			// FIND
			0x19 => [ // Since 16.1
				0x05 => "Find", // Since 16.1
				0x06 => "SearchId", // Since 16.1
				0x07 => "ExecuteSearch", // Since 16.1
				0x08 => "MailBoxSearchCriterion", // Since 16.1
				0x09 => "Query", // Since 16.1
				0x0A => "Status", // Since 16.1
				0x0B => "FreeText", // Since 16.1
				0x0C => "Options", // Since 16.1
				0x0D => "Range", // Since 16.1
				0x0E => "DeepTraversal", // SinceE 16.1
				0x11 => "Response", // Since 16.1
				0x12 => "Result", // Since 16.1
				0x13 => "Properties", // Since 16.1
				0x14 => "Preview", // Since 16.1
				0x15 => "HasAttachments", // Since 16.1
				0x16 => "Total", // Since 16.1
				0x17 => "DisplayCc", // Since 16.1
				0x18 => "DisplayBcc", // Since 16.1
				0x19 => "GalSearchCriterion", // Since 16.1
				0x20 => "MaxPictures", // Since 16.1
				0x21 => "MaxSize", // Since 16.1
				0x22 => "Picture", // Since 16.1
			],
		],
		"namespaces" => [
			// 0 => "AirSync", //
			1 => "POOMCONTACTS",
			2 => "POOMMAIL",
			3 => "AirNotify", // no longer used
			4 => "POOMCAL",
			5 => "Move",
			6 => "GetItemEstimate",
			7 => "FolderHierarchy",
			8 => "MeetingResponse",
			9 => "POOMTASKS",
			0xA => "ResolveRecipients",
			0xB => "ValidateCert",
			0xC => "POOMCONTACTS2",
			0xD => "Ping",
			0xE => "Provision",
			0xF => "Search",
			0x10 => "GAL",
			0x11 => "AirSyncBase", // 12.0, 12.1 and 14.0
			0x12 => "Settings", // 12.0, 12.1 and 14.0.
			0x13 => "DocumentLibrary", // 12.0, 12.1 and 14.0
			0x14 => "ItemOperations", // 12.0, 12.1 and 14.0
			0x15 => "ComposeMail", // 14.0
			0x16 => "POOMMAIL2", // 14.0
			0x17 => "Notes", // 14.0
			0x18 => "RightsManagement",
			0x19 => "Find", // 16.1
		],
	];
}
