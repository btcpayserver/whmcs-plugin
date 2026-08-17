<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2011-2018 BitPay, BTCPay server (c) 2019-2022
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

require_once __DIR__ . '/bp_options.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/bp_security.php';

/**
 * @param string $contents
 */
function bpLog($contents)
{
    error_log($contents);
}

/**
 * @param  string      $url
 * @param  string      $apiKey
 * @param  bool|string $post
 * @return array
 */
function bpCurl($url, $apiKey, $post = false)
{
    global $bpOptions;
    global $version;

    $curl = curl_init($url);
    if ($curl === false) {
        return array('error' => 'Unable to initialize the BTCPay request.', 'error_kind' => 'upstream');
    }

    $length = 0;
    if ($post !== false) {
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
        $length = strlen($post);
    }

    $uname  = base64_encode($apiKey);

    $header = array(
        'Content-Type: application/json',
        'Content-Length: ' . $length,
        'Authorization: Basic ' . $uname,
        'X-BitPay-Plugin-Info: whmcs '. $version,
    );

    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1); // verify certificate
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2); // check existence of CN and verify that it matches hostname
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
    curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);

    $responseString = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    $curlErrno = curl_errno($curl);
    
    if ($responseString === false) {
        bpLog('[ERROR] In modules/gateways/btcpay/bp_lib.php::bpCurl(): cURL request failed with errno ' . $curlErrno . ': ' . $curlError);
        $response = array('error' => 'The BTCPay Server request failed.', 'error_kind' => 'upstream');
    } elseif ($httpCode < 200 || $httpCode >= 300) {
        bpLog(
            '[ERROR] In modules/gateways/btcpay/bp_lib.php::bpCurl(): BTCPay Server returned HTTP ' .
            $httpCode . ' with body length ' . strlen($responseString) . ' and SHA-256 ' .
            hash('sha256', $responseString) . '.'
        );
        $response = array(
            'error' => 'BTCPay Server returned an unsuccessful HTTP status.',
            'error_kind' => 'upstream',
            'http_status' => $httpCode,
        );
    } else {
        try {
            $response = json_decode($responseString, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            bpLog(
                '[ERROR] In modules/gateways/btcpay/bp_lib.php::bpCurl(): Invalid JSON response for HTTP ' .
                $httpCode . ' with body length ' . strlen($responseString) . ' and SHA-256 ' .
                hash('sha256', $responseString) . '.'
            );
            $response = array('error' => 'BTCPay Server returned an invalid response.', 'error_kind' => 'upstream');
        }

        if (!is_array($response)) {
            $response = array('error' => 'BTCPay Server returned an invalid response.', 'error_kind' => 'upstream');
        }
    }

    curl_close($curl);

    return $response;
}

function getFullUri($baseUri, $path)
{
    $uriNormalized = rtrim($baseUri, '/');
    $pathNormalized = ltrim($path, '/');
    return sprintf(
        '%s/%s',
        $uriNormalized,
        $pathNormalized
    );
}

/**
 * $orderId: Used to display an orderID to the buyer. In the account summary view, this value is used to
 * identify a ledger entry if present.
 *
 * $price: by default, $price is expressed in the currency you set in bp_options.php.  The currency can be
 * changed in $options.
 *
 * $posData: this field is included in status updates or requests to get an invoice.  It is intended to be used by
 * the merchant to uniquely identify an order associated with an invoice in their system.  Aside from that, BitPay does
 * not use the data in this field.  The data in this field can be anything that is meaningful to the merchant.
 *
 * $options keys can include any of:
 * ('itemDesc', 'itemCode', 'notificationEmail', 'notificationURL', 'redirectURL', 'apiKey'
 *		'currency', 'physical', 'fullNotifications', 'transactionSpeed', 'buyerName',
 *		'buyerAddress1', 'buyerAddress2', 'buyerCity', 'buyerState', 'buyerZip', 'buyerEmail', 'buyerPhone')
 * If a given option is not provided here, the value of that option will default to what is found in bp_options.php
 * (see api documentation for information on these options).
 *
 * @param  string $orderId
 * @param  string $price
 * @param  string $posData
 * @param  array  $options
 * @return array
 */
function bpCreateInvoice($orderId, $price, $posData, $options = array())
{
    global $bpOptions;

    $options = array_merge($bpOptions, $options);    // $options override any options found in bp_options.php

    $encodedPosData = array('posData' => (string) $posData);

    // Retained only for callers that explicitly enable the deprecated legacy
    // POS hash. This plugin's callback does not use it for authentication.
    if ($bpOptions['verifyPos']) {
        $encodedPosData['hash'] = crypt((string) $posData, $options['apiKey']);
    }

    try {
        $options['posData'] = json_encode(
            $encodedPosData,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (Throwable $exception) {
        return array('error' => 'Unable to encode BTCPay invoice metadata.', 'error_kind' => 'local');
    }
    $options['orderId']  = (string) $orderId;
    $options['price']    = $price;

    $btcpayUrl = getFullUri($options['btcpayUrl'],"/invoices");

    $postOptions = array('orderId', 'itemDesc', 'itemCode', 'notificationEmail', 'notificationURL', 'redirectURL',
        'posData', 'price', 'currency', 'physical', 'fullNotifications', 'transactionSpeed', 'buyerName',
        'buyerAddress1', 'buyerAddress2', 'buyerCity', 'buyerState', 'buyerZip', 'buyerEmail', 'buyerPhone');

    $post = array();
    foreach ($postOptions as $o) {
        if (array_key_exists($o, $options)) {
            $post[$o] = $options[$o];
        }
    }

    try {
        $post = json_encode($post, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $exception) {
        return array('error' => 'Unable to encode the BTCPay invoice request.', 'error_kind' => 'local');
    }

    $response = bpCurl($btcpayUrl, $options['apiKey'], $post);

    return $response;
}

/**
 * Call from your notification handler to convert $_POST data to an object containing invoice data
 *
 * @param  string     $apiKey
 * @param  null       $btcpayUrl
 * @param  array|null $notification
 * @return array
 */
function bpVerifyNotification($apiKey = false, $btcpayUrl = null, $notification = null)
{
    global $bpOptions;

    if (!$apiKey) {
        $apiKey = $bpOptions['apiKey'];
    }

    if ($notification === null) {
        $post = file_get_contents('php://input', false, null, 0, BP_MAX_CALLBACK_BODY_BYTES + 1);
        if (!is_string($post) || $post === '' || strlen($post) > BP_MAX_CALLBACK_BODY_BYTES) {
            return array('error' => 'Invalid callback request body.', 'error_kind' => 'request');
        }

        try {
            $notification = json_decode($post, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return array('error' => 'Invalid callback JSON.', 'error_kind' => 'request');
        }
    }

    if (!is_array($notification)) {
        return array('error' => 'Invalid callback payload.', 'error_kind' => 'request');
    }

    // Treat the unauthenticated legacy IPN only as a wake-up signal. Its
    // invoice ID selects the record to re-fetch through the authenticated API;
    // status, amount, currency, order ID, and POS metadata are never trusted
    // from the posted payload.
    $validated = bpValidateLegacyNotificationPayload($notification);
    if (isset($validated['error'])) {
        return $validated;
    }

    $response = bpGetInvoice($validated['id'], $apiKey, $btcpayUrl);
    if (isset($response['error'])) {
        return $response;
    }

    return $response;
}

/**
 * @param mixed $invoiceId
 * @return bool
 */
function bpIsValidInvoiceIdentifier($invoiceId)
{
    return is_string($invoiceId) && $invoiceId !== '' && strlen($invoiceId) <= 128 &&
        preg_match('/^[A-Za-z0-9._~-]+$/D', $invoiceId) === 1;
}

/**
 * @param mixed $encoded
 * @param bool  $requireHash
 * @return array
 */
function bpDecodePosData($encoded, $requireHash)
{
    if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 4096) {
        return array('error' => 'Invalid posData.', 'error_kind' => 'request');
    }

    try {
        $posData = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return array('error' => 'Invalid posData JSON.', 'error_kind' => 'request');
    }

    if (!is_array($posData) || !array_key_exists('posData', $posData) ||
        !is_scalar($posData['posData']) || is_bool($posData['posData']) ||
        trim((string) $posData['posData']) === '') {
        return array('error' => 'Invalid posData value.', 'error_kind' => 'request');
    }
    if ($requireHash && (!array_key_exists('hash', $posData) || !is_string($posData['hash']) ||
        $posData['hash'] === '' || strlen($posData['hash']) > 255)) {
        return array('error' => 'Invalid posData hash.', 'error_kind' => 'request');
    }

    return $posData;
}

/**
 * Extract the only field consumed from an unauthenticated legacy IPN.
 *
 * BTCPay explicitly warns that legacy notification data can be faked. The
 * callback therefore does not validate or consume the posted status, amount,
 * currency, order ID, or POS data. bpGetInvoice() validates the complete
 * authenticated response before callback processing continues.
 *
 * @param array       $notification
 * @param string|null $apiKey   Retained for backwards call compatibility.
 * @param bool|null   $verifyPos Retained for backwards call compatibility.
 * @return array
 */
function bpValidateLegacyNotificationPayload(array $notification, $apiKey = null, $verifyPos = null)
{
    if (!array_key_exists('id', $notification) ||
        !bpIsValidInvoiceIdentifier($notification['id'])) {
        return array('error' => 'Callback invoice ID is invalid.', 'error_kind' => 'request');
    }

    return array('id' => $notification['id']);
}

/**
 * Validate every field consumed from an authenticated legacy API response.
 *
 * @param mixed $data
 * @return string|null
 */
function bpValidateBtcpayInvoiceResponseData($data)
{
    if (!is_array($data)) {
        return 'Invoice data must be an object.';
    }

    foreach (array('id', 'orderId', 'price', 'currency', 'status', 'posData') as $field) {
        if (!array_key_exists($field, $data)) {
            return 'Invoice data is missing ' . $field . '.';
        }
    }

    if (!bpIsValidInvoiceIdentifier($data['id'])) {
        return 'Invoice ID is invalid.';
    }
    if (!is_scalar($data['orderId']) || is_bool($data['orderId']) ||
        trim((string) $data['orderId']) === '' || strlen((string) $data['orderId']) > 128) {
        return 'Invoice order ID is invalid.';
    }
    if (!is_scalar($data['price']) || is_bool($data['price']) ||
        strlen((string) $data['price']) > 128 || !is_numeric(trim((string) $data['price']))) {
        return 'Invoice price is invalid.';
    }
    if (!is_string($data['currency']) ||
        preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,15}$/D', trim($data['currency'])) !== 1) {
        return 'Invoice currency is invalid.';
    }
    if (!is_string($data['status']) ||
        preg_match('/^[A-Za-z0-9_-]{1,32}$/D', trim($data['status'])) !== 1) {
        return 'Invoice status is invalid.';
    }
    if (isset(bpDecodePosData($data['posData'], false)['error'])) {
        return 'Invoice posData is invalid.';
    }
    if (array_key_exists('url', $data) && (!is_string($data['url']) || trim($data['url']) === '')) {
        return 'Invoice checkout URL is invalid.';
    }

    return null;
}

/**
 * $options can include ('apiKey')
 *
 * @param  string $invoiceId
 * @param  string $apiKey
 * @param  string $btcpayUrl
 * @return array
 */
function bpGetInvoice($invoiceId, $apiKey = false, $btcpayUrl = null)
{
    global $bpOptions;

    if (!$apiKey) {
        $apiKey = $bpOptions['apiKey'];
    }

    if (!$btcpayUrl) {
        $btcpayUrl = $bpOptions['btcpayUrl'];
    }

    if (!bpIsValidInvoiceIdentifier($invoiceId)) {
        return array('error' => 'Invalid BTCPay invoice ID.', 'error_kind' => 'request');
    }

    $btcpayUrl = getFullUri($btcpayUrl,"/invoices");

    $response = bpCurl($btcpayUrl . '/' . rawurlencode($invoiceId), $apiKey);

    if (isset($response['error'])) {
        return $response;
    }

    if (!array_key_exists('data', $response)) {
        return array('error' => 'BTCPay Server response is missing invoice data.', 'error_kind' => 'upstream');
    }
    $schemaError = bpValidateBtcpayInvoiceResponseData($response['data']);
    if ($schemaError !== null) {
        bpLog('[ERROR] In modules/gateways/btcpay/bp_lib.php::bpGetInvoice(): ' . $schemaError);
        return array('error' => 'BTCPay Server returned malformed invoice data.', 'error_kind' => 'upstream');
    }
    if (!hash_equals($invoiceId, $response['data']['id'])) {
        return array('error' => 'BTCPay Server returned a different invoice ID.', 'error_kind' => 'upstream');
    }

    return $response;
}
