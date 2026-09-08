<?php

// Exercise the render-only administrator footer hook, including the guarantee
// that native settings saves cannot trigger automatic webhook setup.
if (($argv[1] ?? '') === '--hook-request') {
    $scenario = $argv[2];
    if ($scenario !== 'outside_whmcs') { define('WHMCS', true); }
    if ($scenario !== 'missing_admin_constant') { define('ADMINAREA', $scenario !== 'non_admin'); }
    $registeredHooks = [];
    if ($scenario !== 'missing_hook_api') {
        function add_hook(string $name, int $priority, callable $callback): void
        {
            $GLOBALS['registeredHooks'][] = compact('name', 'priority', 'callback');
        }
    }
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/renamed-admin/configgateways.php'];
    $_GET = [];
    $_POST = [];
    $_SESSION = ['btcpay_settings_save_token' => 'OBSOLETE-SESSION-TOKEN'];
    $footerVars = ['filename' => 'configgateways', 'adminid' => 1];
    if (in_array($scenario, ['native_post', 'native_ajax'], true)) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['gateway' => 'btcpay', 'action' => 'save', 'token' => 'NATIVE-FORM-TOKEN',
            'btcpayWebhookSetupToken' => 'OBSOLETE-SESSION-TOKEN', 'apiKey' => 'UNSAVED-API-KEY',
            'storeId' => 'UNSAVED-STORE-ID', 'webhookSecret' => 'UNSAVED-WEBHOOK-SECRET'];
    }
    if ($scenario === 'native_ajax') {
        $_SERVER['SCRIPT_NAME'] = '/renamed-admin/index.php';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_GET = ['rp' => '/admin/setup/payments/gateways'];
        $footerVars = ['filename' => 'index', 'adminid' => 1];
    }
    if ($scenario === 'other_admin_page') {
        $_SERVER['SCRIPT_NAME'] = '/renamed-admin/clients.php';
        $footerVars = ['filename' => 'clients', 'adminid' => 1];
    }
    if ($scenario === 'empty_filename') {
        unset($_SERVER['SCRIPT_NAME']);
        $footerVars = [];
    }
    $originalPost = $_POST;
    $originalSession = $_SESSION;
    $includedBefore = get_included_files();
    ob_start();
    require dirname(__DIR__) . '/includes/hooks/btcpay.php';
    $hookOutput = ob_get_clean();
    $footer = '';
    foreach ($registeredHooks as $registered) {
        $footer .= ($registered['callback'])($footerVars);
    }
    // Observe normal request completion as well: obsolete POST tokens must not
    // leave a deferred provisioning callback that runs after hook rendering.
    register_shutdown_function(function () use ($originalPost, $originalSession, $includedBefore, $hookOutput, $footer) {
        echo json_encode([
            'hooks' => array_map(fn ($hook) => ['name' => $hook['name'], 'priority' => $hook['priority']], $GLOBALS['registeredHooks']),
            'hook_output' => $hookOutput,
            'footer' => $footer,
            'post_unchanged' => $_POST === $originalPost,
            'session_unchanged' => $_SESSION === $originalSession,
            'included' => array_values(array_diff(get_included_files(), $includedBefore)),
            'has_bootstrap' => function_exists('btcpayLoadDependencies'),
            'has_save_scheduler' => function_exists('bpScheduleGatewayWebhookSetup'),
            'has_save_field' => function_exists('bpGatewaySettingsSaveField'),
            'has_model' => class_exists('WHMCS\Module\GatewaySetting', false),
        ], JSON_THROW_ON_ERROR);
    });
    exit;
}

require __DIR__ . '/support.php';

$inactive = ['outside_whmcs', 'missing_admin_constant', 'non_admin', 'missing_hook_api'];
$active = ['native_get', 'native_post', 'native_ajax', 'other_admin_page', 'empty_filename'];
$javascript = file_get_contents(__DIR__ . '/../modules/gateways/btcpay/webhook-setup.js');
testSame(true, is_string($javascript) && $javascript !== '', 'Explicit webhook setup script is packaged');
$firstFooter = null;
foreach (array_merge($inactive, $active) as $scenario) {
    $process = proc_open([PHP_BINARY, __FILE__, '--hook-request', $scenario],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    testSame(0, proc_close($process), $scenario . ': hook and request completion need no proprietary APIs: ' . $error);
    testSame('', $error, $scenario . ': no PHP warnings or deferred setup errors');
    $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    testSame('', $result['hook_output'], $scenario . ': hook loading does not emit a response');
    testSame(true, $result['post_unchanged'], $scenario . ': native submission remains untouched');
    testSame(true, $result['session_unchanged'], $scenario . ': obsolete save tokens cause no state change');
    testSame([realpath(__DIR__ . '/../includes/hooks/btcpay.php')], $result['included'],
        $scenario . ': hook rendering never loads payment or database code');
    foreach (['has_bootstrap', 'has_save_scheduler', 'has_save_field', 'has_model'] as $flag) {
        testSame(false, $result[$flag], $scenario . ': no provisioning dependency: ' . $flag);
    }
    if (in_array($scenario, $inactive, true)) {
        testSame([], $result['hooks'], $scenario . ': does not register hooks outside a supported admin context');
        testSame('', $result['footer'], $scenario . ': does not inject JavaScript');
        continue;
    }
    testSame(1, count($result['hooks']), $scenario . ': registers only one rendering hook');
    testSame('AdminAreaFooterOutput', $result['hooks'][0]['name'], $scenario . ': uses the documented output hook');
    testSame(true, str_contains($result['footer'], $javascript), $scenario . ': emits the static explicit-button script');
    $firstFooter ??= $result['footer'];
    testSame($firstFooter, $result['footer'], $scenario . ': works without native page, route or AJAX-save assumptions');
    foreach (['UNSAVED-API-KEY', 'UNSAVED-STORE-ID', 'UNSAVED-WEBHOOK-SECRET', 'OBSOLETE-SESSION-TOKEN'] as $secret) {
        testSame(false, str_contains($result['footer'], $secret), $scenario . ': never serializes form or session secrets');
    }
}

$hookSource = file_get_contents(__DIR__ . '/../includes/hooks/btcpay.php');
foreach (['register_shutdown_function', 'bpScheduleGatewayWebhookSetup', 'bpObserveGatewaySettingsSaves',
    'bpProvisionSavedWebhook', 'GatewaySetting::saved'] as $automaticSetup) {
    testSame(false, str_contains($hookSource, $automaticSetup), 'Hook cannot trigger or schedule automatic setup: ' . $automaticSetup);
}
// JavaScript click behavior and native-save isolation are exercised by
// webhook_setup_ui_test.js rather than assertions about source-code spelling.
testSame(false, function_exists('bpGatewaySettingsSaveField'), 'Obsolete settings-save hidden field is removed');
testSame(false, function_exists('bpScheduleGatewayWebhookSetup'), 'Obsolete settings-save scheduler is removed');
echo "Administrator footer hook rendering, native AJAX/save isolation and guard tests passed.\n";
