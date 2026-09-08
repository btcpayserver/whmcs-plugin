<?php

use BTCPayWHMCS\GatewayException;
use BTCPayWHMCS\Greenfield;
use WHMCS\Database\Capsule;

// This standalone module endpoint is outside WHMCS's configured admin directory.
// ADMINAREA would make init.php reject that path before our authorization runs.
// Bootstrap normally, then require an authenticated, authorized admin below.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/bootstrap.php';

$asJson = ($_POST['responseFormat'] ?? null) === 'json';
header('Content-Type: ' . ($asJson ? 'application/json' : 'text/html') . '; charset=UTF-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$sendJson = function (bool $success, string $message, int $status = 200): void {
    http_response_code($status);
    // Never serialize settings, SDK results, credentials or webhook secrets.
    echo json_encode(['success' => $success, 'message' => $message], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$reject = function (int $status, string $message) use ($asJson, $sendJson): void {
    if ($asJson) {
        $sendJson(false, $message, $status);
    }
    http_response_code($status);
    exit(bpEscapeHtmlAttribute($message));
};
if (!bpCanManageGatewaySettings()) {
    $reject(403, 'Log in to WHMCS as an administrator with Configure Payment Gateways permission, then reopen this page.');
}
$token = bpManagementCsrfToken();
$messages = [];
$setupStatus = '';
try {
    $setupStatus = bpReadGatewaySettings()['webhookSetupStatus'] ?? 'Save your gateway settings first, then click Set up / repair webhook.';
} catch (Throwable $exception) {
    $setupStatus = 'Unable to read webhook setup status.';
}
$nextAfter = null;
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    $reject(405, 'Method not allowed.');
}
if ($method === 'POST') {
    if (!is_string($_POST['token'] ?? null) || !hash_equals($token, $_POST['token'])) {
        $reject(403, 'Invalid form token. Reload this page and try again.');
    }
    try {
        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['check', 'reconcile', 'setup', 'reregister'], true)) {
            throw new GatewayException('Unknown action.', 400);
        }
        // The inline button has one purpose. It cannot rotate secrets, credit
        // invoices, or bypass the separate re-registration confirmation form.
        if ($asJson && $action !== 'setup') {
            throw new GatewayException('Only explicit webhook setup is available through this endpoint format.', 400);
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
            if ($asJson) {
                $sendJson(true, $setupStatus);
            }
        }
        if ($action === 'check' || $action === 'reconcile') {
            // Connection checks must work before first setup or after editing the
            // connection. A missing/stale managed or manual secret is irrelevant.
            $gateway = $action === 'check' ? bpReadGatewaySettings() : bpGetGatewaySettings();
            if (empty($gateway['type'])) {
                throw new GatewayException('Activate the BTCPay gateway first.', 503);
            }
            btcpayLoadDependencies();
            if ($action === 'check') {
                $gateway['webhookSecret'] = '';
            }
            $client = new Greenfield($gateway, null, $action !== 'check');
            $client->checkConnection(true);
            $messages[] = 'Connection successful. The API key has the required invoice and webhook permissions in the selected store. This check does not register or verify the webhook; use Set up / repair webhook, then test a payment.';
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
        }
    } catch (GatewayException $exception) {
        if ($asJson) {
            $sendJson(false, $exception->getMessage(), $exception->httpStatus);
        }
        $messages[] = $exception->getMessage();
    } catch (Throwable $exception) {
        if ($asJson) {
            $sendJson(false, 'Unable to finish webhook setup. Upload the complete release ZIP, verify the required PHP extensions, and check database permissions. Review the connection page before retrying.', 503);
        }
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
<style>body{font:16px/1.6 system-ui,sans-serif;max-width:760px;margin:3rem auto;padding:0 1rem}button{padding:.6rem 1rem;cursor:pointer}li{margin:.5rem 0}form{margin:1rem 0}.text-success{color:#3c763d;font-weight:600}</style>
<h1>BTCPay Server</h1>
<p>Save the server URL, store ID and Greenfield API key in WHMCS gateway settings and wait for WHMCS to confirm success. Then click <strong>Set up / repair webhook</strong> below. Saving settings alone does not register a webhook.</p>
<p class="<?= str_starts_with($setupStatus, 'Webhook ready.') ? 'text-success' : '' ?>" role="status"><?= bpEscapeHtmlAttribute($setupStatus) ?></p>
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
    <button name="action" value="setup">Set up / repair webhook</button>
</form>
<p>Setup uses saved credentials only. It registers a webhook with a BTCPay-generated secret, or repairs the existing webhook while preserving its secret. No API key or webhook secret is shown on this page.</p>
<?php if ($reregisterToken !== ''): ?>
<h2>Re-register webhook</h2>
<p>Use this to rotate the webhook secret or recover lost webhook settings. Set up / repair webhook preserves a working secret; ordinary settings saves do not contact BTCPay.</p>
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
