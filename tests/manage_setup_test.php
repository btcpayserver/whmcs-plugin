<?php

require __DIR__ . '/support.php';

$successful = ['first_html', 'first_json', 'repeat_json'];
$rejected = ['anonymous_json', 'disabled_json', 'no_permission_json', 'bogus_permission_json', 'missing_csrf_json',
    'array_csrf_json', 'stale_csrf_json', 'missing_session_json'];
$unsupported = ['json_reregister', 'json_reconcile', 'json_check', 'json_unknown', 'json_array_action'];
$checks = ['check_before_setup', 'check_stale_saved', 'check_damaged_saved', 'check_invalid_manual'];
$readonly = ['get_query_setup'];
$failures = ['permission_failure' => 503, 'api_failure' => 502, 'save_failure' => 503, 'save_false' => 503,
    'save_invalid_argument' => 503];
foreach (array_merge($successful, $rejected, $unsupported, $checks, $readonly, array_keys($failures)) as $scenario) {
    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/manage_setup_request.php', dirname(__DIR__), $scenario],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    testSame(0, proc_close($process), $scenario . ': endpoint exits cleanly: ' . $error);
    $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    testSame(true, $result['normal_bootstrap'], $scenario . ': normal WHMCS bootstrap does not require an admin-directory path');
    testSame(false, $result['output_has_secret'], $scenario . ': no API key or webhook secret in the response');
    testSame(false, str_contains($error, 'NEVER-EXPOSE'), $scenario . ': no raw credentials, API body or SQL bindings in logs');
    testSame(true, $result['request_credentials_saved'], $scenario . ': uses saved credentials, never unsaved POST fields');
    testSame(true, $result['other_hook_unchanged'], $scenario . ': leaves unrelated webhooks alone');
    testSame(0, $result['transaction_level'], $scenario . ': no transaction left open');
    if ($scenario !== 'check_invalid_manual') {
        testSame(true, $result['manual_secret_blank'], $scenario . ': no secret is placed in the editable manual field');
    }
    $expectsJson = $scenario !== 'first_html' && !in_array($scenario, array_merge($checks, $readonly), true);
    testSame($expectsJson, $result['is_json'], $scenario . ': correct response format');
    if ($expectsJson) {
        testSame(['success', 'message'], $result['response_keys'], $scenario . ': response has only a safe success flag and message');
        testSame(!in_array($scenario, array_merge($rejected, $unsupported, array_keys($failures)), true),
            $result['success'], $scenario . ': reports the correct result');
    }
    if (in_array($scenario, $successful, true)) {
        testSame(200, $result['http_status'], $scenario . ': success');
        if ($scenario === 'first_html') {
            testSame(true, $result['green_status'], 'Management page displays successful setup in green');
        }
        testSame(true, str_starts_with($result['status'], 'Webhook ready.'), $scenario . ': setup status is persisted');
        testSame(['webhookData', 'webhookSetupStatus'], $result['writes'], $scenario . ': only managed data and status are saved');
        testSame(['https://billing.example.test/whmcs/modules/gateways/callback/btcpay.php'], $result['callbacks'],
            $scenario . ': callback URL is derived from WHMCS System URL');
        testSame(true, $result['hook_enabled'], $scenario . ': webhook is enabled');
        if ($scenario === 'repeat_json') {
            testSame('HOOK-OLD', $result['saved_id'], 'Repair preserves the existing webhook ID');
            testSame(['PUT'], $result['mutation_methods'], 'Repair does not delete or duplicate the existing webhook');
            testSame(true, $result['previous_secret_preserved'], 'Repair preserves the stored secret');
            testSame(true, $result['repaired_secret_preserved'], 'Repair explicitly preserves the remote secret');
        } else {
            testSame('HOOK-NEW', $result['saved_id'], $scenario . ': creates the first webhook without a manual secret');
            testSame(['POST'], $result['mutation_methods'], $scenario . ': exactly one remote creation');
            testSame(true, $result['creation_secret_omitted'], $scenario . ': BTCPay generates the secret');
            testSame(true, $result['generated_secret_saved'], $scenario . ': generated secret is saved server-side');
        }
        if ($expectsJson) { testSame(true, str_starts_with($result['message'], 'Webhook ready.'), $scenario . ': gives a clear ready result'); }
    } elseif (in_array($scenario, array_merge($rejected, $unsupported, $readonly), true)) {
        $expectedStatus = in_array($scenario, $readonly, true) ? 200 : (in_array($scenario, $rejected, true) ? 403 : 400);
        testSame($expectedStatus, $result['http_status'], $scenario . ': correct status for non-mutating request');
        testSame([], $result['request_methods'], $scenario . ': no API requests');
        testSame([], $result['attempted_writes'], $scenario . ': no database writes attempted');
        testSame(true, $result['webhook_data_unchanged'], $scenario . ': no managed data changes');
    } elseif (in_array($scenario, $checks, true)) {
        testSame(200, $result['http_status'], $scenario . ': connection check succeeds independently of webhook setup');
        testSame(true, $result['check_success'], $scenario . ': reports a successful API connection');
        testSame(['GET', 'GET'], $result['request_methods'], $scenario . ': only API key permissions and invoice read probe');
        testSame([], $result['attempted_writes'], $scenario . ': checking the connection never provisions or saves anything');
        testSame(true, $result['webhook_data_unchanged'], $scenario . ': does not alter stale or damaged managed data');
    } else {
        testSame($failures[$scenario], $result['http_status'], $scenario . ': meaningful failure status');
        testSame(true, $result['previous_secret_preserved'], $scenario . ': retains the previous secret on failure');
        testSame(true, $result['webhook_data_unchanged'], $scenario . ': failed setup does not replace the stored webhook');
        testSame(['webhookSetupStatus'], $result['writes'], $scenario . ': only a safe recoverable error status is committed');
        testSame(true, $result['message'] !== '', $scenario . ': provides an error message');
        testSame(in_array($scenario, ['save_failure', 'save_false', 'save_invalid_argument'], true) ? ['PUT'] : [],
            $result['mutation_methods'], $scenario . ': no destructive action even if persistence fails');
    }
}
echo "Explicit webhook setup endpoint, JSON/HTML responses, permission checks, persistence and secret-redaction tests passed.\n";
