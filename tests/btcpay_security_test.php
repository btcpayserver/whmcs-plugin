<?php

require_once __DIR__ . '/../modules/gateways/btcpay/bootstrap.php';
require_once __DIR__ . '/../modules/gateways/btcpay.php';

$securityAssertions = 0;

function bpSecurityAssertSame($expected, $actual, $message)
{
    global $securityAssertions;
    $securityAssertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '\nExpected: ' . var_export($expected, true) .
            '\nActual: ' . var_export($actual, true)
        );
    }
}

function bpSecurityAssertContains($needle, $haystack, $message)
{
    global $securityAssertions;
    $securityAssertions++;
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . '\nMissing: ' . var_export($needle, true));
    }
}

function bpSecurityAssertNotContains($needle, $haystack, $message)
{
    global $securityAssertions;
    $securityAssertions++;
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message . '\nUnexpected: ' . var_export($needle, true));
    }
}

function bpSecurityAssertThrows(callable $callback, $message)
{
    global $securityAssertions;
    $securityAssertions++;
    try {
        $callback();
    } catch (Throwable $exception) {
        return;
    }

    throw new RuntimeException($message);
}

bpSecurityAssertSame(
    '&quot;&lt;&gt;&amp;&#039;',
    bpEscapeHtmlAttribute('"<>&\''),
    'Escapes quotes, angle brackets, and ampersands in attributes.'
);
bpSecurityAssertSame(
    "invalid\u{FFFD}(",
    bpEscapeHtmlAttribute("invalid\xC3\x28"),
    'Substitutes invalid UTF-8 while escaping attributes.'
);

$clientFields = array(
    'firstname',
    'lastname',
    'email',
    'address1',
    'address2',
    'city',
    'state',
    'postcode',
    'country',
    'phonenumber',
);
$clientDetails = array();
foreach ($clientFields as $field) {
    $clientDetails[$field] = $field . '"><script>stored-xss</script>&\'' . "\xC3\x28";
}

$form = btcpay_link(array(
    'invoiceid' => '42"><script>invoice-xss</script>',
    'systemurl' => 'https://billing.example.test/whmcs/',
    'langpaynow' => 'Pay"><script>button-xss</script>&\'' . "\xC3\x28",
    'clientdetails' => $clientDetails,
    'sendCustomerEmail' => 'on',
));

bpSecurityAssertContains(
    'action="/whmcs/modules/gateways/btcpay/createinvoice.php"',
    $form,
    'Uses a same-site relative form action.'
);
bpSecurityAssertContains(
    'value="42&quot;&gt;&lt;script&gt;invoice-xss&lt;/script&gt;"',
    $form,
    'Escapes the invoice ID attribute.'
);
bpSecurityAssertContains(
    'value="Pay&quot;&gt;&lt;script&gt;button-xss&lt;/script&gt;&amp;&#039;�("',
    $form,
    'Escapes the submit label and substitutes invalid UTF-8.'
);
bpSecurityAssertNotContains('<script>', $form, 'Does not emit executable client markup.');
bpSecurityAssertNotContains('systemURL', $form, 'Does not post a client-controlled system URL.');
bpSecurityAssertNotContains('ipnURL', $form, 'Does not post a client-controlled callback URL.');
bpSecurityAssertNotContains('buyerName', $form, 'Does not send browser-controlled buyer metadata.');
bpSecurityAssertNotContains('buyerEmail', $form, 'Does not post customer email even when sharing is enabled.');
bpSecurityAssertNotContains('sendCustomerEmail', $form, 'Does not post the administrator email-sharing setting.');
foreach ($clientFields as $field) {
    bpSecurityAssertNotContains(
        $field . '&quot;',
        $form,
        'Does not render the ' . $field . ' client field into the form.'
    );
}

$createInvoiceSource = file_get_contents(
    __DIR__ . '/../modules/gateways/btcpay/createinvoice.php'
);
$callbackSource = file_get_contents(
    __DIR__ . '/../modules/gateways/callback/btcpay.php'
);
bpSecurityAssertNotContains('ipnURL', $createInvoiceSource, 'Never reads a posted callback URL.');
bpSecurityAssertNotContains('systemURL', $createInvoiceSource, 'Never reads a posted system URL.');
bpSecurityAssertNotContains('$options = $_POST', $createInvoiceSource, 'Does not copy browser input into BTCPay options.');
bpSecurityAssertContains("\$_SESSION['uid']", $createInvoiceSource, 'Requires an authenticated WHMCS client session.');
bpSecurityAssertContains('paymentmethod', $createInvoiceSource, 'Requires the invoice to use the BTCPay gateway.');
foreach (array(
    'buyerName',
    'buyerAddress1',
    'buyerAddress2',
    'buyerCity',
    'buyerState',
    'buyerZip',
    'buyerPhone',
) as $buyerOption) {
    bpSecurityAssertNotContains(
        "'" . $buyerOption . "' =>",
        $createInvoiceSource,
        'Does not disclose ' . $buyerOption . ' to BTCPay.'
    );
}
bpSecurityAssertNotContains('defaultgateway', $callbackSource, 'Does not mutate the client-wide default gateway.');

$gatewayConfig = btcpay_config();
bpSecurityAssertSame('password', $gatewayConfig['apiKey']['Type'], 'Renders the API key as a password setting.');
bpSecurityAssertSame('yesno', $gatewayConfig['sendCustomerEmail']['Type'], 'Provides a customer email opt-in checkbox.');
bpSecurityAssertSame('', $gatewayConfig['sendCustomerEmail']['Default'], 'Customer email sharing starts unchecked.');
bpSecurityAssertContains('Warning:', $gatewayConfig['sendCustomerEmail']['Description'], 'Warns before enabling email sharing.');
bpSecurityAssertContains('sends the customer\'s email address to BTCPay Server',
    $gatewayConfig['sendCustomerEmail']['Description'], 'Explains which customer data is sent and its destination.');
bpSecurityAssertContains('may expose this customer data if the BTCPay invoice ID or checkout link is leaked',
    $gatewayConfig['sendCustomerEmail']['Description'], 'Explains the customer data exposure risk from leaked invoice identifiers.');
bpSecurityAssertSame(
    'yesno',
    $gatewayConfig['callbackDiagnostics']['Type'],
    'Provides an explicit callback diagnostics setting.'
);

bpSecurityAssertSame(
    'https://billing.example.test/whmcs/modules/gateways/callback/btcpay.php',
    bpBuildTrustedUrl(
        'https://billing.example.test/whmcs/',
        'modules/gateways/callback/btcpay.php'
    ),
    'Builds the callback URL exclusively from the configured WHMCS URL.'
);
bpSecurityAssertSame(
    'https://billing.example.test/whmcs/viewinvoice.php?id=42&paymentsuccess=true',
    bpBuildTrustedReturnUrl('https://billing.example.test/whmcs/', 42, ''),
    'Builds the default return URL from trusted configuration.'
);
bpSecurityAssertSame(
    'https://merchant.example.test/paid',
    bpBuildTrustedReturnUrl(
        'https://billing.example.test/whmcs/',
        42,
        'https://merchant.example.test/paid'
    ),
    'Accepts an administrator-configured absolute return URL.'
);
bpSecurityAssertThrows(function () {
    bpBuildTrustedReturnUrl('https://billing.example.test/', 42, 'javascript:alert(1)');
}, 'Rejects a non-HTTP administrator return URL.');

bpSecurityAssertSame(
    'https://pay.example.test/btcpay/i/abc?view=checkout',
    bpValidateCheckoutUrl(
        'https://pay.example.test/btcpay/i/abc?view=checkout',
        'https://pay.example.test/btcpay/'
    ),
    'Accepts a checkout under the configured BTCPay origin and path.'
);
bpSecurityAssertThrows(function () {
    bpValidateCheckoutUrl('https://attacker.example/i/abc', 'https://pay.example.test/');
}, 'Rejects a checkout URL on an attacker origin.');
bpSecurityAssertThrows(function () {
    bpValidateCheckoutUrl('https://pay.example.test/btcpay-evil/i/abc', 'https://pay.example.test/btcpay/');
}, 'Rejects a same-origin checkout outside the configured base path.');
bpSecurityAssertThrows(function () {
    bpValidateCheckoutUrl("https://pay.example.test/i/abc\r\nX-Test: injected", 'https://pay.example.test/');
}, 'Rejects control characters in a checkout URL.');
bpSecurityAssertThrows(function () {
    bpValidateCheckoutUrl('https://pay.example.test\\@attacker.example/i/abc', 'https://pay.example.test/');
}, 'Rejects browser-ambiguous backslashes in a checkout URL.');
bpSecurityAssertSame(
    'http://storeabcdefghijklmnop.onion/btcpay/i/abc?view=checkout',
    bpMapCheckoutUrlToBase(
        'https://pay.example.test/btcpay/i/abc?view=checkout',
        'https://pay.example.test/btcpay/',
        'http://storeabcdefghijklmnop.onion/btcpay/'
    ),
    'Maps a validated checkout to the configured Tor base.'
);
bpSecurityAssertSame(
    true,
    bpRequestUsesOnionHost(array('HTTP_HOST' => 'shopabcdefghijklmnop.onion:8080')),
    'Recognizes an onion request without copying its Host header.'
);
bpSecurityAssertSame(
    false,
    bpRequestUsesOnionHost(array('HTTP_HOST' => "shop.onion\r\nX-Test: yes")),
    'Rejects a malformed Host header.'
);

$validBody = json_encode(array(
    'invoiceId' => 'BTCPAY-123',
    'type' => 'InvoiceSettled',
    'storeId' => 'STORE-1',
));
$parsedRequest = bpParseCallbackRequest(array(
    'REQUEST_METHOD' => 'POST',
    'CONTENT_TYPE' => 'application/json; charset=utf-8',
    'CONTENT_LENGTH' => (string) strlen($validBody),
), $validBody);
bpSecurityAssertSame('BTCPAY-123', $parsedRequest['payload']['invoiceId'], 'Parses a bounded JSON callback.');
bpSecurityAssertSame(true, bpGatewayOptionEnabled('on'), 'Recognizes a checked WHMCS yes/no setting.');
bpSecurityAssertSame(true, bpGatewayOptionEnabled(true), 'Recognizes a native enabled setting.');
bpSecurityAssertSame(false, bpGatewayOptionEnabled('off'), 'Does not treat the string off as enabled.');
bpSecurityAssertSame(false, bpGatewayOptionEnabled(''), 'Treats an unset diagnostics setting as disabled.');

$diagnosticBody = '{"private":"callback content is not logged"}';
$requestDiagnostics = bpBuildCallbackRequestDiagnostics(array(
    'REMOTE_ADDR' => '2001:db8::42',
    'REQUEST_METHOD' => "POST\r\nInjected: yes",
    'CONTENT_TYPE' => 'application/json; charset=utf-8',
    'CONTENT_LENGTH' => (string) strlen($diagnosticBody),
    'HTTP_AUTHORIZATION' => 'Bearer must-not-be-logged',
    'HTTP_COOKIE' => 'session=must-not-be-logged',
), $diagnosticBody);
bpSecurityAssertSame('2001:db8::42', $requestDiagnostics['source_ip'], 'Records the direct callback source IP.');
bpSecurityAssertSame(
    strlen($diagnosticBody),
    $requestDiagnostics['received_bytes'],
    'Records the received callback size.'
);
bpSecurityAssertSame(
    hash('sha256', $diagnosticBody),
    $requestDiagnostics['body_sha256'],
    'Records only a callback body digest.'
);
$formattedDiagnostics = bpFormatCallbackDiagnosticContext($requestDiagnostics);
bpSecurityAssertNotContains('callback content', $formattedDiagnostics, 'Does not log the callback request body.');
bpSecurityAssertNotContains('must-not-be-logged', $formattedDiagnostics, 'Does not log credentials or cookies.');
bpSecurityAssertNotContains("\r", $formattedDiagnostics, 'Removes control characters from diagnostics.');
bpSecurityAssertSame(
    1,
    preg_match('/^[a-f0-9]{16}$/D', bpGenerateCallbackTraceId()),
    'Generates a bounded callback trace ID.'
);
bpSecurityAssertSame(
    405,
    bpParseCallbackRequest(array(
        'REQUEST_METHOD' => 'GET',
        'CONTENT_TYPE' => 'application/json',
    ), $validBody)['status'],
    'Rejects callback methods other than POST.'
);
bpSecurityAssertSame(
    415,
    bpParseCallbackRequest(array(
        'REQUEST_METHOD' => 'POST',
        'CONTENT_TYPE' => 'text/plain',
    ), $validBody)['status'],
    'Rejects a non-JSON callback content type.'
);
bpSecurityAssertSame(
    400,
    bpParseCallbackRequest(array(
        'REQUEST_METHOD' => 'POST',
        'CONTENT_TYPE' => 'application/json',
    ), '{broken')['status'],
    'Rejects malformed callback JSON without throwing.'
);
bpSecurityAssertSame(
    413,
    bpParseCallbackRequest(array(
        'REQUEST_METHOD' => 'POST',
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string) (BP_MAX_CALLBACK_BODY_BYTES + 1),
    ), '{}')['status'],
    'Rejects a declared callback body larger than the limit.'
);

$validInvoiceData = array(
    'id' => 'BTCPAY-123',
    'metadata' => ['orderId' => '42'],
    'amount' => '10.00',
    'currency' => 'USD',
    'status' => 'Settled',
    'checkoutLink' => 'https://pay.example.test/i/BTCPAY-123',
);
bpSecurityAssertSame(
    null,
    bpValidateBtcpayInvoiceResponseData($validInvoiceData),
    'Accepts a complete scalar authenticated invoice schema.'
);
$invalidInvoiceData = $validInvoiceData;
$invalidInvoiceData['status'] = array('complete');
bpSecurityAssertSame(
    'Invoice status is invalid.',
    bpValidateBtcpayInvoiceResponseData($invalidInvoiceData),
    'Rejects a non-scalar authenticated invoice status.'
);
$invalidInvoiceData = $validInvoiceData;
$invalidInvoiceData['metadata'] = '{broken';
bpSecurityAssertSame(
    'Invoice metadata is invalid.',
    bpValidateBtcpayInvoiceResponseData($invalidInvoiceData),
    'Rejects malformed authenticated invoice metadata.'
);

echo 'Security tests passed (' . $securityAssertions . ' assertions).' . PHP_EOL;
