<?php

require __DIR__ . '/support.php';

use BTCPayWHMCS\Greenfield;
use BTCPayServer\Http\Response;

$http = new FixtureHttp();
$client = new Greenfield(testSettings(), $http);
$client->createInvoice(42, '10.123456789123456789', 'USD', 'https://billing.example.test/viewinvoice.php?id=42',
    'https://billing.example.test/viewinvoice.php?id=42', 'medium');
$request = $http->requests[0];
$body = json_decode($request['body'], true, 32, JSON_THROW_ON_ERROR);
testSame('POST', $request['method'], 'Uses the SDK invoice creation call');
testSame('https://pay.example.test/btcpay/api/v1/stores/STORE-1/invoices', $request['url'], 'Supports a BTCPay subdirectory');
testSame('token test-greenfield-key', $request['headers']['Authorization'], 'Uses Greenfield token authentication');
testSame('10.123456789123456789', $body['amount'], 'Does not round monetary strings through floats');
testSame('42', $body['metadata']['orderId'], 'Sends searchable order metadata');
testSame('whmcs', $body['metadata']['integration'], 'Identifies early webhook deliveries for retry');
testSame(false, isset($body['notificationURL']), 'Store webhooks replace per-invoice IPNs');
testSame(false, isset($body['metadata']['buyerEmail']), 'Does not disclose buyer profiles');
testSame('MediumSpeed', $body['checkout']['speedPolicy'], 'Preserves one-confirmation policy');
testSame(0, $body['checkout']['paymentTolerance'], 'Does not inherit underpayment tolerance');
testSame(true, $body['checkout']['redirectAutomatically'], 'Returns the browser to WHMCS');
foreach (['low' => 'LowSpeed', 'lowmedium' => 'LowMediumSpeed', 'high' => 'HighSpeed', 'default' => null] as $speed => $expected) {
    testSame($expected, Greenfield::speedPolicy($speed), 'Maps speed ' . $speed);
}
testThrows(fn () => Greenfield::speedPolicy('typo'), 'Rejects invalid speed', 503);

$rotated = testSettings();
$rotated['apiKey'] = 'rotated-key';
testSame($client->connectionKey(), (new Greenfield($rotated, $http))->connectionKey(), 'Key rotation preserves invoice scope');
$rotated['storeId'] = 'STORE-2';
testThrows(fn () => bpValidateInvoiceConnection(['connection_key' => $client->connectionKey()], new Greenfield($rotated, $http)), 'Rejects another store', 409);
$incomplete = testSettings();
unset($incomplete['webhookSecret']);
testThrows(fn () => new Greenfield($incomplete, $http), 'Requires webhook configuration before checkout', 503);

testSame(true, Greenfield::grants(['btcpay.store.canviewinvoices:STORE-1'], 'btcpay.store.canviewinvoices', 'STORE-1'), 'Accepts a scoped key');
testSame(false, Greenfield::grants(['btcpay.store.canviewinvoices:STORE-2'], 'btcpay.store.canviewinvoices', 'STORE-1'), 'Rejects wrong scope');
$checks = new FixtureHttp(fn ($method, $url) => str_ends_with($url, '/api-keys/current')
    ? ['id' => 'akid_new-format', 'permissions' => ['btcpay.store.canviewinvoices:STORE-1', 'btcpay.store.cancreateinvoice:STORE-1']]
    : []);
(new Greenfield(testSettings(), $checks))->checkConnection();
testSame(2, count($checks->requests), 'Checks current permissions and store invoice access without reading a secret from the response');
$unrelated = (new Greenfield(testSettings(), new FixtureHttp(fn () => ['id' => 'BTCPAY-123', 'metadata' => null, 'amount' => null, 'type' => 'TopUp'])))->getInvoice('BTCPAY-123');
testSame(null, $unrelated['metadata'], 'Can identify unrelated invoices before requiring WHMCS metadata');
foreach ([401, 403, 404, 500] as $status) {
    $failing = new Greenfield(testSettings(), new FixtureHttp(fn () => new Response($status, 'sensitive-response-content', [])));
    try {
        $failing->getInvoice('BTCPAY-123');
        throw new RuntimeException('Expected API error');
    } catch (BTCPayWHMCS\GatewayException $exception) {
        testSame(false, str_contains($exception->getMessage(), 'sensitive'), 'Sanitizes SDK exceptions');
        testSame(502, $exception->httpStatus, 'API failure asks for webhook retry');
    }
}

$event = ['invoiceId' => 'BTCPAY-123', 'storeId' => 'STORE-1', 'type' => 'InvoiceSettled'];
$raw = json_encode($event);
$server = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json',
    'HTTP_BTCPAY_SIG' => 'sha256=' . hash_hmac('sha256', $raw, 'test-webhook-secret')];
testSame('BTCPAY-123', $client->webhook($server, $raw)['invoice_id'], 'Accepts the SDK-verified signature');
testThrows(fn () => $client->webhook($server, $raw . ' '), 'Authenticates exact raw bytes', 401);
testThrows(fn () => $client->webhook(array_diff_key($server, ['HTTP_BTCPAY_SIG' => true]), $raw), 'Rejects unsigned legacy IPNs', 401);
testThrows(fn () => $client->webhook($server, str_repeat('x', 65537)), 'Bounds request size', 413);
testThrows(fn () => $client->webhook($server, '[]'), 'Rejects JSON lists', 400);
foreach ([['storeId' => 'STORE-2'], ['invoiceId' => ['BTCPAY-123']]] as $override) {
    $bad = json_encode(array_replace($event, $override));
    $headers = $server;
    $headers['HTTP_BTCPAY_SIG'] = 'sha256=' . hash_hmac('sha256', $bad, 'test-webhook-secret');
    testThrows(fn () => $client->webhook($headers, $bad), 'Rejects a signed but invalid event');
}

$invoice = ['id' => 42, 'total' => '9.00', 'currency' => 'EUR', 'status' => 'Unpaid', 'paymentmethod' => 'btcpay'];
foreach (['New', 'Processing', 'Expired', 'Invalid'] as $status) {
    testSame(false, bpPaymentDecision(array_replace(testInvoice(), ['status' => $status]), testContract(), $invoice)['credit'], 'Does not credit ' . $status);
}
$settled = array_replace(testInvoice(), ['status' => 'Settled']);
foreach (['None', 'PaidOver', 'Marked', 'PaidLate'] as $additional) {
    testSame(true, bpPaymentDecision(array_replace($settled, ['additionalStatus' => $additional]), testContract(), $invoice)['credit'], 'Accepts explicitly settled ' . $additional);
}
foreach (['PaidPartial', 'Invalid', 'FutureStatus', null] as $additional) {
    testThrows(fn () => bpPaymentDecision(array_replace($settled, ['additionalStatus' => $additional]), testContract(), $invoice),
        'Rejects unsupported or null settled additional status', 409);
}
testThrows(fn () => bpPaymentDecision(array_diff_key($settled, ['additionalStatus' => true]), testContract(), $invoice),
    'Does not assume a missing additional status means None', 409);
foreach (['Expired', 'Invalid'] as $terminal) {
    $overpaidTerminal = bpPaymentDecision(array_replace(testInvoice(), ['status' => $terminal, 'additionalStatus' => 'PaidOver']), testContract(), $invoice);
    testSame(false, $overpaidTerminal['credit'], 'Does not credit overpaid terminal invoice');
    testSame(true, $overpaidTerminal['manual_review'], 'Flags overpaid terminal invoice for review');
}
$late = array_replace(testInvoice(), ['status' => 'Expired', 'additionalStatus' => 'PaidLate']);
testSame(false, bpPaymentDecision($late, testContract(), $invoice)['credit'], 'PaidLate alone is not settlement');
testSame(true, bpPaymentDecision($late, testContract(), $invoice)['manual_review'], 'Flags expired late payments');
$processed = array_replace(testContract(), ['processed_at' => '2026-09-07 12:00:00', 'status' => 'settled']);
testSame('duplicate_callback', bpPaymentDecision($settled, $processed, null)['outcome'], 'A credited invoice is not credited twice');
testSame('settled', bpPaymentDecision(testInvoice(), $processed, null)['status'], 'Does not regress a credited invoice to awaiting payment');
foreach (['Expired', 'Invalid'] as $terminal) {
    $terminalInvoice = array_replace(testInvoice(), ['status' => $terminal]);
    testSame(true, bpPaymentDecision($terminalInvoice, $processed, null)['manual_review'],
        'Flags post-credit ' . $terminal . ' even after WHMCS invoice deletion');
    testSame(false, bpPaymentDecision($terminalInvoice, testContract(), $invoice)['manual_review'],
        'Unpaid ' . $terminal . ' without a payment does not require post-credit review');
    testSame(false, bpPaymentDecision($terminalInvoice, array_replace($processed, ['status' => strtolower($terminal)]), null)['manual_review'],
        'Repeated ' . $terminal . ' does not flag the same post-credit transition again');
}
testThrows(fn () => bpPaymentDecision(array_replace($settled, ['amount' => '0.01']), testContract(), $invoice), 'Rejects changed BTCPay amount', 409);
testThrows(fn () => bpPaymentDecision($settled, testContract(), array_replace($invoice, ['total' => '99'])), 'Rejects changed WHMCS amount', 409);
testThrows(fn () => bpPaymentDecision($settled, testContract(), array_replace($invoice, ['paymentmethod' => 'other'])), 'Rejects changed gateway', 409);
testThrows(fn () => bpPaymentDecision($settled, testContract(), array_replace($invoice, ['status' => 'Paid'])), 'Does not credit a second BTCPay invoice for a paid WHMCS bill', 409);
testThrows(fn () => bpPaymentDecision(array_replace($settled, ['metadata' => null]), testContract(), $invoice), 'Never guesses the WHMCS mapping', 409);
echo "Greenfield client, webhook and settlement tests passed.\n";
