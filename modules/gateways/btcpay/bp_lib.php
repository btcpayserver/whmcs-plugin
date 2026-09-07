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

/**
 * BTCPay Server Greenfield API client for the WHMCS gateway module.
 *
 * API reference: https://docs.btcpayserver.org/API/Greenfield/v1/
 *
 * Every function here returns either a decoded response array or an array
 * containing an 'error' key (a public, non-sensitive message) and an
 * 'error_kind' key of 'request', 'local', or 'upstream'.
 */

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/bp_security.php';

const BP_GREENFIELD_TIMEOUT_SECONDS = 15;
const BP_GREENFIELD_MAX_RESPONSE_BYTES = 1048576;

/**
 * @param string $contents
 */
function bpLog($contents)
{
    error_log($contents);
}

/**
 * @param string $baseUri
 * @param string $path
 * @return string
 */
function bpGreenfieldUrl($baseUri, $path)
{
    return rtrim($baseUri, '/') . '/' . ltrim($path, '/');
}

/**
 * Extract a short, sanitized summary of a Greenfield validation error body
 * (an array of {path, message} objects) for the error log. Nothing from the
 * body is ever returned to the browser.
 *
 * @param string $responseString
 * @return string
 */
function bpSummarizeGreenfieldError($responseString)
{
    if (!is_string($responseString) || $responseString === '') {
        return '';
    }

    try {
        $decoded = json_decode($responseString, true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return '';
    }

    $problems = array();
    if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
        $problems[] = $decoded['message'];
    } elseif (is_array($decoded)) {
        foreach ($decoded as $item) {
            if (!is_array($item) || !isset($item['message']) || !is_string($item['message'])) {
                continue;
            }
            $path = isset($item['path']) && is_string($item['path']) ? $item['path'] . ': ' : '';
            $problems[] = $path . $item['message'];
            if (count($problems) >= 5) {
                break;
            }
        }
    }

    if (!$problems) {
        return '';
    }

    $summary = preg_replace('/[\x00-\x1F\x7F]/', ' ', implode('; ', $problems));

    return ' Details: ' . substr((string) $summary, 0, 500);
}

/**
 * Perform one authenticated Greenfield API request.
 *
 * @param string      $btcpayUrl Base URL of the BTCPay Server instance.
 * @param string      $apiKey    Greenfield API key.
 * @param string      $method    GET or POST.
 * @param string      $path      Path under the base URL, e.g. /api/v1/stores/x/invoices.
 * @param array|null  $body      JSON-encodable request body for POST requests.
 * @return array
 */
function bpGreenfieldRequest($btcpayUrl, $apiKey, $method, $path, $body = null)
{
    global $version;

    if (!is_string($apiKey) || trim($apiKey) === '') {
        return array('error' => 'The BTCPay Server API key is not configured.', 'error_kind' => 'local');
    }
    $apiKey = trim($apiKey);
    if (preg_match('/[\x00-\x20\x7F]/', $apiKey)) {
        return array('error' => 'The BTCPay Server API key is invalid.', 'error_kind' => 'local');
    }

    try {
        $btcpayUrl = bpNormalizeConfiguredBaseUrl($btcpayUrl);
    } catch (Throwable $exception) {
        return array('error' => 'The BTCPay Server URL is invalid.', 'error_kind' => 'local');
    }

    $method = strtoupper((string) $method);
    if ($method !== 'GET' && $method !== 'POST') {
        return array('error' => 'Unsupported BTCPay request method.', 'error_kind' => 'local');
    }

    $url = bpGreenfieldUrl($btcpayUrl, $path);
    $curl = curl_init($url);
    if ($curl === false) {
        return array('error' => 'Unable to initialize the BTCPay request.', 'error_kind' => 'upstream');
    }

    $header = array(
        'Accept: application/json',
        'Authorization: token ' . $apiKey,
        'User-Agent: BTCPay-WHMCS/' . $version,
    );

    if ($method === 'POST') {
        try {
            $encoded = json_encode(
                $body === null ? new stdClass() : $body,
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES
            );
        } catch (Throwable $exception) {
            curl_close($curl);
            return array('error' => 'Unable to encode the BTCPay request.', 'error_kind' => 'local');
        }
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded);
        $header[] = 'Content-Type: application/json';
        $header[] = 'Content-Length: ' . strlen($encoded);
    } else {
        curl_setopt($curl, CURLOPT_HTTPGET, 1);
    }

    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($curl, CURLOPT_TIMEOUT, BP_GREENFIELD_TIMEOUT_SECONDS);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1); // verify certificate
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2); // check existence of CN and verify that it matches hostname
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 0); // never follow redirects with the API key attached
    curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
    curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
    curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

    $responseString = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    $curlErrno = curl_errno($curl);
    curl_close($curl);

    $logPrefix = '[ERROR] In modules/gateways/btcpay/bp_lib.php::bpGreenfieldRequest(): ' . $method . ' ' . $path . ': ';

    if ($responseString === false) {
        bpLog($logPrefix . 'cURL request failed with errno ' . $curlErrno . ': ' . $curlError);
        return array('error' => 'The BTCPay Server request failed.', 'error_kind' => 'upstream');
    }
    if (strlen($responseString) > BP_GREENFIELD_MAX_RESPONSE_BYTES) {
        bpLog($logPrefix . 'BTCPay Server response exceeded ' . BP_GREENFIELD_MAX_RESPONSE_BYTES . ' bytes.');
        return array('error' => 'BTCPay Server returned an oversized response.', 'error_kind' => 'upstream');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $hint = '';
        if ($httpCode === 401) {
            $hint = ' The API key was rejected. Check the Greenfield API key in the gateway settings.';
        } elseif ($httpCode === 403) {
            $hint = ' The API key lacks permission for this store or operation.';
        } elseif ($httpCode === 404) {
            $hint = ' The store or invoice was not found. Check the Store ID in the gateway settings.';
        } elseif ($httpCode === 400 || $httpCode === 422) {
            $hint = bpSummarizeGreenfieldError($responseString);
        }
        bpLog(
            $logPrefix . 'BTCPay Server returned HTTP ' . $httpCode . ' with body length ' .
            strlen($responseString) . ' and SHA-256 ' . hash('sha256', $responseString) . '.' . $hint
        );

        return array(
            'error' => 'BTCPay Server returned an unsuccessful HTTP status.',
            'error_kind' => 'upstream',
            'http_status' => $httpCode,
        );
    }

    if ($httpCode === 204 || $responseString === '') {
        return array();
    }

    try {
        $response = json_decode($responseString, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        bpLog(
            $logPrefix . 'Invalid JSON response for HTTP ' . $httpCode . ' with body length ' .
            strlen($responseString) . ' and SHA-256 ' . hash('sha256', $responseString) . '.'
        );
        return array('error' => 'BTCPay Server returned an invalid response.', 'error_kind' => 'upstream');
    }

    if (!is_array($response)) {
        return array('error' => 'BTCPay Server returned an invalid response.', 'error_kind' => 'upstream');
    }
    // Greenfield never returns an 'error' member on success; reserve the key
    // for this library's own error signalling.
    unset($response['error'], $response['error_kind']);

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
 * Store IDs share the invoice ID alphabet.
 *
 * @param mixed $storeId
 * @return bool
 */
function bpIsValidStoreIdentifier($storeId)
{
    return bpIsValidInvoiceIdentifier($storeId);
}

/**
 * @param mixed $storeId
 * @return string
 */
function bpNormalizeStoreId($storeId)
{
    $storeId = is_string($storeId) ? trim($storeId) : '';
    if (!bpIsValidStoreIdentifier($storeId)) {
        throw new InvalidArgumentException('The BTCPay Store ID is missing or invalid.');
    }

    return $storeId;
}

/**
 * Map the gateway's transaction speed setting to a Greenfield speedPolicy.
 * Returns null when the store default should be used.
 *
 * @param mixed $setting
 * @return string|null
 */
function bpMapTransactionSpeed($setting)
{
    $setting = is_string($setting) ? strtolower(trim($setting)) : '';
    switch ($setting) {
        case '':
        case 'default':
            return null;
        case 'high':
            return 'HighSpeed';
        case 'medium':
            return 'MediumSpeed';
        case 'lowmedium':
            return 'LowMediumSpeed';
        case 'low':
            return 'LowSpeed';
        default:
            throw new InvalidArgumentException('The configured transaction speed is invalid.');
    }
}

/**
 * @param array $invoiceData
 * @return string|null
 */
function bpInvoiceOrderId(array $invoiceData)
{
    if (!isset($invoiceData['metadata']) || !is_array($invoiceData['metadata']) ||
        !array_key_exists('orderId', $invoiceData['metadata'])) {
        return null;
    }
    $orderId = $invoiceData['metadata']['orderId'];
    if (!is_scalar($orderId) || is_bool($orderId)) {
        return null;
    }

    return (string) $orderId;
}

/**
 * Lower-cased Greenfield invoice status: new, processing, expired, invalid, settled.
 *
 * @param array $invoiceData
 * @return string
 */
function bpInvoiceStatus(array $invoiceData)
{
    return isset($invoiceData['status']) && is_string($invoiceData['status'])
        ? strtolower(trim($invoiceData['status']))
        : '';
}

/**
 * Lower-cased Greenfield additional status: none, paidlate, paidpartial,
 * marked, invalid, paidover.
 *
 * @param array $invoiceData
 * @return string
 */
function bpInvoiceAdditionalStatus(array $invoiceData)
{
    return isset($invoiceData['additionalStatus']) && is_string($invoiceData['additionalStatus'])
        ? strtolower(trim($invoiceData['additionalStatus']))
        : 'none';
}

/**
 * Validate every field consumed from an authenticated Greenfield invoice.
 *
 * @param mixed       $data
 * @param string|null $expectedStoreId
 * @return string|null
 */
function bpValidateBtcpayInvoiceResponseData($data, $expectedStoreId = null)
{
    if (!is_array($data)) {
        return 'Invoice data must be an object.';
    }

    foreach (array('id', 'amount', 'currency', 'status', 'checkoutLink', 'metadata') as $field) {
        if (!array_key_exists($field, $data)) {
            return 'Invoice data is missing ' . $field . '.';
        }
    }

    if (!bpIsValidInvoiceIdentifier($data['id'])) {
        return 'Invoice ID is invalid.';
    }
    if ($expectedStoreId !== null && array_key_exists('storeId', $data) &&
        (!is_string($data['storeId']) || !hash_equals($expectedStoreId, $data['storeId']))) {
        return 'Invoice belongs to a different store.';
    }
    if (!is_array($data['metadata'])) {
        return 'Invoice metadata is invalid.';
    }
    $orderId = bpInvoiceOrderId($data);
    if ($orderId === null || trim($orderId) === '' || strlen($orderId) > 128) {
        return 'Invoice order ID is invalid.';
    }
    if (!is_scalar($data['amount']) || is_bool($data['amount']) ||
        strlen((string) $data['amount']) > 128 || !is_numeric(trim((string) $data['amount']))) {
        return 'Invoice amount is invalid.';
    }
    if (!is_string($data['currency']) ||
        preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,15}$/D', trim($data['currency'])) !== 1) {
        return 'Invoice currency is invalid.';
    }
    if (!is_string($data['status']) ||
        !in_array(strtolower(trim($data['status'])), array('new', 'processing', 'expired', 'invalid', 'settled'), true)) {
        return 'Invoice status is invalid.';
    }
    if (array_key_exists('additionalStatus', $data) && $data['additionalStatus'] !== null &&
        (!is_string($data['additionalStatus']) ||
            preg_match('/^[A-Za-z0-9_-]{1,32}$/D', trim($data['additionalStatus'])) !== 1)) {
        return 'Invoice additional status is invalid.';
    }
    if (array_key_exists('type', $data) && $data['type'] !== null &&
        (!is_string($data['type']) || strtolower($data['type']) !== 'standard')) {
        return 'Invoice type is not supported.';
    }
    if (!is_string($data['checkoutLink']) || trim($data['checkoutLink']) === '') {
        return 'Invoice checkout URL is invalid.';
    }

    return null;
}

/**
 * Create an invoice: POST /api/v1/stores/{storeId}/invoices
 * Requires the btcpay.store.cancreateinvoice permission.
 *
 * @param string $btcpayUrl
 * @param string $apiKey
 * @param string $storeId
 * @param array  $request  Greenfield CreateInvoiceRequest body.
 * @return array
 */
function bpCreateInvoice($btcpayUrl, $apiKey, $storeId, array $request)
{
    if (!bpIsValidStoreIdentifier($storeId)) {
        return array('error' => 'The BTCPay Store ID is invalid.', 'error_kind' => 'local');
    }

    $response = bpGreenfieldRequest(
        $btcpayUrl,
        $apiKey,
        'POST',
        '/api/v1/stores/' . rawurlencode($storeId) . '/invoices',
        $request
    );
    if (isset($response['error'])) {
        return $response;
    }

    $schemaError = bpValidateBtcpayInvoiceResponseData($response, $storeId);
    if ($schemaError !== null) {
        bpLog('[ERROR] In modules/gateways/btcpay/bp_lib.php::bpCreateInvoice(): ' . $schemaError);
        return array('error' => 'BTCPay Server returned malformed invoice data.', 'error_kind' => 'upstream');
    }

    return $response;
}

/**
 * Fetch an invoice: GET /api/v1/stores/{storeId}/invoices/{invoiceId}
 * Requires the btcpay.store.canviewinvoices permission.
 *
 * @param string $btcpayUrl
 * @param string $apiKey
 * @param string $storeId
 * @param string $invoiceId
 * @return array
 */
function bpGetInvoice($btcpayUrl, $apiKey, $storeId, $invoiceId)
{
    if (!bpIsValidStoreIdentifier($storeId)) {
        return array('error' => 'The BTCPay Store ID is invalid.', 'error_kind' => 'local');
    }
    if (!bpIsValidInvoiceIdentifier($invoiceId)) {
        return array('error' => 'Invalid BTCPay invoice ID.', 'error_kind' => 'request');
    }

    $response = bpGreenfieldRequest(
        $btcpayUrl,
        $apiKey,
        'GET',
        '/api/v1/stores/' . rawurlencode($storeId) . '/invoices/' . rawurlencode($invoiceId)
    );
    if (isset($response['error'])) {
        return $response;
    }

    $schemaError = bpValidateBtcpayInvoiceResponseData($response, $storeId);
    if ($schemaError !== null) {
        bpLog('[ERROR] In modules/gateways/btcpay/bp_lib.php::bpGetInvoice(): ' . $schemaError);
        return array('error' => 'BTCPay Server returned malformed invoice data.', 'error_kind' => 'upstream');
    }
    if (!hash_equals($invoiceId, $response['id'])) {
        return array('error' => 'BTCPay Server returned a different invoice ID.', 'error_kind' => 'upstream');
    }

    return $response;
}

/**
 * Describe the API key in use: GET /api/v1/api-keys/current
 *
 * @param string $btcpayUrl
 * @param string $apiKey
 * @return array
 */
function bpGetCurrentApiKey($btcpayUrl, $apiKey)
{
    return bpGreenfieldRequest($btcpayUrl, $apiKey, 'GET', '/api/v1/api-keys/current');
}

/**
 * Cheap functional probe that the key can read the store's invoices.
 *
 * @param string $btcpayUrl
 * @param string $apiKey
 * @param string $storeId
 * @return array
 */
function bpProbeStoreInvoices($btcpayUrl, $apiKey, $storeId)
{
    if (!bpIsValidStoreIdentifier($storeId)) {
        return array('error' => 'The BTCPay Store ID is invalid.', 'error_kind' => 'local');
    }

    return bpGreenfieldRequest(
        $btcpayUrl,
        $apiKey,
        'GET',
        '/api/v1/stores/' . rawurlencode($storeId) . '/invoices?take=1&skip=0'
    );
}

/**
 * Check whether a Greenfield permission list grants a store permission,
 * either globally or scoped to the given store.
 *
 * @param array  $permissions
 * @param string $permission  e.g. btcpay.store.cancreateinvoice
 * @param string $storeId
 * @return bool
 */
function bpApiKeyGrants(array $permissions, $permission, $storeId)
{
    $implied = array(
        'btcpay.store.cancreateinvoice' => array('btcpay.store.canmodifyinvoices', 'btcpay.store.canmodifystoresettings'),
        'btcpay.store.canviewinvoices' => array('btcpay.store.canmodifyinvoices', 'btcpay.store.canmodifystoresettings', 'btcpay.store.canviewstoresettings'),
    );
    $candidates = array_merge(array($permission), isset($implied[$permission]) ? $implied[$permission] : array());

    foreach ($permissions as $granted) {
        if (!is_string($granted)) {
            continue;
        }
        if ($granted === 'unrestricted') {
            return true;
        }
        $parts = explode(':', $granted, 2);
        $name = $parts[0];
        $scope = isset($parts[1]) ? $parts[1] : null;
        if (!in_array($name, $candidates, true)) {
            continue;
        }
        if ($scope === null || hash_equals($storeId, $scope)) {
            return true;
        }
    }

    return false;
}

/**
 * Read the raw BTCPay-Sig header from the server variables.
 *
 * @param array $server
 * @return string|null
 */
function bpWebhookSignatureHeader(array $server)
{
    if (isset($server['HTTP_BTCPAY_SIG']) && is_string($server['HTTP_BTCPAY_SIG'])) {
        return trim($server['HTTP_BTCPAY_SIG']);
    }

    return null;
}

/**
 * Verify a Greenfield webhook delivery. BTCPay sends
 * "BTCPay-Sig: sha256=HMAC-SHA256(secret, body)" in lower-case hex.
 *
 * @param mixed  $rawBody
 * @param mixed  $signatureHeader
 * @param mixed  $secret
 * @return bool
 */
function bpVerifyWebhookSignature($rawBody, $signatureHeader, $secret)
{
    if (!is_string($rawBody) || !is_string($signatureHeader) || !is_string($secret) || $secret === '') {
        return false;
    }
    if (preg_match('/^sha256=([0-9a-fA-F]{64})$/D', $signatureHeader, $matches) !== 1) {
        return false;
    }

    $expected = hash_hmac('sha256', $rawBody, $secret);

    return hash_equals($expected, strtolower($matches[1]));
}

/**
 * Extract the fields consumed from a (signature-verified) webhook delivery.
 *
 * Only the invoice ID is used to select the record to re-fetch through the
 * authenticated API. Status and metadata in the delivery are never consumed,
 * since deliveries can be replayed out of order.
 *
 * @param array  $payload
 * @param string $expectedStoreId
 * @return array  ['ignored' => true, 'type' => ...] for non-invoice events,
 *                ['error' => ..., 'status' => http] on invalid payloads, or
 *                ['invoice_id' => ..., 'type' => ..., 'delivery_id' => ..., 'is_redelivery' => bool].
 */
function bpValidateWebhookPayload(array $payload, $expectedStoreId)
{
    if (!array_key_exists('type', $payload) || !is_string($payload['type']) ||
        preg_match('/^[A-Za-z0-9_]{1,64}$/D', $payload['type']) !== 1) {
        return array('error' => 'Webhook event type is invalid.', 'status' => 400);
    }
    $type = $payload['type'];

    if (strpos($type, 'Invoice') !== 0) {
        return array('ignored' => true, 'type' => $type);
    }

    if (!array_key_exists('invoiceId', $payload) || !bpIsValidInvoiceIdentifier($payload['invoiceId'])) {
        return array('error' => 'Webhook invoice ID is invalid.', 'status' => 400);
    }
    if (!array_key_exists('storeId', $payload) || !is_string($payload['storeId']) ||
        !hash_equals((string) $expectedStoreId, $payload['storeId'])) {
        return array('error' => 'Webhook store ID does not match the configured store.', 'status' => 409);
    }

    $deliveryId = isset($payload['deliveryId']) && bpIsValidInvoiceIdentifier($payload['deliveryId'])
        ? $payload['deliveryId']
        : null;

    return array(
        'invoice_id' => $payload['invoiceId'],
        'type' => $type,
        'delivery_id' => $deliveryId,
        'is_redelivery' => isset($payload['isRedelivery']) && $payload['isRedelivery'] === true,
    );
}
