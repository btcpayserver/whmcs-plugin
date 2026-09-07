<?php

// Included with the disposable MySQL database and WHMCS model substitute.
require_once __DIR__ . '/webhook_fixture.php';

use BTCPayWHMCS\GatewayException;
use BTCPayWHMCS\Greenfield;
use Illuminate\Database\Capsule\Manager as Capsule;

$originalWebhookData = bpReadGatewaySettings()['webhookData'];
$originalWebhook = bpSavedWebhook(bpReadGatewaySettings());
$capsule->addConnection(Capsule::connection()->getConfig(), 'webhook_commit_observer');
$committedWebhook = function () {
    $raw = Capsule::connection('webhook_commit_observer')->table('tblpaymentgateways')
        ->where('gateway', 'btcpay')->where('setting', 'webhookData')->value('value');
    return json_decode(base64_decode(substr($raw, 15)), true, 16, JSON_THROW_ON_ERROR);
};
$replacementHttp = new WebhookFixtureHttp($hookUrl, $originalWebhook['id']);
$replacementHttp->beforeDelete = function ($id) use ($committedWebhook, $originalWebhook) {
    testSame($originalWebhook['id'], $id, 'Deletes only the previous managed webhook');
    $committed = $committedWebhook();
    testSame('NEW-1', $committed['id'], 'A separate DB connection sees the replacement before deletion');
    testSame('generated-secret-1', $committed['secret'], 'The new secret is committed before deletion');
};
$replacementFactory = fn ($settings) => new Greenfield($settings, $replacementHttp, false);
$confirmed = bpWebhookStateFingerprint(bpReadGatewaySettings());
$status = bpReRegisterSavedWebhook($confirmed, $replacementFactory);
testSame(true, str_contains($status, 'Re-registration completed'), 'Reports completed replacement');
testSame('generated-secret-1', bpGetGatewaySettings()['webhookSecret'], 'Payment paths use the replacement secret');
testSame(false, isset($replacementHttp->hooks[$originalWebhook['id']]), 'Old webhook is removed after persistence');
testSame(true, isset($replacementHttp->hooks['NEW-1'], $replacementHttp->hooks['OTHER']), 'Keeps new webhook and other integrations');
$requestCount = count($replacementHttp->requests);
testThrows(fn () => bpReRegisterSavedWebhook($confirmed, $replacementFactory), 'Rejects stale or concurrent confirmation', 409);
testSame($requestCount, count($replacementHttp->requests), 'Stale confirmation cannot make remote calls');
bpProvisionSavedWebhook($replacementFactory);
testSame(1, $replacementHttp->creations, 'Normal save after replacement does not rotate again');
testSame('generated-secret-1', bpGetGatewaySettings()['webhookSecret'], 'Normal save retains the rotated secret');
testThrows(fn () => Capsule::connection()->transaction(fn () => bpReRegisterSavedWebhook(
    bpWebhookStateFingerprint(bpReadGatewaySettings()), $replacementFactory)), 'Requires its own commit boundary', 503);

foreach (['missing_secret', 'damaged_record', 'missing_record', 'stale_id'] as $scenario) {
    $local = $originalWebhook;
    if ($scenario === 'missing_secret') { unset($local['secret']); }
    if ($scenario === 'stale_id') { $local['id'] = 'DELETED'; }
    $serialized = json_encode($local, JSON_THROW_ON_ERROR);
    if ($scenario === 'damaged_record') { $serialized = '{broken'; }
    if ($scenario === 'missing_record') { $serialized = ''; }
    bpWriteGatewaySetting('webhookData', $serialized);
    // An invalid old manual field is irrelevant to an explicitly confirmed reset.
    bpWriteGatewaySetting('webhookSecret', str_repeat('x', 1100));
    $replacementHttp = new WebhookFixtureHttp($hookUrl, $originalWebhook['id']);
    $replacementFactory = fn ($settings) => new Greenfield($settings, $replacementHttp, false);
    bpReRegisterSavedWebhook(bpWebhookStateFingerprint(bpReadGatewaySettings()), $replacementFactory);
    testSame('generated-secret-1', bpGetGatewaySettings()['webhookSecret'], $scenario . ': recovers without the old secret');
    testSame(false, isset($replacementHttp->hooks[$originalWebhook['id']]), $scenario . ': removes only the matched old callback');
}
bpWriteGatewaySetting('webhookSecret', '');

foreach (['creation_failure', 'lost_response', 'permission_failure', 'webhook_save_failure', 'status_save_failure'] as $scenario) {
    bpWriteGatewaySetting('webhookData', $originalWebhookData);
    $replacementHttp = new WebhookFixtureHttp($hookUrl, $originalWebhook['id']);
    $replacementFactory = fn ($settings) => new Greenfield($settings, $replacementHttp, false);
    if ($scenario === 'creation_failure') { $replacementHttp->createStatus = 500; }
    if ($scenario === 'lost_response') { $replacementHttp->loseCreateResponse = true; }
    if ($scenario === 'permission_failure') { $replacementHttp->permissions = ['btcpay.store.canviewinvoices:STORE-1']; }
    if (in_array($scenario, ['webhook_save_failure', 'status_save_failure'], true)) {
        $blockedField = $scenario === 'webhook_save_failure' ? 'webhookData' : 'webhookSetupStatus';
        IntegrationGatewaySetting::saving(fn ($setting) => $setting->setting === $blockedField ? false : null);
    }
    try {
        testThrows(fn () => bpReRegisterSavedWebhook(bpWebhookStateFingerprint(bpReadGatewaySettings()), $replacementFactory), $scenario . ': surfaces failure');
        testSame($originalWebhookData, bpReadGatewaySettings()['webhookData'], $scenario . ': local state rolls back');
        testSame($originalWebhook, $committedWebhook(), $scenario . ': old state remains committed');
        testSame(true, isset($replacementHttp->hooks[$originalWebhook['id']], $replacementHttp->hooks['OTHER']), $scenario . ': preserves existing remote webhooks');
        testSame(false, in_array('DELETE', array_column($replacementHttp->requests, 'method'), true), $scenario . ': never deletes before persistence');
    } finally {
        IntegrationGatewaySetting::flushEventListeners();
    }
}

$replacementHttp = new WebhookFixtureHttp($hookUrl, $originalWebhook['id']);
$replacementHttp->deleteStatus = 500;
$replacementFactory = fn ($settings) => new Greenfield($settings, $replacementHttp, false);
$status = bpReRegisterSavedWebhook(bpWebhookStateFingerprint(bpReadGatewaySettings()), $replacementFactory);
testSame(true, str_contains($status, 'Cleanup of previous webhook ' . $originalWebhook['id']), 'Cleanup failure identifies only the previous webhook for manual removal');
testSame('generated-secret-1', bpGetGatewaySettings()['webhookSecret'], 'Cleanup failure never rolls back the usable new secret');
testSame(true, isset($replacementHttp->hooks[$originalWebhook['id']], $replacementHttp->hooks['NEW-1']), 'Cleanup failure preserves both webhooks for inspection');
testSame($status, bpReadGatewaySettings()['webhookSetupStatus'], 'Cleanup warning survives the request');

if (function_exists('pcntl_fork')) {
    bpWriteGatewaySetting('webhookData', $originalWebhookData);
    $confirmed = bpWebhookStateFingerprint(bpReadGatewaySettings());
    Capsule::connection()->disconnect();
    Capsule::connection('webhook_commit_observer')->disconnect();
    $children = [];
    for ($worker = 0; $worker < 2; $worker++) {
        $pid = pcntl_fork();
        if ($pid === -1) { throw new RuntimeException('Unable to fork replacement concurrency test'); }
        if ($pid === 0) {
            $parallelHttp = new WebhookFixtureHttp($hookUrl, $originalWebhook['id']);
            try {
                bpReRegisterSavedWebhook($confirmed, fn ($settings) => new Greenfield($settings, $parallelHttp, false));
                testSame(1, $parallelHttp->creations, 'Winner creates exactly one replacement');
                exit(0);
            } catch (GatewayException $exception) {
                if ($exception->httpStatus === 409 && $parallelHttp->creations === 0) { exit(10); }
                exit(1);
            } catch (Throwable $exception) {
                exit(1);
            }
        }
        $children[] = $pid;
    }
    $outcomes = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $childStatus);
        $outcomes[] = pcntl_wexitstatus($childStatus);
    }
    sort($outcomes);
    testSame([0, 10], $outcomes, 'Concurrent confirmations rotate once and reject the stale worker');
}
Capsule::connection('webhook_commit_observer')->disconnect();
echo "MySQL webhook replacement commit ordering, recovery, failure and concurrency tests passed.\n";
