<?php

require __DIR__ . '/webhook_fixture.php';

use BTCPayWHMCS\Greenfield;

$url = 'https://billing.example.test/whmcs/modules/gateways/callback/btcpay.php';
$settings = testSettings();
$settings['webhookSecret'] = '';
$http = new WebhookFixtureHttp($url);
$client = new Greenfield($settings, $http, false);
$saved = ['id' => 'OLD', 'secret' => 'old-secret', 'url' => $url, 'connection_key' => $client->connectionKey()];
$replacement = $client->prepareWebhookReplacement($url, $saved);
testSame('NEW-1', $replacement['webhook']['id'], 'Replacement gets a new webhook ID');
testSame('generated-secret-1', $replacement['webhook']['secret'], 'Replacement gets a fresh generated secret');
testSame(true, isset($http->hooks['OLD']), 'Creation does not delete the working webhook');
testSame(false, in_array('DELETE', array_column($http->requests, 'method'), true), 'Preparation never deletes');
$client->deleteReplacedWebhook($replacement['previous'], $replacement['webhook']);
testSame(false, isset($http->hooks['OLD']), 'Explicit cleanup deletes only the old webhook');
testSame(true, isset($http->hooks['NEW-1'], $http->hooks['OTHER']), 'Keeps the replacement and unrelated integration');
testThrows(fn () => $client->deleteReplacedWebhook($replacement['webhook'], $replacement['webhook']), 'Never deletes the active webhook', 409);
$foreign = $replacement['previous'];
$foreign['connection_key'] = str_repeat('0', 64);
testThrows(fn () => $client->deleteReplacedWebhook($foreign, $replacement['webhook']), 'Never deletes across connections', 409);

foreach (['missing_secret', 'missing_record', 'stale_id', 'foreign_connection', 'deleted_remote', 'moved_callback'] as $scenario) {
    $http = new WebhookFixtureHttp($url);
    $client = new Greenfield($settings, $http, false);
    $old = $saved;
    $callback = $url;
    if ($scenario === 'missing_secret') { unset($old['secret']); }
    if ($scenario === 'missing_record') { $old = null; }
    if ($scenario === 'stale_id') { $old['id'] = 'DELETED'; }
    if ($scenario === 'foreign_connection') {
        $old['connection_key'] = str_repeat('0', 64);
        $old['id'] = 'OTHER';
    }
    if ($scenario === 'deleted_remote') { unset($http->hooks['OLD']); }
    if ($scenario === 'moved_callback') { $callback = 'https://billing.example.test/new/modules/gateways/callback/btcpay.php'; }
    $replacement = $client->prepareWebhookReplacement($callback, $old);
    testSame($callback, $replacement['webhook']['url'], $scenario . ': uses current callback');
    if ($replacement['previous'] !== null) {
        testSame('OLD', $replacement['previous']['id'], $scenario . ': resolves only the WHMCS webhook');
        $client->deleteReplacedWebhook($replacement['previous'], $replacement['webhook']);
    }
    testSame(['OTHER', 'NEW-1'], array_keys($http->hooks), $scenario . ': leaves unrelated hooks alone');
}

foreach (['duplicate', 'changed_url', 'get_failure', 'create_failure', 'lost_response', 'missing_new_secret'] as $scenario) {
    $http = new WebhookFixtureHttp($url);
    $client = new Greenfield($settings, $http, false);
    if ($scenario === 'duplicate') { $http->hooks['EXTRA'] = ['id' => 'EXTRA', 'url' => $url, 'enabled' => true]; }
    if ($scenario === 'changed_url') { $http->hooks['OLD']['url'] = 'https://another.example.test/changed'; }
    if ($scenario === 'get_failure') { $http->getFailures['OLD'] = 403; }
    if ($scenario === 'create_failure') { $http->createStatus = 500; }
    if ($scenario === 'lost_response') { $http->loseCreateResponse = true; }
    if ($scenario === 'missing_new_secret') { $http->omitCreatedSecret = true; }
    testThrows(fn () => $client->prepareWebhookReplacement($url, $saved), $scenario . ': stops replacement');
    testSame(true, isset($http->hooks['OLD'], $http->hooks['OTHER']), $scenario . ': preserves existing webhooks');
    testSame(false, in_array('DELETE', array_column($http->requests, 'method'), true), $scenario . ': does not delete');
    if (in_array($scenario, ['duplicate', 'changed_url', 'get_failure'], true)) {
        testSame(0, $http->creations, $scenario . ': no creation on ambiguous ownership or read errors');
    }
}

foreach (['changed_old', 'disabled_new', 'missing_new', 'delete_failure', 'already_deleted', 'delete_race'] as $scenario) {
    $http = new WebhookFixtureHttp($url);
    $client = new Greenfield($settings, $http, false);
    $replacement = $client->prepareWebhookReplacement($url, $saved);
    if ($scenario === 'changed_old') { $http->hooks['OLD']['url'] = 'https://another.example.test/changed'; }
    if ($scenario === 'disabled_new') { $http->hooks['NEW-1']['enabled'] = false; }
    if ($scenario === 'missing_new') { unset($http->hooks['NEW-1']); }
    if ($scenario === 'delete_failure') { $http->deleteStatus = 500; }
    if ($scenario === 'already_deleted') { unset($http->hooks['OLD']); }
    if ($scenario === 'delete_race') { $http->deleteStatus = 404; }
    $cleanup = fn () => $client->deleteReplacedWebhook($replacement['previous'], $replacement['webhook']);
    if (in_array($scenario, ['already_deleted', 'delete_race'], true)) {
        $cleanup();
        testSame(false, isset($http->hooks['OLD']), $scenario . ': absence is harmless');
    } else {
        testThrows($cleanup, $scenario . ': cleanup fails closed');
        testSame(true, isset($http->hooks['OLD']), $scenario . ': preserves the previous webhook');
    }
    testSame(true, isset($http->hooks['OTHER']), $scenario . ': keeps other integration');
}

$CONFIG['SystemURL'] = 'https://billing.example.test/whmcs';
$session = [];
$nonce = bpCreateWebhookReregisterConfirmation($settings, $session);
$post = ['reregisterToken' => $nonce, 'confirmReregister' => 'yes'];
testThrows(fn () => bpConsumeWebhookReregisterConfirmation(['reregisterToken' => $nonce], $session), 'Requires explicit confirmation checkbox', 400);
testThrows(fn () => bpConsumeWebhookReregisterConfirmation(array_replace($post, ['reregisterToken' => 'wrong']), $session), 'Rejects a forged confirmation', 403);
testSame(bpWebhookStateFingerprint($settings), bpConsumeWebhookReregisterConfirmation($post, $session), 'Uses the server-side configuration snapshot');
testThrows(fn () => bpConsumeWebhookReregisterConfirmation($post, $session), 'Rejects replayed confirmation', 403);
$post['reregisterToken'] = bpCreateWebhookReregisterConfirmation($settings, $session);
$session['btcpay_webhook_reregister']['expires'] = time() - 1;
testThrows(fn () => bpConsumeWebhookReregisterConfirmation($post, $session), 'Rejects expired confirmation', 403);
foreach (['btcpayUrl', 'storeId', 'apiKey', 'webhookData', 'type'] as $field) {
    testSame(false, bpWebhookStateFingerprint($settings) === bpWebhookStateFingerprint(array_replace($settings, [$field => 'changed'])), 'Binds confirmation to ' . $field);
}
testSame(bpWebhookStateFingerprint($settings), bpWebhookStateFingerprint(array_replace($settings, ['webhookSetupStatus' => 'changed'])), 'Status-only updates do not change confirmation');
echo "Webhook re-registration, ownership, failure safety and single-use confirmation tests passed.\n";
