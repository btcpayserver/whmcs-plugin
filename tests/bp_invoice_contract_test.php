<?php

require_once __DIR__ . '/../modules/gateways/btcpay/bp_invoice_contract.php';

$assertions = 0;

function bpTestAssertSame($expected, $actual, $message)
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '\nExpected: ' . var_export($expected, true) .
            '\nActual: ' . var_export($actual, true)
        );
    }
}

function bpTestAssertNull($actual, $message)
{
    bpTestAssertSame(null, $actual, $message);
}

function bpTestAssertNotNull($actual, $message)
{
    global $assertions;
    $assertions++;
    if ($actual === null) {
        throw new RuntimeException($message);
    }
}

function bpTestAssertThrows(callable $callback, $message)
{
    global $assertions;
    $assertions++;
    try {
        $callback();
    } catch (Throwable $exception) {
        return;
    }

    throw new RuntimeException($message);
}

bpTestAssertSame('10.5', bpNormalizeDecimal('0010.5000'), 'Normalizes leading and trailing zeros.');
bpTestAssertSame('0.00000001', bpNormalizeDecimal('1e-8'), 'Expands negative scientific notation.');
bpTestAssertSame('12500', bpNormalizeDecimal('1.25e4'), 'Expands positive scientific notation.');
bpTestAssertSame('0', bpNormalizeDecimal('-0.000'), 'Canonicalizes negative zero.');
bpTestAssertSame(true, bpDecimalEquals('10.00', 10.0), 'Compares equivalent decimal representations.');
bpTestAssertSame(true, bpDecimalIsPositive('0.000000000000000001'), 'Accepts small positive exact amounts.');
bpTestAssertSame(false, bpDecimalIsPositive('-1'), 'Rejects negative amounts.');
bpTestAssertThrows(function () {
    bpNormalizeDecimal('not-a-number');
}, 'Rejects malformed decimals.');
bpTestAssertThrows(function () {
    bpNormalizeDecimal('1e1000');
}, 'Rejects unreasonable decimal exponents.');

bpTestAssertSame(
    '90',
    bpConvertCurrencyAmount('100.00', '1.0', '0.9'),
    'Converts currencies without floating-point arithmetic.'
);
bpTestAssertSame(
    '33.33333333',
    bpConvertCurrencyAmount('100', '3', '1'),
    'Uses the configured fixed-point conversion scale.'
);
bpTestAssertSame(
    '0.66666667',
    bpConvertCurrencyAmount('2', '3', '1'),
    'Rounds converted values half-up instead of truncating them.'
);
bpTestAssertThrows(function () {
    bpConvertCurrencyAmount('100', '0', '1');
}, 'Rejects a zero source currency rate.');

$convertedTransport = json_decode('{"amount":33.33333333}', true);
bpTestAssertSame(
    true,
    bpDecimalEquals(
        bpConvertCurrencyAmount('100', '3', '1'),
        $convertedTransport['amount']
    ),
    'Keeps converted values stable through JSON number decoding.'
);

$createdInvoice = array(
    'id' => 'BTCPAY-123',
    'metadata' => ['orderId' => '42'],
    'amount' => 10.0,
    'currency' => 'USD',
    'status' => 'New',
    'checkoutLink' => 'https://btcpay.example/i/BTCPAY-123',
);

bpTestAssertNull(
    bpValidateCreatedInvoiceData($createdInvoice, 42, '10.00', 'USD'),
    'Accepts a creation response matching the requested contract.'
);

$badCreatedAmount = $createdInvoice;
$badCreatedAmount['amount'] = '9.99';
bpTestAssertNotNull(
    bpValidateCreatedInvoiceData($badCreatedAmount, 42, '10.00', 'USD'),
    'Rejects a changed creation-response amount.'
);

$missingCheckoutUrl = $createdInvoice;
unset($missingCheckoutUrl['checkoutLink']);
bpTestAssertNotNull(
    bpValidateCreatedInvoiceData($missingCheckoutUrl, 42, '10.00', 'USD'),
    'Rejects a creation response without a checkout URL.'
);

$contract = array(
    'btcpay_invoice_id' => 'BTCPAY-123',
    'whmcs_invoice_id' => 42,
    'whmcs_amount' => '10',
    'whmcs_currency' => 'USD',
    'btcpay_amount' => '10.00',
    'btcpay_currency' => 'USD',
);

bpTestAssertNull(
    bpValidateBtcpayInvoiceContract($createdInvoice, $contract),
    'Accepts authenticated BTCPay data matching the persisted contract.'
);

$wrongBtcpayId = $createdInvoice;
$wrongBtcpayId['id'] = 'BTCPAY-OTHER';
bpTestAssertNotNull(
    bpValidateBtcpayInvoiceContract($wrongBtcpayId, $contract),
    'Rejects a different BTCPay invoice ID.'
);

$wrongOrder = $createdInvoice;
$wrongOrder['metadata']['orderId'] = '43';
bpTestAssertNotNull(
    bpValidateBtcpayInvoiceContract($wrongOrder, $contract),
    'Rejects a cross-order invoice collision.'
);

$wrongCurrency = $createdInvoice;
$wrongCurrency['currency'] = 'EUR';
bpTestAssertNotNull(
    bpValidateBtcpayInvoiceContract($wrongCurrency, $contract),
    'Rejects a different BTCPay currency.'
);

$wrongAmount = $createdInvoice;
$wrongAmount['amount'] = '0.01';
bpTestAssertNotNull(
    bpValidateBtcpayInvoiceContract($wrongAmount, $contract),
    'Rejects a low-value BTCPay invoice for a larger WHMCS contract.'
);

$whmcsInvoice = array(
    'id' => 42,
    'total' => '10.00',
    'currency' => 'USD',
);
bpTestAssertNull(
    bpValidateWhmcsInvoiceContract($whmcsInvoice, $contract),
    'Accepts an unchanged WHMCS invoice.'
);

$changedWhmcsTotal = $whmcsInvoice;
$changedWhmcsTotal['total'] = '100.00';
bpTestAssertNotNull(
    bpValidateWhmcsInvoiceContract($changedWhmcsTotal, $contract),
    'Rejects a WHMCS invoice total changed after checkout creation.'
);

$changedWhmcsCurrency = $whmcsInvoice;
$changedWhmcsCurrency['currency'] = 'EUR';
bpTestAssertNotNull(
    bpValidateWhmcsInvoiceContract($changedWhmcsCurrency, $contract),
    'Rejects a WHMCS invoice currency changed after checkout creation.'
);

bpTestAssertSame(
    true,
    bpInvoiceStatusRequiresManualReview('2026-08-14 12:00:00', 'invalid'),
    'Requires manual review when a credited transaction becomes invalid.'
);
bpTestAssertSame(
    false,
    bpInvoiceStatusRequiresManualReview(null, 'invalid'),
    'Does not report an uncredited invalid invoice as a post-credit invalidation.'
);
bpTestAssertSame(
    true,
    bpInvoiceStatusRequiresManualReview('2026-08-14 12:00:00', 'expired'),
    'Requires review when a credited transaction unexpectedly expires.'
);

echo 'Invoice contract tests passed (' . $assertions . ' assertions).' . PHP_EOL;
