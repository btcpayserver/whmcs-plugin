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

require_once __DIR__ . '/btcpay/bp_security.php';
require_once __DIR__ . '/btcpay/bp_lib.php';

function btcpay_MetaData()
{
    return [
      'DisplayName' => 'BTCPay Server (Greenfield API)',
      'failedEmail' => 'Credit Card Payment Failed',
      'successEmail' => 'BTCPay Payment Success',
      'pendingEmail' => 'BTCPay Payment Pending',
      'APIVersion' => '1.1',
    ];
}

/**
 * Returns configuration options array.
 *
 * @return array
 */
function btcpay_config()
{
    $configarray = array(
        "FriendlyName" => array(
            "Type" => "System",
            "Value" => "Bitcoin payments via BTCPay Server"
        ),
        'btcpayUrl' => array(
            'FriendlyName' => 'BTCPay Server URL',
            'Type' => 'text',
            'Description' => 'The URL of your BTCPay Server instance, e.g., https://btcpay.example.com.',
        ),
        'storeId' => array(
            'FriendlyName' => 'Store ID',
            'Type' => 'text',
            'Description' => 'The ID of the BTCPay store to bill through. Found in BTCPay under Store Settings > General, or in the store URL.',
        ),
        'apiKey' => array(
            'FriendlyName' => 'Greenfield API Key',
            'Type' => 'password',
            'Description' => 'Create one in BTCPay under Account > Manage Account > API Keys with the permissions '
                . '"Create an invoice" (btcpay.store.cancreateinvoice) and "View invoices" (btcpay.store.canviewinvoices), '
                . 'ideally restricted to the store above. Legacy store access tokens no longer work.',
        ),
        'webhookSecret' => array(
            'FriendlyName' => 'Webhook Secret',
            'Type' => 'password',
            'Description' => 'In BTCPay go to Store Settings > Webhooks, add a webhook whose Payload URL is '
                . '<code>{WHMCS URL}/modules/gateways/callback/btcpay.php</code>, enable the invoice events, '
                . 'and paste the webhook secret here. Deliveries are rejected unless their signature matches this secret.',
        ),
        'btcpayUrlTor' => array(
            'FriendlyName' => 'BTCPay Server Tor URL (optional)',
            'Type' => 'text',
            'Description' => 'The Tor URL of your BTCPay Server instance. This is optional and only used if WHMCS is accessed via a .onion domain.',
        ),
        'redirectURL' => array(
                'FriendlyName' => 'Redirect URL (optional)',
                'Type' => 'text',
                'Description' => 'URL to redirect to after payment. Leave blank to use the default WHMCS invoice page.',
        ),
        'transactionSpeed' => array(
            'FriendlyName' => 'Transaction Speed',
            'Type'         => 'dropdown',
            'Options'      => array(
                'default' => 'Store default',
                'high' => 'High (0 confirmations)',
                'medium' => 'Medium (1 confirmation)',
                'lowmedium' => 'Low-Medium (2 confirmations)',
                'low' => 'Low (6 confirmations)',
            ),
            'Default'      => 'default',
            'Description'  => 'Overrides the store\'s speed policy for invoices created by WHMCS. Sets the Greenfield checkout.speedPolicy value.',
        ),
        'callbackDiagnostics' => array(
            'FriendlyName' => 'Callback Diagnostics',
            'Type' => 'yesno',
            'Description' => 'Temporarily log safe webhook stages in the WHMCS Gateway Log and Activity Log. Request bodies and API credentials are never logged.',
        ),
    );

    return $configarray;
}

/**
 * Validate the gateway configuration when it is saved in the WHMCS admin
 * area. Performs a live check against BTCPay Server so a wrong URL, store ID,
 * key, or missing permission is reported immediately instead of at checkout.
 *
 * @param array $params
 * @return void
 * @throws \WHMCS\Exception\Module\InvalidConfiguration
 */
function btcpay_config_validate($params)
{
    $fail = function ($message) {
        if (class_exists('\WHMCS\Exception\Module\InvalidConfiguration')) {
            throw new \WHMCS\Exception\Module\InvalidConfiguration($message);
        }
        throw new RuntimeException($message);
    };

    try {
        $btcpayUrl = bpNormalizeConfiguredBaseUrl(isset($params['btcpayUrl']) ? $params['btcpayUrl'] : null);
    } catch (Throwable $exception) {
        $fail('BTCPay Server URL must be an absolute http(s) URL without query string or fragment.');
    }
    if (isset($params['btcpayUrlTor']) && trim((string) $params['btcpayUrlTor']) !== '') {
        try {
            bpNormalizeConfiguredBaseUrl($params['btcpayUrlTor']);
        } catch (Throwable $exception) {
            $fail('BTCPay Server Tor URL must be an absolute http(s) URL without query string or fragment.');
        }
    }
    if (isset($params['redirectURL']) && trim((string) $params['redirectURL']) !== '') {
        try {
            bpValidateAbsoluteHttpUrl($params['redirectURL']);
        } catch (Throwable $exception) {
            $fail('Redirect URL must be an absolute http(s) URL.');
        }
    }
    try {
        $storeId = bpNormalizeStoreId(isset($params['storeId']) ? $params['storeId'] : null);
    } catch (Throwable $exception) {
        $fail('Store ID is missing or contains invalid characters.');
    }
    try {
        bpMapTransactionSpeed(isset($params['transactionSpeed']) ? $params['transactionSpeed'] : null);
    } catch (Throwable $exception) {
        $fail('Transaction Speed is invalid.');
    }

    $apiKey = isset($params['apiKey']) ? trim((string) $params['apiKey']) : '';
    if ($apiKey === '') {
        $fail('Greenfield API Key is required.');
    }
    $webhookSecret = isset($params['webhookSecret']) ? trim((string) $params['webhookSecret']) : '';
    if ($webhookSecret === '') {
        $fail('Webhook Secret is required. Create a webhook in BTCPay Store Settings > Webhooks and paste its secret here.');
    }

    $keyInfo = bpGetCurrentApiKey($btcpayUrl, $apiKey);
    if (isset($keyInfo['error'])) {
        $status = isset($keyInfo['http_status']) ? (int) $keyInfo['http_status'] : 0;
        if ($status === 401) {
            $fail('BTCPay Server rejected the Greenfield API Key. Legacy store access tokens are not accepted; create an API key under Account > Manage Account > API Keys.');
        }
        $fail('Unable to reach the BTCPay Server Greenfield API at ' . $btcpayUrl . '. Check the URL and that WHMCS can make outbound HTTPS requests. See the PHP error log for details.');
    }

    $permissions = isset($keyInfo['permissions']) && is_array($keyInfo['permissions'])
        ? $keyInfo['permissions']
        : array();
    $missing = array();
    foreach (array('btcpay.store.cancreateinvoice', 'btcpay.store.canviewinvoices') as $permission) {
        if (!bpApiKeyGrants($permissions, $permission, $storeId)) {
            $missing[] = $permission;
        }
    }
    if ($missing) {
        $fail('The Greenfield API Key is missing the permission(s): ' . implode(', ', $missing) . ' for store ' . $storeId . '.');
    }

    $probe = bpProbeStoreInvoices($btcpayUrl, $apiKey, $storeId);
    if (isset($probe['error'])) {
        $status = isset($probe['http_status']) ? (int) $probe['http_status'] : 0;
        if ($status === 403 || $status === 404) {
            $fail('Store ' . $storeId . ' was not found or the API key cannot access it. Check the Store ID and the key\'s store restriction.');
        }
        $fail('BTCPay Server accepted the API key but the store check failed. See the PHP error log for details.');
    }
}

/**
 * Returns html form.
 *
 * @param  array  $params
 * @return string
 */
function btcpay_link($params)
{
    if (false === isset($params) || true === empty($params)) {
        die('[ERROR] In modules/gateways/btcpay.php::btcpay_link() function: Missing or invalid $params data.');
    }

    if (!isset($params['invoiceid'], $params['systemurl'], $params['langpaynow'])) {
        die('[ERROR] In modules/gateways/btcpay.php::btcpay_link() function: Missing required gateway data.');
    }

    try {
        $action = bpBuildGatewayFormAction($params['systemurl']);
    } catch (Throwable $exception) {
        error_log('[ERROR] In modules/gateways/btcpay.php::btcpay_link() function: Invalid WHMCS system URL.');
        die('[ERROR] In modules/gateways/btcpay.php::btcpay_link() function: Invalid gateway configuration.');
    }

    // Buyer metadata is loaded from WHMCS by the endpoint. The browser only
    // identifies the invoice, whose ownership is independently checked there.
    $post = array('invoiceId' => $params['invoiceid']);

    $form = '<form action="' . bpEscapeHtmlAttribute($action) . '" method="post">';

    foreach ($post as $key => $value) {
        $form .= '<input type="hidden" name="' . bpEscapeHtmlAttribute($key) .
            '" value="' . bpEscapeHtmlAttribute($value) . '" />';
    }

    $form .= '<input type="submit" value="' . bpEscapeHtmlAttribute($params['langpaynow']) . '" />';
    $form .= '</form>';

    return $form;
}
