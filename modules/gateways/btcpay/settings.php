<?php

use BTCPayWHMCS\GatewayException;
use BTCPayWHMCS\Greenfield;
use WHMCS\Database\Capsule;
use WHMCS\Module\GatewaySetting;

/** Read through WHMCS's model so encrypted gateway values are decrypted. */
function bpReadGatewaySettings(): array
{
    $settings = [];
    foreach (GatewaySetting::where('gateway', 'btcpay')->get() as $setting) {
        $settings[$setting->setting] = $setting->value;
    }
    return $settings;
}

function bpSavedWebhook(array $settings): ?array
{
    if (empty($settings['webhookData'])) {
        return null;
    }
    try {
        $data = json_decode($settings['webhookData'], true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        throw new GatewayException('Saved webhook configuration is invalid. Review the BTCPay connection settings.', 503);
    }
    if (!is_array($data)) {
        throw new GatewayException('Saved webhook configuration is invalid. Review the BTCPay connection settings.', 503);
    }
    return $data;
}

/** The generated secret is internal: a stale settings form cannot overwrite it. */
function bpGetGatewaySettings(): array
{
    $settings = bpReadGatewaySettings();
    $saved = bpSavedWebhook($settings);
    if ($saved !== null) {
        $connection = hash('sha256', bpNormalizeConfiguredBaseUrl($settings['btcpayUrl'] ?? null) . "\n" . trim($settings['storeId'] ?? ''));
        if (($saved['connection_key'] ?? '') !== $connection) {
            throw new GatewayException('Webhook setup does not match the saved server and store. Save the settings again or retry webhook setup.', 503);
        }
        $settings['webhookSecret'] = $saved['secret'] ?? '';
    }
    return $settings;
}

function bpWriteGatewaySetting(string $name, string $value): void
{
    $setting = GatewaySetting::where('gateway', 'btcpay')->where('setting', $name)->first();
    if (!$setting) {
        $setting = new GatewaySetting();
        $setting->gateway = 'btcpay';
        $setting->setting = $name;
    }
    // Do not use a raw table update or encrypt() here: WHMCS's model handles
    // its own gateway-specific encryption, including secrets in webhookData.
    $setting->value = $value;
    if (!$setting->save()) {
        throw new GatewayException('Unable to save webhook configuration in WHMCS.', 503);
    }
}

/** Call inside a transaction to serialize setup and replacement attempts. */
function bpLockedGatewaySettings(): array
{
    $gateway = GatewaySetting::where('gateway', 'btcpay')->where('setting', 'type')->lockForUpdate()->first();
    if (!$gateway || !$gateway->value) {
        throw new GatewayException('Activate the BTCPay gateway first.', 503);
    }
    return bpReadGatewaySettings();
}

/** Called only after an authorized settings save or an explicit admin retry. */
function bpProvisionSavedWebhook(?callable $clientFactory = null): void
{
    Capsule::connection()->transaction(function () use ($clientFactory) {
        $settings = bpLockedGatewaySettings();
        $client = $clientFactory ? $clientFactory($settings) : new Greenfield($settings, null, false);
        $client->checkConnection(true);
        $url = bpBuildTrustedUrl(bpGetConfiguredWhmcsSystemUrl(), 'modules/gateways/callback/btcpay.php');
        $webhook = $client->provisionWebhook($url, bpSavedWebhook($settings));
        bpWriteGatewaySetting('webhookData', json_encode($webhook, JSON_THROW_ON_ERROR));
        bpWriteGatewaySetting('webhookSetupStatus', 'Webhook ready. Its secret is stored securely; automatic redelivery is enabled.');
    });
}

/** Bind a destructive confirmation to the configuration the administrator saw. */
function bpWebhookStateFingerprint(array $settings): string
{
    $state = array_intersect_key($settings, array_flip(['type', 'btcpayUrl', 'storeId', 'apiKey', 'webhookData']));
    $state['callbackUrl'] = bpBuildTrustedUrl(bpGetConfiguredWhmcsSystemUrl(), 'modules/gateways/callback/btcpay.php');
    ksort($state);
    return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
}

function bpCreateWebhookReregisterConfirmation(array $settings, array &$session): string
{
    $token = bin2hex(random_bytes(32));
    $session['btcpay_webhook_reregister'] = ['token' => $token,
        'state' => bpWebhookStateFingerprint($settings), 'expires' => time() + 900];
    return $token;
}

/** The management endpoint must check admin authorization and its CSRF token first. */
function bpConsumeWebhookReregisterConfirmation(array $post, array &$session): string
{
    if (($post['confirmReregister'] ?? null) !== 'yes') {
        throw new GatewayException('Confirm webhook replacement using the checkbox before continuing.', 400);
    }
    $confirmation = $session['btcpay_webhook_reregister'] ?? null;
    if (!is_array($confirmation) || !is_string($post['reregisterToken'] ?? null) ||
        !is_string($confirmation['token'] ?? null) || !is_string($confirmation['state'] ?? null) ||
        !is_int($confirmation['expires'] ?? null) || $confirmation['expires'] <= time() ||
        !hash_equals($confirmation['token'], $post['reregisterToken'])) {
        throw new GatewayException('Webhook replacement confirmation expired or was already used. Reload this page and confirm again.', 403);
    }
    // Consume before remote work, including failures. Refresh/double clicks cannot rotate again.
    unset($session['btcpay_webhook_reregister']);
    return $confirmation['state'];
}

/** Explicit, confirmed admin action. Never called by the settings-save observer. */
function bpReRegisterSavedWebhook(string $expectedState, ?callable $clientFactory = null): string
{
    if (Capsule::connection()->transactionLevel() !== 0) {
        throw new GatewayException('Webhook replacement must run outside an existing transaction.', 503);
    }
    $prepared = Capsule::connection()->transaction(function () use ($expectedState, $clientFactory) {
        $settings = bpLockedGatewaySettings();
        if (!hash_equals(bpWebhookStateFingerprint($settings), $expectedState)) {
            throw new GatewayException('Gateway settings or webhook changed since confirmation. Reload this page and review them before re-registering.', 409);
        }
        // Neither a missing managed secret nor a stale manual field is needed to reset.
        $settings['webhookSecret'] = '';
        $client = $clientFactory ? $clientFactory($settings) : new Greenfield($settings, null, false);
        $client->checkConnection(true);
        $url = bpBuildTrustedUrl(bpGetConfiguredWhmcsSystemUrl(), 'modules/gateways/callback/btcpay.php');
        try {
            $saved = bpSavedWebhook($settings);
        } catch (GatewayException $exception) {
            // Explicit recovery can identify a single exact callback URL even
            // when the internal record is damaged. Ordinary saves still fail closed.
            $saved = null;
        }
        $prepared = $client->prepareWebhookReplacement($url, $saved);
        $status = $prepared['previous'] === null ? 'Webhook ready with a new BTCPay-generated secret.'
            : 'New webhook configuration saved. Cleanup of previous webhook ' . $prepared['previous']['id'] .
                ' is not confirmed. Inspect that webhook in BTCPay and remove only it if still present. Reconcile saved invoices.';
        bpWriteGatewaySetting('webhookData', json_encode($prepared['webhook'], JSON_THROW_ON_ERROR));
        bpWriteGatewaySetting('webhookSetupStatus', $status);
        return $prepared + ['client' => $client, 'status' => $status];
    });

    // Remote creation cannot be rolled back with MySQL. Never delete the previous
    // webhook until the replacement's secret has actually committed above.
    try {
        return Capsule::connection()->transaction(function () use ($prepared) {
            $current = bpSavedWebhook(bpLockedGatewaySettings());
            if ($current !== $prepared['webhook']) {
                throw new GatewayException('Webhook changed before cleanup. Review the previous webhook manually.', 409);
            }
            if ($prepared['previous'] !== null) {
                $prepared['client']->deleteReplacedWebhook($prepared['previous'], $prepared['webhook']);
            }
            $status = 'Webhook ready. Re-registration completed with a new BTCPay-generated secret. Reconcile saved invoices to recover any missed notifications.';
            bpWriteGatewaySetting('webhookSetupStatus', $status);
            return $status;
        });
    } catch (Throwable $exception) {
        // Keep the committed replacement usable, even if cleanup or its status
        // update fails. The status stored before cleanup already identifies the old ID.
        error_log('BTCPay webhook re-registration: cleanup or status update could not be confirmed. Inspect the previous webhook and reconcile saved invoices.');
        return $prepared['status'];
    }
}

function bpRecordWebhookSetupFailure(Throwable $exception): void
{
    $message = $exception instanceof GatewayException ? $exception->getMessage()
        : 'Webhook setup failed. Check the complete release upload, saved connection settings and database permissions.';
    try {
        bpWriteGatewaySetting('webhookSetupStatus', $message . ' Open connection and recovery to retry.');
    } catch (Throwable $storageError) {
        // Never leak encryption material or SQL bindings from a storage exception.
    }
    error_log('BTCPay webhook setup: ' . $message);
}

/** WHMCS has no documented GatewayConfigSave hook. Observe actual model saves. */
function bpObserveGatewaySettingsSaves(callable $defer): void
{
    if (!GatewaySetting::getEventDispatcher()) {
        throw new RuntimeException('Gateway settings model events are unavailable.');
    }
    $scheduled = false;
    GatewaySetting::saved(function ($setting) use (&$scheduled, $defer) {
        if ($scheduled || $setting->gateway !== 'btcpay' ||
            in_array($setting->setting, ['webhookData', 'webhookSetupStatus'], true) ||
            !defined('ADMINAREA') || !ADMINAREA || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
            basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'configgateways.php') {
            return;
        }
        try {
            $admin = \WHMCS\User\Admin::getAuthenticatedUser();
            $authorized = $admin && $admin->isAllowedToAuthenticate() && $admin->hasPermission('Manage Payment Gateways');
        } catch (Throwable $exception) {
            $authorized = false;
        }
        if (!$authorized) {
            return;
        }
        $scheduled = true;
        // Wait for ALL fields to be saved, including when WHMCS redirects/exits.
        // Never trust posted/masked credentials or write during a config-page GET.
        $defer(function () {
            $lastError = error_get_last();
            if (($lastError && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) ||
                http_response_code() >= 400) {
                return;
            }
            try {
                if (Capsule::connection()->transactionLevel() !== 0) {
                    return;
                }
                bpProvisionSavedWebhook();
            } catch (Throwable $exception) {
                bpRecordWebhookSetupFailure($exception);
            }
        });
    });
}
