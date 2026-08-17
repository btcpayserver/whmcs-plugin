<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2011-2015 BitPay
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

// Required File Includes
include __DIR__ . '/../../../includes/functions.php';
include __DIR__ . '/../../../includes/gatewayfunctions.php';
include __DIR__ . '/../../../includes/invoicefunctions.php';

if (file_exists(__DIR__ . '/../../../dbconnect.php')) {
    include __DIR__ . '/../../../dbconnect.php';
} else if (file_exists(__DIR__ . '/../../../init.php')) {
    include __DIR__ . '/../../../init.php';
} else {
    error_log('[ERROR] In modules/gateways/callback/btcpay.php: include error: Cannot find dbconnect.php or init.php');
    die('[ERROR] In modules/gateways/callback/btcpay.php: include error: Cannot find dbconnect.php or init.php');
}

require_once __DIR__ . '/../btcpay/bp_lib.php';
require_once __DIR__ . '/../btcpay/bp_invoice_contract.php';

$gatewaymodule = 'btcpay';
$GATEWAY       = getGatewayVariables($gatewaymodule);
$callbackTraceId = bpGenerateCallbackTraceId();

/**
 * Write opt-in, bounded callback diagnostics to the two WHMCS logs an
 * administrator can inspect without shell access, plus the PHP error log.
 * Failures in diagnostic logging must never interrupt payment processing.
 *
 * @param array  $gateway
 * @param string $traceId
 * @param string $stage
 * @param array  $context
 * @return void
 */
function bpWriteCallbackTrace(array $gateway, $traceId, $stage, array $context = array())
{
    if (!bpGatewayOptionEnabled(
        isset($gateway['callbackDiagnostics']) ? $gateway['callbackDiagnostics'] : null
    )) {
        return;
    }

    $stage = preg_match('/^[a-z][a-z0-9_]{0,63}$/D', (string) $stage) === 1
        ? (string) $stage
        : 'unknown_stage';
    $context = array_merge(array('trace_id' => (string) $traceId), $context);
    $encodedContext = bpFormatCallbackDiagnosticContext($context);
    $message = 'BTCPay callback [' . $traceId . '] ' . $stage . ' ' . $encodedContext;
    $gatewayName = isset($gateway['name']) && is_scalar($gateway['name'])
        ? (string) $gateway['name']
        : 'BTCPay Server';

    try {
        bpLog('[INFO] ' . $message);
    } catch (Throwable $exception) {
        // Diagnostics are best effort only.
    }

    if (function_exists('logActivity')) {
        try {
            logActivity($message);
        } catch (Throwable $exception) {
            bpLog('[WARNING] Unable to write BTCPay callback trace ' . $traceId . ' to the Activity Log.');
        }
    }

    if (function_exists('logTransaction')) {
        try {
            logTransaction(
                $gatewayName,
                $context,
                'Callback diagnostics: ' . $stage
            );
        } catch (Throwable $exception) {
            bpLog('[WARNING] Unable to write BTCPay callback trace ' . $traceId . ' to the Gateway Log.');
        }
    }
}

/**
 * @param int    $status
 * @param string $publicMessage
 * @return void
 */
function bpAbortCallback($status, $publicMessage)
{
    global $GATEWAY, $callbackTraceId;

    bpWriteCallbackTrace($GATEWAY, $callbackTraceId, 'callback_rejected', array(
        'http_status' => (int) $status,
        'reason' => (string) $publicMessage,
    ));

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-BTCPay-WHMCS-Trace: ' . $callbackTraceId);
        if ($status === 405) {
            header('Allow: POST');
        }
    }

    echo $publicMessage . "\nTrace: " . $callbackTraceId;
    exit;
}

/**
 * @param string $stage
 * @param array  $context
 * @return void
 */
function bpCompleteCallback($stage, array $context = array())
{
    global $GATEWAY, $callbackTraceId;

    bpWriteCallbackTrace($GATEWAY, $callbackTraceId, $stage, $context);

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-BTCPay-WHMCS-Trace: ' . $callbackTraceId);
    }

    echo 'OK ' . $callbackTraceId;
    exit;
}

if (!$GATEWAY['type']) {
    logTransaction($GATEWAY['name'], array(), 'Not activated');
    bpLog('[ERROR] In modules/gateways/callback/btcpay.php: btcpay module not activated');
    bpAbortCallback(503, 'Payment gateway is unavailable.');
}

$requestBody = file_get_contents(
    'php://input',
    false,
    null,
    0,
    BP_MAX_CALLBACK_BODY_BYTES + 1
);
bpWriteCallbackTrace(
    $GATEWAY,
    $callbackTraceId,
    'request_received',
    bpBuildCallbackRequestDiagnostics($_SERVER, $requestBody)
);
$request = bpParseCallbackRequest($_SERVER, $requestBody);
if (isset($request['error'])) {
    logTransaction($GATEWAY['name'], array(), 'Rejected callback request: ' . $request['error']);
    bpLog('[ERROR] In modules/gateways/callback/btcpay.php: ' . $request['error']);
    bpAbortCallback($request['status'], $request['error']);
}

$response = bpVerifyNotification(
    $GATEWAY['apiKey'],
    $GATEWAY['btcpayUrl'],
    $request['payload']
);

if (!is_array($response) || isset($response['error']) ||
    !isset($response['data']) || !is_array($response['data'])) {
    $responseError = is_array($response) && isset($response['error'])
        ? $response['error']
        : 'Malformed BTCPay API response.';
    $errorKind = is_array($response) && isset($response['error_kind'])
        ? $response['error_kind']
        : 'upstream';
    $callbackId = isset($request['payload']['id']) && is_scalar($request['payload']['id'])
        ? (string) $request['payload']['id']
        : '[invalid]';
    logTransaction(
        $GATEWAY['name'],
        array('btcpayInvoiceId' => $callbackId),
        'Callback verification failed: ' . $responseError
    );
    bpLog('[ERROR] In modules/gateways/callback/btcpay.php: Invalid response received: ' . $responseError);
    bpAbortCallback($errorKind === 'request' ? 400 : 502, 'Unable to verify callback.');
} else {
    $invoiceData = $response['data'];
    if (!isset($invoiceData['id']) || !is_scalar($invoiceData['id'])) {
        logTransaction($GATEWAY['name'], $response, 'Missing BTCPay invoice ID');
        bpLog('[ERROR] In modules/gateways/callback/btcpay.php: Authenticated BTCPay response did not contain an invoice ID.');
        bpAbortCallback(502, 'BTCPay Server returned an invalid response.');
    }

    $transid = (string) $invoiceData['id'];

    try {
        $contract = bpFindInvoiceContract($transid);
    } catch (Throwable $exception) {
        logTransaction($GATEWAY['name'], $response, 'Invoice contract storage error');
        bpLog('[ERROR] In modules/gateways/callback/btcpay.php: Unable to load invoice contract for ' . $transid . ': ' . $exception->getMessage());
        bpAbortCallback(500, 'Unable to validate invoice mapping.');
    }

    if (!$contract) {
        logTransaction($GATEWAY['name'], $response, 'Unknown or legacy BTCPay invoice');
        bpLog('[ERROR] In modules/gateways/callback/btcpay.php: Refusing callback for unmapped BTCPay invoice ' . $transid . '.');
        bpAbortCallback(409, 'Unknown BTCPay invoice.');
    }

    $contractError = bpValidateBtcpayInvoiceContract($invoiceData, $contract);
    if ($contractError !== null) {
        logTransaction($GATEWAY['name'], $response, 'BTCPay invoice contract mismatch');
        bpLog('[ERROR] In modules/gateways/callback/btcpay.php: Refusing BTCPay invoice ' . $transid . ': ' . $contractError);
        bpAbortCallback(409, 'Invoice validation failed.');
    }

    $whmcsid = (int) $contract->whmcs_invoice_id;
    if ($whmcsid <= 0) {
        logTransaction($GATEWAY['name'], $response, 'Invalid mapped WHMCS invoice ID');
        bpLog('[ERROR] In modules/gateways/callback/btcpay.php: Stored contract contains an invalid WHMCS invoice ID.');
        bpAbortCallback(500, 'Unable to validate invoice mapping.');
    }

    $status = strtolower(trim($invoiceData['status']));
    bpWriteCallbackTrace($GATEWAY, $callbackTraceId, 'invoice_verified', array(
        'btcpay_invoice_id' => $transid,
        'whmcs_invoice_id' => $whmcsid,
        'status' => $status,
    ));
    $recordStatus = function ($newStatus) use ($GATEWAY, $invoiceData, $response, $transid) {
        try {
            return bpWithLockedInvoiceContract($transid, function ($lockedContract) use (
                $invoiceData,
                $newStatus
            ) {
                $contractError = bpValidateBtcpayInvoiceContract($invoiceData, $lockedContract);
                if ($contractError !== null) {
                    throw new RuntimeException($contractError);
                }

                $manualReview = bpInvoiceStatusRequiresManualReview(
                    $lockedContract->processed_at,
                    $newStatus
                ) && strtolower((string) $lockedContract->status) !== strtolower($newStatus);
                bpUpdateInvoiceContractStatus($lockedContract->id, $newStatus);

                return array(
                    'processed' => $lockedContract->processed_at !== null,
                    'manual_review' => $manualReview,
                );
            });
        } catch (Throwable $exception) {
            logTransaction($GATEWAY['name'], $response, 'Invoice contract status update failed');
            bpLog('[ERROR] In modules/gateways/callback/btcpay.php: Unable to update contract status for ' . $transid . ': ' . $exception->getMessage());
            bpAbortCallback(500, 'Unable to update invoice status.');
        }
    };

    // Handle terminal non-payment states from the authenticated BTCPay record
    // before validating the mutable WHMCS invoice. This guarantees that a
    // post-credit invalidation is escalated even if an administrator later
    // edited or deleted the WHMCS invoice.
    if ($status === 'expired' || $status === 'invalid') {
        $statusResult = $recordStatus($status);
        if ($statusResult['manual_review']) {
            $reviewMessage = 'MANUAL REVIEW REQUIRED: BTCPay transaction ' . $transid .
                ' for WHMCS invoice #' . $whmcsid .
                ' was reported as ' . $status .
                ' after the invoice had already been credited. No automatic reversal was made.';
            logTransaction($GATEWAY['name'], $response, $reviewMessage);
            bpLog('[SECURITY] ' . $reviewMessage);
            if (function_exists('logActivity')) {
                logActivity($reviewMessage);
            }
        } else {
            logTransaction(
                $GATEWAY['name'],
                $response,
                $status === 'expired'
                    ? 'The BTCPay invoice expired without being credited.'
                    : 'The transaction is invalid. Do not process this order!'
            );
        }

        bpCompleteCallback('terminal_status_recorded', array(
            'btcpay_invoice_id' => $transid,
            'whmcs_invoice_id' => $whmcsid,
            'status' => $status,
            'manual_review' => $statusResult['manual_review'],
        ));
    }

    try {
        $invoice = bpGetWhmcsInvoiceForContract($whmcsid);
    } catch (Throwable $exception) {
        logTransaction($GATEWAY['name'], $response, 'WHMCS invoice lookup failed');
        bpLog('[ERROR] In modules/gateways/callback/btcpay.php: Unable to load WHMCS invoice for BTCPay invoice ' . $transid . ': ' . $exception->getMessage());
        bpAbortCallback(500, 'Unable to validate WHMCS invoice.');
    }
    if (!$invoice) {
        logTransaction($GATEWAY['name'], $response, 'Mapped WHMCS invoice no longer exists');
        bpLog('[ERROR] In modules/gateways/callback/btcpay.php: Mapped WHMCS invoice no longer exists for BTCPay invoice ' . $transid . '.');
        bpAbortCallback(409, 'WHMCS invoice validation failed.');
    }
    $contractError = bpValidateWhmcsInvoiceContract($invoice, $contract);
    if ($contractError !== null) {
        logTransaction($GATEWAY['name'], $response, 'WHMCS invoice contract mismatch');
        bpLog('[ERROR] In modules/gateways/callback/btcpay.php: Refusing payment for BTCPay invoice ' . $transid . ': ' . $contractError);
        bpAbortCallback(409, 'WHMCS invoice validation failed.');
    }

    $applyPayment = function ($logMessage) use (
        $GATEWAY,
        $gatewaymodule,
        $invoiceData,
        $response,
        $status,
        $transid
    ) {
        try {
            $result = bpWithLockedInvoiceContract($transid, function ($lockedContract) use (
                $gatewaymodule,
                $invoiceData,
                $status,
                $transid
            ) {
                if ($lockedContract->processed_at !== null) {
                    // Keep the authenticated lifecycle status current without
                    // attempting to credit the WHMCS invoice a second time.
                    bpUpdateInvoiceContractStatus($lockedContract->id, $status);
                    return 'duplicate';
                }

                $contractError = bpValidateBtcpayInvoiceContract($invoiceData, $lockedContract);
                if ($contractError !== null) {
                    throw new RuntimeException($contractError);
                }

                $lockedInvoice = bpGetWhmcsInvoiceForContract(
                    $lockedContract->whmcs_invoice_id,
                    true
                );
                if (!$lockedInvoice) {
                    throw new RuntimeException('Mapped WHMCS invoice no longer exists.');
                }

                $contractError = bpValidateWhmcsInvoiceContract($lockedInvoice, $lockedContract);
                if ($contractError !== null) {
                    throw new RuntimeException($contractError);
                }

                // Preserve WHMCS's transaction-level duplicate protection in addition
                // to serializing this contract row.
                checkCbTransID($transid);

                addInvoicePayment(
                    (int) $lockedContract->whmcs_invoice_id,
                    $transid,
                    bpNormalizeDecimal($lockedContract->whmcs_amount),
                    0.00,
                    $gatewaymodule
                );

                bpUpdateInvoiceContractStatus($lockedContract->id, $status, true);

                return 'processed';
            });
        } catch (Throwable $exception) {
            logTransaction($GATEWAY['name'], $response, 'Secure invoice processing failed');
            bpLog('[ERROR] In modules/gateways/callback/btcpay.php: Unable to process BTCPay invoice ' . $transid . ': ' . $exception->getMessage());
            bpAbortCallback(500, 'Invoice payment processing failed.');
        }

        if ($result === 'duplicate') {
            logTransaction($GATEWAY['name'], $response, 'Duplicate callback ignored.');
            return 'duplicate';
        }

        logTransaction($GATEWAY['name'], $response, $logMessage);

        return 'processed';
    };

    $outcome = 'status_recorded';
    switch ($status) {
        case 'paid':
            // New payment, not confirmed
            $recordStatus($status);
            logTransaction($GATEWAY['name'], $response, 'The payment has been received, but the transaction has not been confirmed on the bitcoin network. This will be updated when the transaction has been confirmed.');
            $outcome = 'awaiting_confirmation';
            break;
        case 'confirmed':
            // Apply Payment to Invoice
            $outcome = $applyPayment('The payment has been received, and the transaction has been confirmed on the bitcoin network. This will be updated when the transaction has been completed.') === 'duplicate'
                ? 'duplicate_callback'
                : 'payment_applied';
            break;
        case 'complete':
            // Apply Payment to Invoice
            $outcome = $applyPayment('The transaction is now complete.') === 'duplicate'
                ? 'duplicate_callback'
                : 'payment_applied';
            break;
        default:
            $recordStatus($status);
            logTransaction($GATEWAY['name'], $response, 'Unknown response received.');
    }

    bpCompleteCallback($outcome, array(
        'btcpay_invoice_id' => $transid,
        'whmcs_invoice_id' => $whmcsid,
        'status' => $status,
    ));
}
