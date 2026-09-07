<?php

use BTCPayWHMCS\GatewayException;
use BTCPayWHMCS\Greenfield;
use WHMCS\Database\Capsule;
use WHMCS\User\Admin;

define('ADMINAREA', true);
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$admin = Admin::getAuthenticatedUser();
if (!$admin || !$admin->isAllowedToAuthenticate() || !$admin->hasPermission('Manage Payment Gateways')) {
    http_response_code(403);
    exit('Log in to WHMCS as an administrator with Manage Payment Gateways permission, then reopen this page.');
}
if (!isset($_SESSION['btcpay_manage_token']) || !is_string($_SESSION['btcpay_manage_token'])) {
    $_SESSION['btcpay_manage_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['btcpay_manage_token'];
$messages = [];
$setupStatus = '';
try {
    $setupStatus = bpReadGatewaySettings()['webhookSetupStatus'] ?? 'Save your gateway settings to register the webhook automatically.';
} catch (Throwable $exception) {
    $setupStatus = 'Unable to read webhook setup status.';
}
$nextAfter = null;
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}
if ($method === 'POST') {
    if (!is_string($_POST['token'] ?? null) || !hash_equals($token, $_POST['token'])) {
        http_response_code(403);
        exit('Invalid form token. Reload this page and try again.');
    }
    try {
        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['check', 'reconcile', 'setup', 'reregister'], true)) {
            throw new GatewayException('Unknown action.', 400);
        }
        if ($action === 'reregister') {
            $confirmedState = bpConsumeWebhookReregisterConfirmation($_POST, $_SESSION);
        }
        if ($action === 'setup' || $action === 'reregister') {
            try {
                if ($action === 'reregister') {
                    $setupStatus = bpReRegisterSavedWebhook($confirmedState);
                } else {
                    bpProvisionSavedWebhook();
                    $setupStatus = bpReadGatewaySettings()['webhookSetupStatus'];
                }
            } catch (Throwable $exception) {
                bpRecordWebhookSetupFailure($exception);
                $setupStatus = 'Webhook setup failed. Review the error below, correct the saved settings, and retry.';
                if ($action === 'reregister') {
                    $messages[] = 'Re-registration did not finish. A new webhook may already exist in BTCPay; inspect the store webhooks before retrying. The previous webhook is not deleted until the new secret is saved.';
                }
                throw $exception;
            }
        }
        $gateway = bpGetGatewaySettings();
        if (empty($gateway['type'])) {
            throw new GatewayException('Activate the BTCPay gateway first.', 503);
        }
        btcpayLoadDependencies();
        $client = new Greenfield($gateway);
        $client->checkConnection(true);
        $messages[] = 'Connection successful. The API key can create and view invoices in the selected store. Test a payment to verify webhook delivery.';
        if ($action === 'reconcile') {
            $after = $_POST['after'] ?? '0';
            if (!is_string($after) || !preg_match('/^\d{1,10}$/D', $after)) {
                throw new GatewayException('Invalid reconciliation position.', 400);
            }
            bpEnsureInvoiceContractTable();
            // Include expired/invalid rows: an administrator may since have
            // accepted a late payment. Each batch is deliberately bounded.
            $contracts = Capsule::table(BP_INVOICE_CONTRACT_TABLE)->whereNull('processed_at')
                ->where('id', '>', (int) $after)->orderBy('id')->limit(5)->get();
            foreach ($contracts as $contract) {
                try {
                    $result = bpSynchronizeInvoice($client, $contract->btcpay_invoice_id);
                    bpLogPaymentResult($gateway, $result);
                    $messages[] = 'WHMCS invoice #' . $contract->whmcs_invoice_id . ': ' . $result['outcome'] .
                        (!empty($result['manual_review']) ? ' — manual review required.' : '.');
                } catch (GatewayException $exception) {
                    $messages[] = 'WHMCS invoice #' . $contract->whmcs_invoice_id . ': ' . $exception->getMessage();
                } catch (Throwable $exception) {
                    $messages[] = 'WHMCS invoice #' . $contract->whmcs_invoice_id . ': reconciliation failed. Check the WHMCS transaction and database before retrying.';
                }
                $nextAfter = (int) $contract->id;
            }
            if (count($contracts) < 5) {
                $nextAfter = null;
                $messages[] = 'Reconciliation scan complete. Review any errors above; resolve them and start a new scan to retry.';
            }
        }
    } catch (GatewayException | InvalidArgumentException $exception) {
        $messages[] = $exception->getMessage();
    } catch (Throwable $exception) {
        $messages[] = 'Unable to check BTCPay. Upload the complete release ZIP, verify the required PHP extensions, and check database permissions.';
    }
}
$reregisterToken = '';
$reregisterWebhookId = 'Not available for this connection';
try {
    $reregisterSettings = bpReadGatewaySettings();
    if (!empty($reregisterSettings['type'])) {
        $reregisterUrl = bpBuildTrustedUrl(bpGetConfiguredWhmcsSystemUrl(), 'modules/gateways/callback/btcpay.php');
        $reregisterToken = bpCreateWebhookReregisterConfirmation($reregisterSettings, $_SESSION);
        try {
            $saved = bpSavedWebhook($reregisterSettings);
            $connection = hash('sha256', bpNormalizeConfiguredBaseUrl($reregisterSettings['btcpayUrl'] ?? null) . "\n" . trim($reregisterSettings['storeId'] ?? ''));
            if (($saved['connection_key'] ?? '') === $connection && bpIsValidInvoiceIdentifier($saved['id'] ?? null)) {
                $reregisterWebhookId = $saved['id'];
            }
        } catch (Throwable $exception) {
            // A damaged internal record must not hide the explicit recovery action.
        }
    }
} catch (Throwable $exception) {
    // Do not offer a destructive action without a saved configuration snapshot.
}
?>
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BTCPay Server connection and invoice recovery</title>
<style>body{font:16px/1.6 system-ui,sans-serif;max-width:760px;margin:3rem auto;padding:0 1rem}button{padding:.6rem 1rem;cursor:pointer}li{margin:.5rem 0}form{margin:1rem 0}</style>
<h1>BTCPay Server</h1>
<p>Save the server URL, store ID and Greenfield API key in WHMCS gateway settings. Saving registers a webhook with a BTCPay-generated secret.</p>
<p><?= bpEscapeHtmlAttribute($setupStatus) ?></p>
<ul>
<?php foreach ($messages as $message): ?>
    <li><?= bpEscapeHtmlAttribute($message) ?></li>
<?php endforeach; ?>
</ul>
<form method="post">
    <input type="hidden" name="token" value="<?= bpEscapeHtmlAttribute($token) ?>">
    <button name="action" value="check">Check connection</button>
</form>
<form method="post">
    <input type="hidden" name="token" value="<?= bpEscapeHtmlAttribute($token) ?>">
    <button name="action" value="setup">Retry webhook setup</button>
</form>
<p>Retry uses the saved credentials, reuses the registered webhook and preserves its secret. No API key or webhook secret is shown on this page.</p>
<?php if ($reregisterToken !== ''): ?>
<h2>Re-register webhook</h2>
<p>Use this to rotate the webhook secret or recover lost webhook settings. Normal settings saves and Retry webhook setup do not rotate secrets.</p>
<p>Server: <?= bpEscapeHtmlAttribute($reregisterSettings['btcpayUrl'] ?? '') ?><br>
Store: <?= bpEscapeHtmlAttribute($reregisterSettings['storeId'] ?? '') ?><br>
Callback: <?= bpEscapeHtmlAttribute($reregisterUrl) ?><br>
Saved webhook ID: <?= bpEscapeHtmlAttribute($reregisterWebhookId) ?></p>
<p>A replacement is created with a fresh BTCPay-generated secret and saved before the previous WHMCS webhook is deleted. Other callback URLs are left alone, except the saved WHMCS webhook if your installation URL moved. Multiple matching webhooks require manual review.</p>
<p>Deleting the previous webhook removes its delivery history. Notifications signed with its old secret are no longer accepted. After re-registering, reconcile saved invoices below and test a payment.</p>
<form method="post">
    <input type="hidden" name="token" value="<?= bpEscapeHtmlAttribute($token) ?>">
    <input type="hidden" name="reregisterToken" value="<?= bpEscapeHtmlAttribute($reregisterToken) ?>">
    <label><input type="checkbox" name="confirmReregister" value="yes" required> I confirm replacement of this WHMCS webhook and its secret.</label><br>
    <button name="action" value="reregister">Re-register webhook</button>
</form>
<?php endif; ?>
<h2>Recover missed payments</h2>
<p>Reconciliation checks saved invoices against BTCPay and applies settled payments to WHMCS. It uses the same checks as payment notifications and does not credit a payment twice. Invoices without a saved mapping need manual reconciliation.</p>
<form method="post">
    <input type="hidden" name="token" value="<?= bpEscapeHtmlAttribute($token) ?>">
    <input type="hidden" name="after" value="<?= $nextAfter ?? 0 ?>">
    <button name="action" value="reconcile"><?= $nextAfter === null ? 'Reconcile saved invoices' : 'Continue reconciliation' ?></button>
</form>
<p>When the scan is complete, return to WHMCS. Use the Gateway Log to review payment results. Reconcile again after finishing the upgrade to catch payments received during the switch.</p>
</html>
