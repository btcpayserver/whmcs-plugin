<?php

// Runs on an extracted ZIP, independent of this repository's vendor directory.
$archive = $argv[1] ?? '';
$zip = new ZipArchive();
if ($zip->open($archive) !== true) {
    throw new RuntimeException('Cannot open release ZIP.');
}
$directory = sys_get_temp_dir() . '/btcpay-release-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_starts_with($name, '/') || str_contains($name, '..') || str_contains($name, '\\')) {
            throw new RuntimeException('Unsafe ZIP entry.');
        }
    }
    $zip->extractTo($directory);
    $zip->close();
    foreach (['modules/gateways/btcpay.php', 'modules/gateways/callback/btcpay.php',
        'modules/gateways/btcpay/vendor/autoload.php', 'modules/gateways/btcpay/manage.php',
        'includes/hooks/btcpay.php', 'modules/gateways/btcpay/webhook-setup.js', 'GUIDE.md', 'LICENSE'] as $file) {
        if (!is_file($directory . '/' . $file)) { throw new RuntimeException('Missing archive file: ' . $file); }
    }
    $gatewayDir = $directory . '/modules/gateways/btcpay';
    if (is_dir($gatewayDir . '/vendor/illuminate') || is_file($gatewayDir . '/bp_lib.php') || is_file($gatewayDir . '/bp_options.php')) {
        throw new RuntimeException('Release contains development dependencies or obsolete legacy files.');
    }
    $probe = <<<'PHP'
        $version = 'whmcs-host-version';
        require $argv[1] . '/modules/gateways/btcpay.php';
        if ($version !== 'whmcs-host-version' || BTCPAY_WHMCS_VERSION !== '4.0.0') { exit(3); }
        btcpayLoadDependencies();
        if (!class_exists('BTCPayServer\Client\Invoice') || btcpay_config()['apiKey']['Type'] !== 'password') { exit(1); }
        if (!function_exists('bpReRegisterSavedWebhook') || !method_exists('BTCPayWHMCS\Greenfield', 'prepareWebhookReplacement')) { exit(4); }
        if (function_exists('bpCurl') || function_exists('bpCreateInvoice')) { exit(2); }
        $client = new BTCPayWHMCS\Greenfield(['btcpayUrl'=>'https://example.test','storeId'=>'store','apiKey'=>'test','webhookSecret'=>'secret']);
        PHP;
    $run = function (string $code) use ($directory): void {
        $process = proc_open([PHP_BINARY, '-r', $code, $directory], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $directory);
        fclose($pipes[0]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Extracted gateway bootstrap failed.'); }
    };
    $run($probe);
    // Verify overwriting an old experimental hook disables native-save work.
    $run(<<<'PHP'
        define('WHMCS', true);
        define('ADMINAREA', true);
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'SCRIPT_NAME' => '/admin/configgateways.php'];
        $_POST = ['btcpayWebhookSetupToken' => 'obsolete-token', 'gateway' => 'btcpay'];
        $hooks = [];
        function add_hook($name, $priority, $callback) { $GLOBALS['hooks'][$name] = $callback; }
        require $argv[1] . '/includes/hooks/btcpay.php';
        if (array_keys($hooks) !== ['AdminAreaFooterOutput'] || function_exists('bpProvisionSavedWebhook')) { exit(5); }
        $script = $hooks['AdminAreaFooterOutput'](['filename' => 'index']);
        if (!str_contains($script, 'data-btcpay-webhook-setup') || str_contains($script, 'obsolete-token')) { exit(6); }
        PHP);
    // The actual packaged config renders an explicit non-submit button using
    // the same CSRF token as the management endpoint, not a native-save field.
    $run(<<<'PHP'
        define('ADMINAREA', true);
        class ConfigAdmin {
            public static function getAuthenticatedUser() { return new self(); }
            public function isAllowedToAuthenticate() { return true; }
            public function hasPermission($name) { return $name === 'Configure Payment Gateways'; }
        }
        class ConfigSetting {
            public static function where(...$args) { return new self(); }
            public function first() { return (object) ['value' => 'Webhook ready. <escaped>']; }
        }
        class_alias('ConfigAdmin', 'WHMCS\User\Admin');
        class_alias('ConfigSetting', 'WHMCS\Module\GatewaySetting');
        $CONFIG['SystemURL'] = 'https://billing.example.test/whmcs';
        $_SESSION = [];
        require $argv[1] . '/modules/gateways/btcpay.php';
        $description = btcpay_config()['webhookSecret']['Description'];
        if (!str_contains($description, 'type="button"') || !str_contains($description, 'data-btcpay-webhook-setup') ||
            !str_contains($description, 'data-setup-token="' . $_SESSION['btcpay_manage_token'] . '"') ||
            !str_contains($description, 'data-setup-url="https://billing.example.test/whmcs/modules/gateways/btcpay/manage.php"') ||
            !str_contains($description, 'Webhook ready. &lt;escaped&gt;') || !str_contains($description, 'class="text-success"') ||
            str_contains($description, 'btcpayWebhookSetupToken')) { exit(7); }
        if (function_exists('bpScheduleGatewayWebhookSetup') || function_exists('bpObserveGatewaySettingsSaves')) { exit(8); }
        PHP);
    // Exercise the packaged explicit endpoint with production dependencies.
    // Proprietary WHMCS boundaries/HTTP are substituted; no real API is called.
    foreach (['first_html', 'first_json', 'repeat_json', 'get_query_setup', 'bogus_permission_json', 'anonymous_json', 'missing_csrf_json'] as $scenario) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/manage_setup_request.php', $directory, $scenario],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, $directory);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        $rejected = in_array($scenario, ['bogus_permission_json', 'anonymous_json', 'missing_csrf_json'], true);
        if (proc_close($process) !== 0 || ($result['http_status'] ?? 0) !== ($rejected ? 403 : 200) ||
            !($result['normal_bootstrap'] ?? false) || ($result['output_has_secret'] ?? true)) {
            throw new RuntimeException('Packaged management endpoint failed: ' . $scenario);
        }
        if ($scenario === 'get_query_setup' || $rejected) {
            if ($result['attempted_writes'] !== [] || $result['request_methods'] !== []) {
                throw new RuntimeException('Packaged management page mutates on GET or a forbidden request.');
            }
        } elseif (($result['saved_id'] ?? '') !== ($scenario === 'repeat_json' ? 'HOOK-OLD' : 'HOOK-NEW') ||
            !($scenario === 'repeat_json' ? $result['previous_secret_preserved'] : $result['generated_secret_saved']) ||
            ($result['writes'] ?? []) !== ['webhookData', 'webhookSetupStatus'] ||
            !str_starts_with($result['status'] ?? '', 'Webhook ready.')) {
            throw new RuntimeException('Packaged explicit webhook setup failed: ' . $scenario);
        }
    }
    // If an upgrade still includes either legacy file, deliberately fail if
    // it is loaded. New code must not depend on either presence or contents.
    foreach (['bp_lib.php', 'bp_options.php'] as $legacy) {
        file_put_contents($gatewayDir . '/' . $legacy, '<?php throw new RuntimeException("Legacy code was loaded");');
    }
    $run($probe);
    rename($gatewayDir . '/vendor', $gatewayDir . '/vendor-missing');
    $run(<<<'PHP'
        require $argv[1] . '/modules/gateways/btcpay.php';
        if (!isset(btcpay_config()['storeId'])) { exit(1); }
        try { btcpayLoadDependencies(); exit(2); }
        catch (RuntimeException $exception) { if (!str_contains($exception->getMessage(), 'complete release ZIP')) { exit(3); } }
        PHP);
    echo "Release ZIP autoload, explicit webhook setup, safe hook overwrite and missing-dependency tests passed.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
