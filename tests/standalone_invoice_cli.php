<?php
/**
 * Explicit live example; never included in the automated test suite.
 * Usage: BTCPAY_URL=... BTCPAY_API_KEY=... BTCPAY_STORE_ID=... php tests/standalone_invoice_cli.php
 * Optional: BTCPAY_AMOUNT (10.00), BTCPAY_CURRENCY (USD).
 * This creates a real invoice without a WHMCS mapping; it cannot credit WHMCS.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (in_array('--help', $argv, true)) {
    echo "Set BTCPAY_URL, BTCPAY_API_KEY and BTCPAY_STORE_ID to create a live test invoice. Optional: BTCPAY_AMOUNT and BTCPAY_CURRENCY.\n";
    exit;
}
require_once __DIR__ . '/../modules/gateways/btcpay/bootstrap.php';

try {
    btcpayLoadDependencies();
    $url = bpNormalizeConfiguredBaseUrl(getenv('BTCPAY_URL'));
    $key = getenv('BTCPAY_API_KEY');
    $store = getenv('BTCPAY_STORE_ID');
    if (!is_string($key) || $key === '' || preg_match('/[\\x00-\\x20\\x7f]/', $key) || !bpIsValidInvoiceIdentifier($store)) {
        throw new InvalidArgumentException('Missing or invalid API key or store ID.');
    }
    $amount = bpNormalizeDecimal(getenv('BTCPAY_AMOUNT') ?: '10.00');
    if (!bpDecimalIsPositive($amount)) {
        throw new InvalidArgumentException('Amount must be positive.');
    }
    $currency = bpNormalizeCurrencyCode(getenv('BTCPAY_CURRENCY') ?: 'USD');
    $invoice = (new BTCPayServer\Client\Invoice($url, $key))->createInvoice(
        $store, $currency, BTCPayServer\Util\PreciseNumber::parseString($amount),
        'whmcs-cli-' . bin2hex(random_bytes(8)), null, ['itemDesc' => 'Standalone test invoice']
    );
    echo 'Invoice ID: ' . $invoice->getId() . PHP_EOL;
    echo 'Checkout: ' . bpValidateCheckoutUrl($invoice->getCheckoutLink(), $url) . PHP_EOL;
    echo 'Status: ' . $invoice->getStatus() . PHP_EOL;
} catch (Throwable $exception) {
    // SDK exception messages can contain complete API response bodies.
    fwrite(STDERR, "Invoice creation failed. Check dependencies, environment variables, connectivity and API permissions.\n");
    exit(1);
}
