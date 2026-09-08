<?php

// Keep the permission fixture independent of the plugin's helper. WHMCS's
// GetAdminDetails reference lists Configure Payment Gateways, not Manage Payment
// Gateways: https://developers.whmcs.com/api-reference/getadmindetails/
if (($argv[1] ?? '') === '--config-request') {
    $scenario = $argv[2];
    define('WHMCS', true);
    if (!in_array($scenario, ['client', 'missing_admin_constant'], true)) {
        define('ADMINAREA', $scenario !== 'non_admin');
    }
    class GatewayConfigAdmin
    {
        public static function getAuthenticatedUser()
        {
            $GLOBALS['authChecks']++;
            if ($GLOBALS['scenario'] === 'auth_error') {
                throw new RuntimeException('NEVER-EXPOSE-AUTH-DETAILS');
            }
            return in_array($GLOBALS['scenario'], ['anonymous', 'client'], true) ? null : new self();
        }

        public function isAllowedToAuthenticate(): bool
        {
            return $GLOBALS['scenario'] !== 'disabled';
        }

        public function hasPermission($permission): bool
        {
            $GLOBALS['permissionChecks'][] = $permission;
            $permissions = $GLOBALS['scenario'] === 'no_permission' ? ['View Gateway Log'] :
                ($GLOBALS['scenario'] === 'bogus_permission' ? ['Manage Payment Gateways'] : ['Configure Payment Gateways']);
            return in_array($permission, $permissions, true);
        }
    }
    class GatewayConfigStatus
    {
        public function __get($name)
        {
            if ($GLOBALS['scenario'] === 'status_accessor_error') {
                throw new RuntimeException('NEVER-EXPOSE-DECRYPTION-DETAILS');
            }
            return 'Webhook ready. <script>DO-NOT-EXECUTE</script>';
        }
    }
    class GatewayConfigSetting
    {
        public static function where(...$arguments)
        {
            $GLOBALS['statusQueries']++;
            if ($GLOBALS['scenario'] === 'status_query_error') {
                throw new RuntimeException('NEVER-EXPOSE-SQL-AND-BINDINGS');
            }
            if (!in_array($arguments, [['gateway', 'btcpay'], ['setting', 'webhookSetupStatus']], true)) {
                throw new RuntimeException('Unexpected configuration query');
            }
            return new self();
        }

        public function first()
        {
            return $GLOBALS['scenario'] === 'missing_status' ? null : new GatewayConfigStatus();
        }
    }
    class_alias('GatewayConfigAdmin', 'WHMCS\\User\\Admin');
    class_alias('GatewayConfigSetting', 'WHMCS\\Module\\GatewaySetting');
    $CONFIG['SystemURL'] = 'https://billing.example.test/whmcs';
    $_SESSION = ['unrelated_session_value' => 'NEVER-EXPOSE-SESSION-DATA'];
    if ($scenario === 'existing_token') {
        $_SESSION['btcpay_manage_token'] = 'existing-session-csrf-token';
    }
    $originalSession = $_SESSION;
    $authChecks = $statusQueries = 0;
    $permissionChecks = [];
    require dirname(__DIR__) . '/modules/gateways/btcpay.php';
    $description = btcpay_config()['webhookSecret']['Description'];
    $token = $_SESSION['btcpay_manage_token'] ?? null;
    $secondDescription = btcpay_config()['webhookSecret']['Description'];
    echo json_encode(compact('description', 'secondDescription', 'token', 'authChecks', 'statusQueries', 'permissionChecks') + [
        'session_unchanged' => $_SESSION === $originalSession,
        'token_stable' => $token === ($_SESSION['btcpay_manage_token'] ?? null),
    ], JSON_THROW_ON_ERROR);
    exit;
}

require __DIR__ . '/support.php';

$authorized = ['authorized', 'existing_token', 'missing_status', 'status_query_error', 'status_accessor_error'];
$unauthorized = ['anonymous', 'client', 'non_admin', 'missing_admin_constant', 'disabled',
    'no_permission', 'bogus_permission', 'auth_error'];
foreach (array_merge($authorized, $unauthorized) as $scenario) {
    $process = proc_open([PHP_BINARY, __FILE__, '--config-request', $scenario],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    testSame(0, proc_close($process), $scenario . ': config renders without terminating the page');
    $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    $description = $result['description'];
    testSame(true, str_contains($description, 'https://billing.example.test/whmcs/modules/gateways/btcpay/manage.php'),
        $scenario . ': retains the trusted management link');
    testSame(false, str_contains($description . $errors, 'NEVER-EXPOSE'), $scenario . ': does not leak session or exception data');
    testSame(false, str_contains($description, '<script>'), $scenario . ': stored status cannot inject markup');
    testSame($description, $result['secondDescription'], $scenario . ': repeated rendering is stable');
    testSame(true, $result['token_stable'], $scenario . ': does not rotate the shared management token on rendering');
    if (in_array($scenario, $unauthorized, true)) {
        testSame(false, str_contains($description, 'data-btcpay-webhook-setup'), $scenario . ': no privileged setup button');
        testSame(false, str_contains($description, 'data-setup-token'), $scenario . ': no CSRF token in markup');
        testSame(null, $result['token'], $scenario . ': does not create a privileged session token');
        testSame(true, $result['session_unchanged'], $scenario . ': leaves the session unchanged');
        testSame(0, $result['statusQueries'], $scenario . ': does not read privileged status');
        continue;
    }
    testSame(['Configure Payment Gateways', 'Configure Payment Gateways'], $result['permissionChecks'],
        $scenario . ': checks the documented WHMCS role permission');
    testSame(true, str_contains($description, 'type="button"'), $scenario . ': setup does not submit native settings');
    testSame(true, str_contains($description, 'data-btcpay-webhook-setup'), $scenario . ': renders explicit setup control');
    testSame(true, str_contains($description, 'display:block;margin-top:8px;font-weight:600'),
        $scenario . ': status is emphasized below the button');
    testSame(true, str_contains($description, 'aria-atomic="true"'), $scenario . ': announces the full result accessibly');
    testSame(true, str_contains($description, 'data-setup-token="' . $result['token'] . '"'),
        $scenario . ': button uses the server-side management CSRF token');
    if ($scenario === 'existing_token') {
        testSame('existing-session-csrf-token', $result['token'], 'Reuses an existing management-page token');
    } else {
        testSame(1, preg_match('/^[a-f0-9]{64}$/D', $result['token']), $scenario . ': generates a cryptographic CSRF token');
    }
    if (in_array($scenario, ['status_query_error', 'status_accessor_error'], true)) {
        testSame(false, str_contains($description, 'class="text-success"'), $scenario . ': unavailable status is not green');
        testSame(true, str_contains($description, 'Unable to read webhook setup status'),
            $scenario . ': reports a safe status failure without hiding authorized setup');
    } elseif ($scenario === 'missing_status') {
        testSame(false, str_contains($description, 'class="text-success"'), 'Missing status is not green');
        testSame(true, str_contains($description, 'Uses saved settings only'), 'First setup remains available without stored status');
    } else {
        testSame(true, str_contains($description, 'class="text-success"'), $scenario . ': persisted success renders green after reload');
        testSame(true, str_contains($description, '&lt;script&gt;DO-NOT-EXECUTE&lt;/script&gt;'),
            $scenario . ': escapes stored webhook status');
    }
}

echo "Gateway config permission, button rendering, CSRF isolation and optional status failure tests passed.\n";
