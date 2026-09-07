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

require_once __DIR__ . '/btcpay/bootstrap.php';

function btcpay_MetaData()
{
    return [
      'DisplayName' => 'BTCPay Server',
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
        'apiKey' => array(
            'FriendlyName' => 'Greenfield API Key',
            'Type' => 'password',
            'Description' => 'Create a store-scoped account API key with Create an invoice, View invoices and Modify stores webhooks permissions. Creating non-approved pull payments is optional for future refunds. Replace your legacy key when upgrading.',
        ),
        'storeId' => array(
            'FriendlyName' => 'Store ID',
            'Type' => 'text',
            'Description' => 'Copy the Store ID from BTCPay Store Settings > General.',
        ),
        'webhookSecret' => array(
            'FriendlyName' => 'Manual Webhook Secret (optional)',
            'Type' => 'password',
            'Description' => 'Normally leave blank: saving settings registers the webhook automatically and securely stores the BTCPay-generated secret. Only enter a secret to adopt an existing manual webhook. The generated secret is never displayed here.',
        ),
        'btcpayUrl' => array(
            'FriendlyName' => 'BTCPay Server URL',
            'Type' => 'text',
            'Description' => 'The URL of your BTCPay Server instance, e.g., https://btcpay.example.com.',
        ),
        'btcpayUrlTor' => array(
            'FriendlyName' => 'BTCPay Server Tor URL (optional)',
            'Type' => 'text',
            'Description' => 'The Tor URL of your BTCPay Server instance. This is optional and only used if WHMCS is accessed via a .onion domain.',
        ),
        'redirectURL' => array(
                'FriendlyName' => 'Redirect URL (optional)',
                'Type' => 'text',
                'Description' => 'URL to redirect to after payment. Leave blank to return to the WHMCS invoice page.',
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
            'Default'      => 'medium',
            'Description'  => 'Medium is recommended. High accepts unconfirmed on-chain payments. Existing saved settings are retained on upgrade.',
        ),
        'callbackDiagnostics' => array(
            'FriendlyName' => 'Callback Diagnostics',
            'Type' => 'yesno',
            'Description' => 'Temporarily log safe callback stages in the WHMCS Gateway Log and Activity Log. Request bodies and API credentials are never logged.',
        ),
    );

    try {
        $manageUrl = bpBuildTrustedUrl(bpGetConfiguredWhmcsSystemUrl(), 'modules/gateways/btcpay/manage.php');
        $configarray['webhookSecret']['Description'] .= ' <a href="' .
            bpEscapeHtmlAttribute($manageUrl) . '" target="_blank" rel="noopener">Connection, webhook setup status and invoice recovery</a>.';
        if (defined('ADMINAREA') && ADMINAREA) {
            $status = \WHMCS\Module\GatewaySetting::where('gateway', 'btcpay')->where('setting', 'webhookSetupStatus')->first();
            if ($status) {
                $configarray['webhookSecret']['Description'] .= '<br>' . bpEscapeHtmlAttribute($status->value);
            }
        }
    } catch (Throwable $exception) {
        // Configuration remains renderable before WHMCS has a SystemURL.
    }
    return $configarray;
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
