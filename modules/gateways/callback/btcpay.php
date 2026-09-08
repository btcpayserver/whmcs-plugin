<?php
/**
 * BTCPay Server webhook receiver.
 * Copyright (c) 2011-2018 BitPay, BTCPay Server (c) 2019-2026.
 * Distributed under the MIT license; see LICENSE.
 */

use BTCPayWHMCS\GatewayException;
use BTCPayWHMCS\Greenfield;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../btcpay/bootstrap.php';

$gateway = getGatewayVariables('btcpay');
$trace = bpGenerateCallbackTraceId();
$status = 200;
$message = 'OK';
$body = file_get_contents('php://input', false, null, 0, BP_MAX_CALLBACK_BODY_BYTES + 1);
bpWriteWebhookTrace($gateway, $trace, 'request_received', bpBuildCallbackRequestDiagnostics($_SERVER, $body));

try {
    if (empty($gateway['type'])) {
        throw new GatewayException('Payment gateway is unavailable.', 503);
    }
    $gateway = bpGetGatewaySettings();
    $client = new Greenfield($gateway);
    $event = $client->webhook($_SERVER, is_string($body) ? $body : '');
    bpWriteWebhookTrace($gateway, $trace, 'signature_verified');
    $result = bpProcessWebhook($client, $event);
    bpLogPaymentResult($gateway, $result);
    unset($result['invoice']);
    bpWriteWebhookTrace($gateway, $trace, $result['outcome'], $result + $event);
} catch (GatewayException $exception) {
    $status = $exception->httpStatus;
    $message = $exception->getMessage();
} catch (Throwable $exception) {
    $status = 503;
    $message = 'BTCPay processing is unavailable. Check gateway configuration and dependencies.';
}
if ($status !== 200) {
    bpWriteWebhookTrace($gateway, $trace, 'callback_rejected', ['http_status' => $status, 'reason' => $message]);
    error_log('BTCPay webhook [' . $trace . '] HTTP ' . $status . ': ' . $message);
    if (function_exists('logTransaction')) {
        logTransaction($gateway['name'] ?? 'BTCPay Server', ['trace_id' => $trace, 'http_status' => $status], $message);
    }
}
http_response_code($status);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
header('X-BTCPay-WHMCS-Trace: ' . $trace);
if ($status === 405) {
    header('Allow: POST');
}
echo $message . "\nTrace: " . $trace;
