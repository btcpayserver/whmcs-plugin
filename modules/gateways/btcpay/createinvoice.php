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

use WHMCS\Database\Capsule;
use BTCPayWHMCS\Greenfield;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/bootstrap.php';

$gatewaymodule = 'btcpay';

$GATEWAY = getGatewayVariables($gatewaymodule);

/**
 * @param int         $status
 * @param string      $publicMessage
 * @param string|null $logMessage
 * @return void
 */
function bpAbortInvoiceCreation($status, $publicMessage, $logMessage = null)
{
    if ($logMessage !== null) {
        error_log('[ERROR] In modules/gateways/btcpay/createinvoice.php: ' . $logMessage);
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        if ($status === 405) {
            header('Allow: POST');
        }
    }

    echo $publicMessage;
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || !is_string($_SERVER['REQUEST_METHOD']) ||
    strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    bpAbortInvoiceCreation(405, 'Method not allowed.', 'Rejected a non-POST invoice creation request.');
}

$contentType = isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])
    ? strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'], 2)[0]))
    : '';
if ($contentType !== 'application/x-www-form-urlencoded') {
    bpAbortInvoiceCreation(415, 'Unsupported media type.', 'Rejected an invoice creation request with an unexpected content type.');
}
if (isset($_SERVER['CONTENT_LENGTH']) &&
    (!preg_match('/^\d+$/D', (string) $_SERVER['CONTENT_LENGTH']) ||
        (int) $_SERVER['CONTENT_LENGTH'] > 16384)) {
    bpAbortInvoiceCreation(413, 'Request body is too large.', 'Rejected an oversized invoice creation request.');
}

$postedInvoiceId = isset($_POST['invoiceId']) ? $_POST['invoiceId'] : null;
if (!is_string($postedInvoiceId) || preg_match('/^[1-9][0-9]*$/D', $postedInvoiceId) !== 1 ||
    strlen($postedInvoiceId) > 10 || (int) $postedInvoiceId <= 0) {
    bpAbortInvoiceCreation(400, 'Invalid invoice request.', 'Rejected an invalid invoice ID.');
}
$invoiceId = (int) $postedInvoiceId;

$sessionClientId = isset($_SESSION['uid']) ? $_SESSION['uid'] : null;
if ((!is_int($sessionClientId) && !is_string($sessionClientId)) ||
    preg_match('/^[1-9][0-9]*$/D', (string) $sessionClientId) !== 1 ||
    (int) $sessionClientId <= 0) {
    bpAbortInvoiceCreation(403, 'Authentication required.', 'Rejected invoice creation without an authenticated client session.');
}
$clientId = (int) $sessionClientId;

if (empty($GATEWAY['type'])) {
    bpAbortInvoiceCreation(503, 'Payment gateway is unavailable.', 'BTCPay module is not activated.');
}

try {
    $GATEWAY = bpGetGatewaySettings();
    $client = new Greenfield($GATEWAY);
    bpEnsureInvoiceContractTable();

    // Scope the first lookup to the session owner so guessed sequential invoice
    // IDs do not disclose invoice existence or status.
    $ownedInvoice = bpGetWhmcsInvoiceForContract($invoiceId, false, $clientId);
    if (!$ownedInvoice) {
        bpAbortInvoiceCreation(403, 'Invoice access denied.', 'Rejected invoice creation for an invoice not owned by the client session.');
    }
    if ((string) $ownedInvoice->status !== 'Unpaid') {
        bpAbortInvoiceCreation(409, 'Invoice is no longer unpaid.', 'Rejected invoice creation for a non-unpaid invoice.');
    }
    if ((string) $ownedInvoice->paymentmethod !== $gatewaymodule) {
        bpAbortInvoiceCreation(409, 'Invoice does not use this payment gateway.', 'Rejected invoice creation for a different payment method.');
    }

    $systemUrl = bpGetConfiguredWhmcsSystemUrl();
    $orderUrl = bpBuildTrustedUrl($systemUrl, 'viewinvoice.php', ['id' => $invoiceId]);
    $returnUrl = bpBuildTrustedReturnUrl(
        $systemUrl,
        $invoiceId,
        isset($GATEWAY['redirectURL']) ? $GATEWAY['redirectURL'] : ''
    );
    $btcpayUrl = bpNormalizeConfiguredBaseUrl(
        isset($GATEWAY['btcpayUrl']) ? $GATEWAY['btcpayUrl'] : null
    );
    $btcpayTorUrl = '';
    if (isset($GATEWAY['btcpayUrlTor']) && trim((string) $GATEWAY['btcpayUrlTor']) !== '') {
        $btcpayTorUrl = bpNormalizeConfiguredBaseUrl($GATEWAY['btcpayUrlTor']);
    }

    $result = Capsule::connection()->transaction(function () use (
        $GATEWAY,
        $btcpayUrl,
        $client,
        $clientId,
        $gatewaymodule,
        $invoiceId,
        $orderUrl,
        $returnUrl
    ) {
        // This row lock serializes invoice creation so concurrent form submits
        // cannot create multiple active BTCPay invoices for the same WHMCS bill.
        $data = bpGetWhmcsInvoiceForContract($invoiceId, true, $clientId);
        if (!$data) {
            throw new RuntimeException('The authorized invoice disappeared before it could be locked.');
        }
        if ((string) $data->status !== 'Unpaid') {
            throw new RuntimeException('The invoice is no longer unpaid.');
        }
        if ((string) $data->paymentmethod !== $gatewaymodule) {
            throw new RuntimeException('The invoice payment method changed before it could be processed.');
        }

        $whmcsAmount = bpNormalizeDecimal($data->total);
        if (!bpDecimalIsPositive($whmcsAmount)) {
            throw new RuntimeException('The invoice amount is not positive.');
        }
        $whmcsCurrency = bpNormalizeCurrencyCode($data->currency);
        $price = $whmcsAmount;
        $currency = $whmcsCurrency;

        $convertSetting = Capsule::table('tblpaymentgateways')
            ->where('gateway', $gatewaymodule)
            ->where('setting', 'convertto')
            ->first();
        $convertTo = $convertSetting ? $convertSetting->value : null;

        if ($convertTo) {
            $currentCurrency = Capsule::table('tblcurrencies')
                ->where('code', $whmcsCurrency)
                ->first();
            $targetCurrency = Capsule::table('tblcurrencies')
                ->where('id', (int) $convertTo)
                ->first();
            if (!$currentCurrency || !$targetCurrency) {
                throw new RuntimeException('The configured currency conversion is invalid.');
            }

            $currency = bpNormalizeCurrencyCode($targetCurrency->code);
            $price = bpConvertCurrencyAmount(
                $whmcsAmount,
                $currentCurrency->rate,
                $targetCurrency->rate
            );
        }

        $reusableContracts = bpFindReusableInvoiceContracts(
            $invoiceId,
            $whmcsAmount,
            $whmcsCurrency,
            $price,
            $currency
        );
        foreach ($reusableContracts as $contract) {
            // Reuse also recovers settlement if its webhook was missed during upgrade.
            $synchronized = bpSynchronizeInvoice($client, $contract->btcpay_invoice_id);
            bpLogPaymentResult($GATEWAY, $synchronized);
            $existing = $synchronized['invoice'];
            $existingStatus = strtolower($existing['status']);
            if ($existingStatus === 'expired' || $existingStatus === 'invalid') {
                continue;
            }
            if (!in_array($existingStatus, array('new', 'processing', 'settled'), true)) {
                throw new RuntimeException('Existing BTCPay invoice has an unsupported status.');
            }

            $contractError = bpValidateCreatedInvoiceData(
                $existing,
                $invoiceId,
                $price,
                $currency
            );
            if ($contractError !== null) {
                throw new RuntimeException('Existing BTCPay checkout is invalid: ' . $contractError);
            }

            return array(
                'checkout_url' => bpValidateCheckoutUrl($existing['checkoutLink'], $btcpayUrl),
                'btcpay_invoice_id' => $existing['id'],
                'reused' => true,
            );
        }

        $buyerEmail = null;
        if (bpGatewayOptionEnabled($GATEWAY['sendCustomerEmail'] ?? null)) {
            // Use the authorized invoice owner's saved email, never posted buyer data.
            $buyerEmail = Capsule::table('tblclients')->where('id', (int) $data->userid)->value('email');
        }

        $transactionSpeed = isset($GATEWAY['transactionSpeed'])
            ? strtolower(trim($GATEWAY['transactionSpeed']))
            : 'medium';
        $invoice = $client->createInvoice(
            $invoiceId, $price, $currency, $returnUrl, $orderUrl, $transactionSpeed, $buyerEmail
        );
        $contractError = bpValidateCreatedInvoiceData(
            $invoice,
            $invoiceId,
            $price,
            $currency
        );
        if ($contractError !== null) {
            throw new RuntimeException('BTCPay invoice creation contract mismatch: ' . $contractError);
        }

        $checkoutUrl = bpValidateCheckoutUrl($invoice['checkoutLink'], $btcpayUrl);
        bpStoreInvoiceContract(array(
            'btcpay_invoice_id' => (string) $invoice['id'],
            'whmcs_invoice_id' => $invoiceId,
            'whmcs_amount' => $whmcsAmount,
            'whmcs_currency' => $whmcsCurrency,
            'btcpay_amount' => $invoice['amount'],
            'btcpay_currency' => $invoice['currency'],
            'connection_key' => $client->connectionKey(),
        ));

        return array(
            'checkout_url' => $checkoutUrl,
            'btcpay_invoice_id' => $invoice['id'],
            'reused' => false,
        );
    });

    $checkoutUrl = $result['checkout_url'];
    if ($btcpayTorUrl !== '' && bpRequestUsesOnionHost($_SERVER)) {
        $checkoutUrl = bpMapCheckoutUrlToBase($checkoutUrl, $btcpayUrl, $btcpayTorUrl);
    }
    // Validate once more immediately before using untrusted API data in a
    // response header, including after any administrator-configured Tor map.
    bpValidateCheckoutUrl($checkoutUrl, $btcpayTorUrl !== '' && bpRequestUsesOnionHost($_SERVER)
        ? $btcpayTorUrl
        : $btcpayUrl);

    header('Location: ' . $checkoutUrl, true, 303);
    exit;
} catch (Throwable $exception) {
    bpAbortInvoiceCreation(
        $exception instanceof BTCPayWHMCS\GatewayException ? $exception->httpStatus : 503,
        'Unable to create or resume the BTCPay invoice.',
        'Secure invoice creation failed for WHMCS invoice #' . $invoiceId . '. ' .
            ($exception instanceof BTCPayWHMCS\GatewayException ? $exception->getMessage() : 'Check gateway configuration and database permissions.')
    );
}
