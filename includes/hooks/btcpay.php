<?php

// This file must be uploaded alongside modules/. No public endpoint is added.
if (!defined('WHMCS') || !defined('ADMINAREA') || !ADMINAREA ||
    ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
    basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'configgateways.php') {
    return;
}
$btcpayBootstrap = __DIR__ . '/../../modules/gateways/btcpay/bootstrap.php';
if (!is_file($btcpayBootstrap)) {
    return;
}
require_once $btcpayBootstrap;
try {
    bpObserveGatewaySettingsSaves('register_shutdown_function');
} catch (Throwable $exception) {
    error_log('BTCPay: unable to observe gateway settings saves. Use the connection page to retry webhook setup.');
}
