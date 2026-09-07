<?php

require __DIR__ . '/support.php';

class SaveEventGatewaySetting extends Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];
    public function emitSaved(): void { $this->fireModelEvent('saved', false); }
}
class SaveEventAdmin
{
    public static bool $loggedIn = true;
    public static bool $allowed = true;
    public static bool $permission = true;
    public static function getAuthenticatedUser() { return self::$loggedIn ? new self() : null; }
    public function isAllowedToAuthenticate(): bool { return self::$allowed; }
    public function hasPermission(string $name): bool
    {
        testSame('Manage Payment Gateways', $name, 'Requires the specific gateway administration permission');
        return self::$permission;
    }
}
class_alias(SaveEventGatewaySetting::class, 'WHMCS\Module\GatewaySetting');
class_alias(SaveEventAdmin::class, 'WHMCS\User\Admin');
SaveEventGatewaySetting::setEventDispatcher(new Illuminate\Events\Dispatcher());
define('ADMINAREA', true);
$deferred = [];
bpObserveGatewaySettingsSaves(function ($callback) use (&$deferred) { $deferred[] = $callback; });
$row = new SaveEventGatewaySetting(['gateway' => 'btcpay', 'setting' => 'apiKey']);
$_SERVER = ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/custom-admin/configgateways.php'];
$row->emitSaved();
testSame([], $deferred, 'Does not provision during GET requests');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/modules/gateways/btcpay/manage.php';
$row->emitSaved();
testSame([], $deferred, 'Does not observe unrelated endpoints');
$_SERVER['SCRIPT_NAME'] = '/custom-admin/configgateways.php';
SaveEventAdmin::$loggedIn = false;
$row->emitSaved();
testSame([], $deferred, 'Requires authenticated administrator');
SaveEventAdmin::$loggedIn = true;
SaveEventAdmin::$allowed = false;
$row->emitSaved();
testSame([], $deferred, 'Rejects disabled administrator');
SaveEventAdmin::$allowed = true;
SaveEventAdmin::$permission = false;
$row->emitSaved();
testSame([], $deferred, 'Requires gateway management permission');
SaveEventAdmin::$permission = true;
$row->gateway = 'other';
$row->emitSaved();
testSame([], $deferred, 'Does not provision for another gateway');
$row->gateway = 'btcpay';
foreach (['webhookData', 'webhookSetupStatus'] as $internal) {
    $row->setting = $internal;
    $row->emitSaved();
}
testSame([], $deferred, 'Internal writes cannot recursively trigger provisioning');
foreach (['apiKey', 'storeId', 'btcpayUrl', 'transactionSpeed'] as $public) {
    $row->setting = $public;
    $row->emitSaved();
}
testSame(1, count($deferred), 'Queues once after all settings are persisted, including redirected requests');
testSame(true, is_callable($deferred[0]), 'Defers remote work until request completion');
echo "Gateway settings-save observer authorization and batching tests passed.\n";
