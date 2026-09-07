<?php

// Included by integration_test.php after its concurrent payment workers finish.
class IntegrationGatewaySetting extends Illuminate\Database\Eloquent\Model
{
    protected $table = 'tblpaymentgateways';
    protected $guarded = [];
    public $timestamps = false;
    // Substitute WHMCS's proprietary encryption with a test-only accessor. This
    // verifies production code uses the model, NOT WHMCS's encryption algorithm.
    public function setValueAttribute($value): void { $this->attributes['value'] = 'test-encrypted:' . base64_encode($value); }
    public function getValueAttribute($value): string { return base64_decode(substr($value, 15)); }
}
class_alias(IntegrationGatewaySetting::class, 'WHMCS\Module\GatewaySetting');
$capsule->bootEloquent();
$CONFIG['SystemURL'] = 'https://billing.example.test/whmcs';
$tables[] = 'tblpaymentgateways';
Illuminate\Database\Capsule\Manager::schema()->create('tblpaymentgateways', function ($table) {
    $table->increments('id'); $table->string('gateway'); $table->string('setting'); $table->text('value');
    $table->unique(['gateway', 'setting']);
});
foreach (array_replace(testSettings(), ['type' => 'invoices', 'webhookSecret' => '']) as $key => $value) {
    bpWriteGatewaySetting($key, $value);
}
$hookUrl = 'https://billing.example.test/whmcs/modules/gateways/callback/btcpay.php';
$remoteWebhook = null;
$setupHttp = new FixtureHttp(function ($method, $url, $body) use (&$remoteWebhook, $hookUrl) {
    testSame(true, Illuminate\Database\Capsule\Manager::connection()->transactionLevel() > 0, 'Webhook setup runs under a database lock');
    if (str_ends_with($url, '/api-keys/current')) {
        return ['permissions' => ['btcpay.store.cancreateinvoice:STORE-1', 'btcpay.store.canviewinvoices:STORE-1',
            BTCPayWHMCS\Greenfield::WEBHOOK_PERMISSION . ':STORE-1']];
    }
    if (str_contains($url, '/invoices?')) { return []; }
    if ($method === 'POST') {
        $remoteWebhook = ['id' => 'HOOK-1', 'url' => $hookUrl];
        return $remoteWebhook + ['secret' => 'btcpay-generated-test-secret'];
    }
    if ($method === 'PUT') { return $remoteWebhook; }
    return str_ends_with($url, '/webhooks') ? ($remoteWebhook ? [$remoteWebhook] : []) : $remoteWebhook;
});
$factory = fn ($settings) => new BTCPayWHMCS\Greenfield($settings, $setupHttp, false);
bpProvisionSavedWebhook($factory);
$configured = bpGetGatewaySettings();
testSame('btcpay-generated-test-secret', $configured['webhookSecret'], 'Payment paths use the generated secret');
$rawStored = Illuminate\Database\Capsule\Manager::table('tblpaymentgateways')->where('setting', 'webhookData')->value('value');
testSame(true, str_starts_with($rawStored, 'test-encrypted:'), 'Persists through the gateway model encryption accessor');
testSame(false, str_contains($rawStored, 'btcpay-generated-test-secret'), 'Does not persist the secret in plaintext');
testSame('', bpReadGatewaySettings()['webhookSecret'], 'Generated secret is not a user-editable configuration field');
bpWriteGatewaySetting('webhookSecret', 'stale-form-secret');
bpProvisionSavedWebhook($factory);
testSame('btcpay-generated-test-secret', bpGetGatewaySettings()['webhookSecret'], 'Stale forms cannot overwrite the managed secret');
testSame(1, count(array_filter($setupHttp->requests, fn ($request) => $request['method'] === 'POST')), 'Repeat saves create only one webhook');
$beforeFailure = bpReadGatewaySettings()['webhookData'];
testThrows(fn () => bpProvisionSavedWebhook(fn ($settings) => new BTCPayWHMCS\Greenfield($settings,
    new FixtureHttp(fn () => new BTCPayServer\Http\Response(403, 'secret-error-response', [])), false)), 'Setup failures propagate safely', 502);
testSame($beforeFailure, bpReadGatewaySettings()['webhookData'], 'Failure preserves the existing webhook and secret');
IntegrationGatewaySetting::setEventDispatcher(new Illuminate\Events\Dispatcher());
IntegrationGatewaySetting::saving(fn ($setting) => $setting->setting === 'webhookData' ? false : null);
testThrows(fn () => bpProvisionSavedWebhook($factory), 'Does not report success when model persistence is cancelled', 503);
testSame($beforeFailure, bpReadGatewaySettings()['webhookData'], 'Failed persistence does not replace the saved secret');
IntegrationGatewaySetting::flushEventListeners();
require __DIR__ . '/webhook_reregister_integration.php';
bpWriteGatewaySetting('storeId', 'STORE-2');
testThrows(fn () => bpGetGatewaySettings(), 'Changed store cannot use the previous webhook secret', 503);
echo "MySQL webhook setup persistence, repeat saves and failure recovery tests passed.\n";
