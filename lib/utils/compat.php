<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020 grommunio GmbH
 *
 * Help function for files
 */

if (!function_exists('apache_request_headers')) {
    /**
      * When using other webservers or using php as cgi in apache
      * the function apache_request_headers() is not available.
      * This function parses the environment variables to extract
      * the necessary headers for grommunio-sync
      */
    function apache_request_headers() {
        $headers = array();
        foreach ($_SERVER as $key => $value)
            if (substr($key, 0, 5) == 'HTTP_')
                $headers[strtr(substr($key, 5), '_', '-')] = $value;

        return $headers;
    }
}

if (!function_exists('hex2bin')) {
    /**
     * Complementary function to bin2hex() which converts a hex entryid to a binary entryid.
     * Since PHP 5.4 an internal hex2bin() implementation is available.
     *
     * @param string    $data   the hexadecimal string
     *
     * @returns string
     */
    function hex2bin($data) {
        return pack("H*", $data);
    }
}

if (!function_exists('http_response_code')) {
    /**
     * http_response_code does not exists in PHP < 5.4
     * http://php.net/manual/en/function.http-response-code.php
     */
    function http_response_code($code = NULL) {
        if ($code !== NULL) {
            switch ($code) {
                case 100: $text = 'Continue'; break;
                case 101: $text = 'Switching Protocols'; break;
                case 200: $text = 'OK'; break;
                case 201: $text = 'Created'; break;
                case 202: $text = 'Accepted'; break;
                case 203: $text = 'Non-Authoritative Information'; break;
                case 204: $text = 'No Content'; break;
                case 205: $text = 'Reset Content'; break;
                case 206: $text = 'Partial Content'; break;
                case 300: $text = 'Multiple Choices'; break;
                case 301: $text = 'Moved Permanently'; break;
                case 302: $text = 'Moved Temporarily'; break;
                case 303: $text = 'See Other'; break;
                case 304: $text = 'Not Modified'; break;
                case 305: $text = 'Use Proxy'; break;
                case 400: $text = 'Bad Request'; break;
                case 401: $text = 'Unauthorized'; break;
                case 402: $text = 'Payment Required'; break;
                case 403: $text = 'Forbidden'; break;
                case 404: $text = 'Not Found'; break;
                case 405: $text = 'Method Not Allowed'; break;
                case 406: $text = 'Not Acceptable'; break;
                case 407: $text = 'Proxy Authentication Required'; break;
                case 408: $text = 'Request Time-out'; break;
                case 409: $text = 'Conflict'; break;
                case 410: $text = 'Gone'; break;
                case 411: $text = 'Length Required'; break;
                case 412: $text = 'Precondition Failed'; break;
                case 413: $text = 'Request Entity Too Large'; break;
                case 414: $text = 'Request-URI Too Large'; break;
                case 415: $text = 'Unsupported Media Type'; break;
                case 500: $text = 'Internal Server Error'; break;
                case 501: $text = 'Not Implemented'; break;
                case 502: $text = 'Bad Gateway'; break;
                case 503: $text = 'Service Unavailable'; break;
                case 504: $text = 'Gateway Time-out'; break;
                case 505: $text = 'HTTP Version not supported'; break;
                default:
                    exit('Unknown http status code "' . htmlentities($code) . '"');
                    break;
            }

            $protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
            header($protocol . ' ' . $code . ' ' . $text);

            $GLOBALS['http_response_code'] = $code;
        } else {
            $code = (isset($GLOBALS['http_response_code']) ? $GLOBALS['http_response_code'] : 200);
        }

        return $code;
    }
}

if (!function_exists('memory_get_peak_usage')) {
    /**
     * memory_get_peak_usage is not available prior to PHP 5.2.
     * This complementary function will return the value of memory_get_usage();
     * @see http://php.net/manual/en/function.memory-get-usage.php
     * @see http://php.net/manual/en/function.memory-get-peak-usage.php
     *
     * @param boolean $real_usage
     */
    function memory_get_peak_usage($real_usage = false) {
        ZLog::Write(LOGLEVEL_DEBUG, "memory_get_peak_usage() is not available on this system. The value of memory_get_usage() will be used.");
        return memory_get_usage();
    }

}
