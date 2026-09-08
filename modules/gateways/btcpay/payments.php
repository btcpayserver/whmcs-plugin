<?php

use BTCPayWHMCS\GatewayException;
use BTCPayWHMCS\Greenfield;
use WHMCS\Database\Capsule;

/** The same payment policy is used for webhooks, checkout reuse and reconciliation. */
function bpPaymentDecision(array $data, $contract, $invoice): array
{
    $contract = (array) $contract;
    $error = bpValidateBtcpayInvoiceResponseData($data) ?? bpValidateBtcpayInvoiceContract($data, $contract);
    if ($error !== null) {
        throw new GatewayException($error, 409);
    }
    $status = strtolower($data['status']);
    // A missing additional status is not equivalent to BTCPay explicitly
    // reporting None. Require a supported combination before crediting.
    $additional = strtolower($data['additionalStatus'] ?? '');
    $processed = ($contract['processed_at'] ?? null) !== null;
    $result = ['status' => $status, 'additional_status' => $additional, 'credit' => false, 'manual_review' => false];
    if (in_array($status, ['expired', 'invalid'], true)) {
        $result['manual_review'] = ($processed && ($contract['status'] ?? '') !== $status) ||
            in_array($additional, ['paidpartial', 'paidlate', 'paidover'], true);
        return $result + ['outcome' => 'terminal_status_recorded'];
    }
    if ($processed) {
        if (in_array($status, ['new', 'processing'], true)) {
            $result['status'] = $contract['status'];
        }
        return $result + ['outcome' => 'duplicate_callback'];
    }
    if ($status !== 'settled') {
        return $result + ['outcome' => $status === 'new' ? 'awaiting_payment' : 'awaiting_confirmation'];
    }
    // Do not use Invoice::isSettled(): that also returns true for PaidLate.
    if ($additional === 'paidpartial') {
        throw new GatewayException('Partially paid invoice requires manual review.', 409);
    }
    if (!in_array($additional, ['none', 'paidover', 'marked', 'paidlate'], true)) {
        throw new GatewayException('Settled invoice has an unsupported or missing additional status. Review the payment manually.', 409);
    }
    $invoice = (array) $invoice;
    $error = bpValidateWhmcsInvoiceContract($invoice, $contract);
    if ($error !== null || ($invoice['status'] ?? '') !== 'Unpaid' || ($invoice['paymentmethod'] ?? '') !== 'btcpay') {
        throw new GatewayException($error ?? 'WHMCS invoice is no longer unpaid through BTCPay. Review the payment manually.', 409);
    }
    $result['credit'] = true;
    return $result + ['outcome' => 'payment_applied'];
}

function bpValidateInvoiceConnection($contract, Greenfield $client): void
{
    $contract = (array) $contract;
    if (!empty($contract['connection_key']) && !hash_equals($contract['connection_key'], $client->connectionKey())) {
        throw new GatewayException('Invoice belongs to a different BTCPay connection. Restore its original server and store to reconcile it.', 409);
    }
}

/** Caller holds the WHMCS invoice row and contract row locks. */
function bpSynchronizeLockedInvoice(Greenfield $client, $contract): array
{
    bpValidateInvoiceConnection($contract, $client);
    // Fetch AFTER acquiring locks; concurrent callbacks cannot write stale snapshots.
    $data = $client->getInvoice($contract->btcpay_invoice_id);
    $invoice = bpGetWhmcsInvoiceForContract($contract->whmcs_invoice_id, true);
    $decision = bpPaymentDecision($data, $contract, $invoice);

    if ($decision['credit']) {
        // checkCbTransID() exits the request on duplicates, bypassing normal
        // transaction cleanup. Check the same ledger explicitly under our locks.
        if (Capsule::table('tblaccounts')->where('transid', $data['id'])->exists()) {
            throw new GatewayException('Transaction already exists in WHMCS. Reconcile its saved invoice mapping manually.', 409);
        }
        addInvoicePayment((int) $contract->whmcs_invoice_id, $data['id'],
            bpNormalizeDecimal($contract->whmcs_amount), 0.00, 'btcpay');
    }
    bpUpdateInvoiceContractStatus($contract->id, $decision['status'], $decision['credit']);
    // Legacy rows are bound only after a store-scoped, authenticated fetch AND
    // a complete contract check. Never guess a mapping from orderId alone.
    if (empty($contract->connection_key)) {
        Capsule::table(BP_INVOICE_CONTRACT_TABLE)->where('id', $contract->id)
            ->update(['connection_key' => $client->connectionKey()]);
    }
    return $decision + ['btcpay_invoice_id' => $data['id'],
        'whmcs_invoice_id' => (int) $contract->whmcs_invoice_id, 'invoice' => $data];
}

function bpSynchronizeInvoice(Greenfield $client, string $id): array
{
    return bpWithLockedInvoiceContract($id, fn ($contract) => bpSynchronizeLockedInvoice($client, $contract));
}

/** Process an already signature-verified event from Greenfield::webhook(). */
function bpProcessWebhook(Greenfield $client, array $event): array
{
    if (!empty($event['ignored'])) {
        return ['outcome' => 'event_ignored'];
    }
    $id = $event['invoice_id'];
    if (bpFindInvoiceContract($id)) {
        return bpSynchronizeInvoice($client, $id);
    }
    // Store-wide webhooks also carry other integrations' invoices. Only our
    // mapped invoices need WHMCS metadata and the full payment contract checks.
    $invoice = $client->getInvoice($id);
    if (is_array($invoice['metadata'] ?? null) && ($invoice['metadata']['integration'] ?? '') === 'whmcs') {
        // The notification may have raced checkout's mapping commit. Do not
        // acknowledge it and lose the payment; BTCPay must retry this delivery.
        throw new GatewayException('Invoice mapping is not available yet. Retry delivery.', 503);
    }
    return ['outcome' => 'unmapped_invoice_ignored', 'btcpay_invoice_id' => $id];
}

/** Never log SDK exceptions, complete invoice responses, or customer metadata. */
function bpLogPaymentResult(array $gateway, array $result): void
{
    $context = array_intersect_key($result, array_flip([
        'btcpay_invoice_id', 'whmcs_invoice_id', 'status', 'additional_status', 'outcome', 'manual_review',
    ]));
    $message = !empty($result['manual_review'])
        ? 'MANUAL REVIEW REQUIRED: BTCPay payment needs reconciliation. No automatic reversal was made.'
        : 'BTCPay: ' . ($result['outcome'] ?? 'status_recorded');
    if (($result['additional_status'] ?? '') === 'marked') {
        $message .= ' Invoice was manually marked in BTCPay.';
    } elseif (($result['additional_status'] ?? '') === 'paidover') {
        $message .= !empty($result['credit'])
            ? ' Customer overpaid; only the original WHMCS amount was credited.'
            : ' Customer overpaid; review the payment outcome and transaction history.';
    }
    if (function_exists('logTransaction')) {
        logTransaction($gateway['name'] ?? 'BTCPay Server', $context, $message);
    }
    if (!empty($result['manual_review'])) {
        $message .= ' ' . bpFormatCallbackDiagnosticContext($context);
        error_log($message);
        if (function_exists('logActivity')) {
            logActivity($message);
        }
    }
}

function bpWriteWebhookTrace(array $gateway, string $trace, string $stage, array $context = []): void
{
    if (!bpGatewayOptionEnabled($gateway['callbackDiagnostics'] ?? null)) {
        return;
    }
    $message = 'BTCPay webhook [' . $trace . '] ' . $stage . ' ' . bpFormatCallbackDiagnosticContext($context);
    error_log($message);
    try {
        if (function_exists('logActivity')) {
            logActivity($message);
        }
        if (function_exists('logTransaction')) {
            logTransaction($gateway['name'] ?? 'BTCPay Server', ['trace_id' => $trace] + $context, $stage);
        }
    } catch (Throwable $exception) {
        error_log('Unable to write BTCPay diagnostics for trace ' . $trace);
    }
}
