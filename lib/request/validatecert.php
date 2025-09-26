<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Provides the ValidateCert command
 */

class ValidateCert extends RequestProcessor {
	/**
	 * Handles the ValidateCert command.
	 *
	 * @param int $commandCode
	 *
	 * @return bool
	 */
	public function Handle($commandCode) {
		// Parse input
		if (!self::$decoder->getElementStartTag(SYNC_VALIDATECERT_VALIDATECERT)) {
			return false;
		}

		$validateCert = new SyncValidateCert();
		$validateCert->Decode(self::$decoder);
		$cert_der = base64_decode($validateCert->certificates[0]);
		$cert_pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($cert_der), 64, "\n") . "-----END CERTIFICATE-----\n";

		$checkpurpose = (defined('CAINFO') && CAINFO) ? openssl_x509_checkpurpose($cert_pem, X509_PURPOSE_SMIME_SIGN, [CAINFO]) : openssl_x509_checkpurpose($cert_pem, X509_PURPOSE_SMIME_SIGN);
		if ($checkpurpose === true) {
			$status = SYNC_VALIDATECERTSTATUS_SUCCESS;
		}
		else {
			$status = SYNC_VALIDATECERTSTATUS_CANTVALIDATESIG;
		}

		if (!self::$decoder->getElementEndTag()) {
			return false;
		} // SYNC_VALIDATECERT_VALIDATECERT

		self::$encoder->startWBXML();
		self::$encoder->startTag(SYNC_VALIDATECERT_VALIDATECERT);

		self::$encoder->startTag(SYNC_VALIDATECERT_STATUS);
		self::$encoder->content($status);
		self::$encoder->endTag(); // SYNC_VALIDATECERT_STATUS

		self::$encoder->startTag(SYNC_VALIDATECERT_CERTIFICATE);
		self::$encoder->startTag(SYNC_VALIDATECERT_STATUS);
		self::$encoder->content($status);
		self::$encoder->endTag(); // SYNC_VALIDATECERT_STATUS
		self::$encoder->endTag(); // SYNC_VALIDATECERT_CERTIFICATE

		self::$encoder->endTag(); // SYNC_VALIDATECERT_VALIDATECERT

		return true;
	}
}
