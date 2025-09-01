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

function btcpay_MetaData()
{
    return [
      'DisplayName' => 'BTCPay Server (legacy API)',
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
            'FriendlyName' => 'Legacy API Key',
            'Type' => 'text',
            'Description' => 'Your legacy API key. You can create a new one in your BTCPay Server store settings > Access Tokens.',
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
                'Description' => 'URL to redirect to after payment. Leave blank to use the default WHMCS order confirmation page.',
        ),
        'transactionSpeed' => array(
            'FriendlyName' => 'Transaction Speed',
            'Type'         => 'dropdown',
            'Options'      => 'low,medium,high',
            'Default'      => 'medium',
            'Description'  => 'The transaction speed to use for the invoice. Medium is recommended. See docs for a detailed explanation.',
        ),
    );

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

    // Invoice Variables
    $invoiceid = $params['invoiceid'];

    // Client Variables
    $firstname = $params['clientdetails']['firstname'];
    $lastname  = $params['clientdetails']['lastname'];
    $email     = $params['clientdetails']['email'];
    $address1  = $params['clientdetails']['address1'];
    $address2  = $params['clientdetails']['address2'];
    $city      = $params['clientdetails']['city'];
    $state     = $params['clientdetails']['state'];
    $postcode  = $params['clientdetails']['postcode'];
    $country   = $params['clientdetails']['country'];
    $phone     = $params['clientdetails']['phonenumber'];

    // Tor support
    $parsedurl = parse_url($params['systemurl']);
    $is_tor_enabled = preg_match("/\.onion$/", $_SERVER['HTTP_HOST']) && $params['btcpayUrlTor'] != '';
    if (is_array($parsedurl) && $is_tor_enabled) {
	$systemtor = "http://{$_SERVER['HTTP_HOST']}" . $parsedurl['path'];
    } else {
        $systemtor = "";
    }

    // System Variables
    $systemurl = $systemtor != '' ? $systemtor : $params['systemurl'];

    $post = array(
        'invoiceId'     => $invoiceid,
        'systemURL'     => $systemurl,
        'ipnURL'        => $params['systemurl'] . '/modules/gateways/callback/btcpay.php',
        'buyerName'     => $firstname . ' ' . $lastname,
        'buyerAddress1' => $address1,
        'buyerAddress2' => $address2,
        'buyerCity'     => $city,
        'buyerState'    => $state,
        'buyerZip'      => $postcode,
        'buyerEmail'    => $email,
        'buyerPhone'    => $phone,
    );
    
    $form = '<form action="' . $systemurl . '/modules/gateways/btcpay/createinvoice.php" method="POST">';

    foreach ($post as $key => $value) {
        $form .= '<input type="hidden" name="' . $key . '" value = "' . $value . '" />';
    }

    $form .= '<input type="submit" value="' . $params['langpaynow'] . '" />';
    $form .= '</form>';

    return $form;
}
