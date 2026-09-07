<?php

require __DIR__ . '/support.php';

use BTCPayWHMCS\Greenfield;
use BTCPayServer\Http\Response;

$url = 'https://billing.example.test/whmcs/modules/gateways/callback/btcpay.php';
$settings = testSettings();
unset($settings['webhookSecret']);
$hooks = ['OTHER' => ['id' => 'OTHER', 'url' => 'https://another.example.test/callback']];
$creations = 0;
$http = new FixtureHttp(function ($method, $endpoint, $body) use (&$hooks, &$creations) {
    if (str_ends_with($endpoint, '/api-keys/current')) {
        return ['permissions' => ['btcpay.store.canviewinvoices:STORE-1',
            'btcpay.store.cancreateinvoice:STORE-1', Greenfield::WEBHOOK_PERMISSION . ':STORE-1']];
    }
    if (str_contains($endpoint, '/invoices?')) { return []; }
    if ($method === 'GET') {
        return str_ends_with($endpoint, '/webhooks') ? array_values($hooks)
            : ($hooks[basename($endpoint)] ?? new Response(404, 'Not found', []));
    }
    $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if ($method === 'POST') {
        testSame(false, array_key_exists('secret', $data), 'Lets BTCPay generate the secret');
        $id = 'HOOK-' . ++$creations;
        $hooks[$id] = ['id' => $id] + $data;
        return $hooks[$id] + ['secret' => 'generated-secret-' . $creations];
    }
    testSame('PUT', $method, 'Never deletes an existing webhook');
    $id = basename($endpoint);
    testSame(false, $id === 'OTHER', 'Does not change other integrations');
    $hooks[$id] = ['id' => $id] + $data;
    unset($hooks[$id]['secret']); // GET/PUT responses do not reveal secrets.
    return $hooks[$id];
});
$client = new Greenfield($settings, $http, false);
$client->checkConnection(true);
$saved = $client->provisionWebhook($url);
testSame('HOOK-1', $saved['id'], 'Stores webhook ID');
testSame('generated-secret-1', $saved['secret'], 'Stores generated secret');
testSame($client->connectionKey(), $saved['connection_key'], 'Binds webhook to server and store');
testSame(Greenfield::WEBHOOK_EVENTS, $hooks['HOOK-1']['authorizedEvents']['specificEvents'], 'Subscribes to payment lifecycle events');
testSame(true, $hooks['HOOK-1']['automaticRedelivery'], 'Enables automatic redelivery');
testSame(true, $hooks['HOOK-1']['enabled'], 'Enables webhook');
$hooks['HOOK-1']['enabled'] = false;
$again = $client->provisionWebhook($url, $saved);
testSame($saved, $again, 'Repeat saves reuse the ID and secret');
testSame(1, $creations, 'Does not create duplicate webhooks');
testSame(true, $hooks['HOOK-1']['enabled'], 'Repairs a disabled owned webhook');
$movedUrl = 'https://billing.example.test/new-whmcs/modules/gateways/callback/btcpay.php';
$moved = $client->provisionWebhook($movedUrl, $saved);
testSame($movedUrl, $hooks['HOOK-1']['url'], 'Updates the owned webhook when WHMCS moves');
testSame('generated-secret-1', $moved['secret'], 'Preserves secret when URL changes');
$hooks['HOOK-1']['url'] = 'https://unrelated.example.test/callback';
testThrows(fn () => $client->provisionWebhook($movedUrl, $moved), 'Does not repurpose a remotely changed webhook', 409);
unset($hooks['HOOK-1']);
$recreated = $client->provisionWebhook($url, $saved);
testSame('HOOK-2', $recreated['id'], 'Recreates a deleted owned webhook');
testSame('generated-secret-2', $recreated['secret'], 'Saves the replacement secret');
$before = count($http->requests);
testThrows(fn () => $client->provisionWebhook($url), 'Refuses duplicates after a lost creation response or local save failure', 409);
testSame($before + 1, count($http->requests), 'Ambiguous recovery performs only a read');
$manual = new Greenfield(testSettings(), $http, false);
$adopted = $manual->provisionWebhook($url);
testSame('HOOK-2', $adopted['id'], 'Can adopt a manual webhook using its supplied secret');
testSame('test-webhook-secret', $adopted['secret'], 'Preserves supplied manual secret');
testSame(2, $creations, 'Manual adoption does not duplicate');
$otherConnection = $saved;
$otherConnection['connection_key'] = str_repeat('0', 64);
testThrows(fn () => $manual->provisionWebhook($url, $otherConnection), 'Does not reuse another connection secret', 409);
testThrows(fn () => $client->createInvoice(42, '10', 'USD', $url, $url, 'medium'), 'Setup client cannot create invoices without a webhook secret', 503);
testThrows(fn () => $client->webhook([], '{}'), 'No signature is accepted with an empty secret', 503);

foreach ([401, 403, 500] as $code) {
    $failing = new FixtureHttp(fn () => new Response($code, 'sensitive-key-or-secret', []));
    $broken = new Greenfield($settings, $failing, false);
    testThrows(fn () => $broken->provisionWebhook($url, $saved), 'Does not recreate after an API failure', 502);
    testSame(['GET'], array_column($failing->requests, 'method'), 'Fails closed on inaccessible webhook');
}
$insufficient = new FixtureHttp(fn () => ['permissions' => ['btcpay.store.cancreateinvoice:STORE-1',
    'btcpay.store.canviewinvoices:STORE-1', Greenfield::WEBHOOK_PERMISSION . ':STORE-2']]);
testThrows(fn () => (new Greenfield($settings, $insufficient, false))->checkConnection(true), 'Requires webhook permission in the correct store', 503);
testSame(true, Greenfield::grants(['btcpay.store.canmodifystoresettings:STORE-1'], Greenfield::WEBHOOK_PERMISSION, 'STORE-1'), 'Accepts an implied webhook permission');
echo "Automatic webhook setup, permissions, secret preservation and recovery tests passed.\n";
