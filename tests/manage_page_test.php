<?php

require __DIR__ . '/support.php';

// Exercise the actual endpoint with only the proprietary WHMCS bootstrap/model
// boundaries substituted. Any unexpected mutating path fails before an API call.
$directory = sys_get_temp_dir() . '/btcpay-manage-' . bin2hex(random_bytes(8));
mkdir($directory . '/modules/gateways/btcpay', 0700, true);
mkdir($directory . '/includes', 0700);
try {
    foreach (['init.php', 'includes/gatewayfunctions.php', 'includes/invoicefunctions.php'] as $file) {
        file_put_contents($directory . '/' . $file, '<?php');
    }
    copy(__DIR__ . '/../modules/gateways/btcpay/manage.php', $directory . '/modules/gateways/btcpay/manage.php');
    file_put_contents($directory . '/modules/gateways/btcpay/bootstrap.php', '<?php require ' .
        var_export(realpath(__DIR__ . '/../modules/gateways/btcpay/bootstrap.php'), true) . ';');
    $probe = <<<'PHP'
        namespace WHMCS\User {
            class Admin {
                public static function getAuthenticatedUser() { return $GLOBALS['scenario'] === 'anonymous' ? null : new self(); }
                public function isAllowedToAuthenticate() { return $GLOBALS['scenario'] !== 'disabled'; }
                public function hasPermission($permission) { return $permission === 'Manage Payment Gateways' && $GLOBALS['scenario'] !== 'no_permission'; }
            }
        }
        namespace WHMCS\Module {
            class GatewaySetting {
                public static function where(...$args) { return new self(); }
                public function get() {
                    $values = ['type' => 'invoices', 'btcpayUrl' => 'https://pay.example.test', 'storeId' => 'STORE-1',
                        'apiKey' => 'NEVER-EXPOSE-API-KEY', 'webhookSecret' => 'NEVER-EXPOSE-MANUAL-SECRET',
                        'webhookData' => $GLOBALS['scenario'] === 'damaged_record' ? '{broken' : json_encode([
                            'id' => 'OLD', 'secret' => 'NEVER-EXPOSE-MANAGED-SECRET',
                            'url' => 'https://billing.example.test/whmcs/modules/gateways/callback/btcpay.php',
                            'connection_key' => hash('sha256', "https://pay.example.test\nSTORE-1")])];
                    $rows = [];
                    foreach ($values as $key => $value) { $rows[] = (object) ['setting' => $key, 'value' => $value]; }
                    return $rows;
                }
            }
        }
        namespace WHMCS\Database {
            class Capsule {
                public static function connection() { $GLOBALS['unexpectedMutation'] = true; throw new \RuntimeException('Unexpected write'); }
            }
        }
        namespace {
            $scenario = $argv[2];
            $CONFIG['SystemURL'] = 'https://billing.example.test/whmcs';
            $_SERVER = ['REQUEST_METHOD' => in_array($scenario, ['missing_csrf', 'missing_confirmation', 'expired', 'replayed']) ? 'POST' : 'GET'];
            $_SESSION = ['btcpay_manage_token' => 'test-csrf', 'btcpay_webhook_reregister' => [
                'token' => 'test-nonce', 'state' => 'test-state', 'expires' => time() + 900]];
            $_POST = ['action' => 'reregister', 'token' => 'test-csrf', 'reregisterToken' => 'test-nonce', 'confirmReregister' => 'yes'];
            if ($scenario === 'missing_csrf') { unset($_POST['token']); }
            if ($scenario === 'missing_confirmation') { unset($_POST['confirmReregister']); }
            if ($scenario === 'expired') { $_SESSION['btcpay_webhook_reregister']['expires'] = time() - 1; }
            if ($scenario === 'replayed') { unset($_SESSION['btcpay_webhook_reregister']); }
            register_shutdown_function(function () {
                echo "\nHTTP: ", http_response_code() ?: 200, "\nMUTATION: ", empty($GLOBALS['unexpectedMutation']) ? 'no' : 'yes';
            });
            require $argv[1] . '/modules/gateways/btcpay/manage.php';
        }
        PHP;
    foreach (['get', 'damaged_record', 'anonymous', 'disabled', 'no_permission', 'missing_csrf', 'missing_confirmation', 'expired', 'replayed'] as $scenario) {
        $process = proc_open([PHP_BINARY, '-r', $probe, $directory, $scenario],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        testSame(0, proc_close($process), $scenario . ': endpoint exits cleanly');
        testSame(true, str_contains($output, 'MUTATION: no'), $scenario . ': no database or API mutation');
        testSame(false, str_contains($output, 'NEVER-EXPOSE'), $scenario . ': does not expose credentials or secrets');
        if (in_array($scenario, ['anonymous', 'disabled', 'no_permission', 'missing_csrf'], true)) {
            testSame(true, str_contains($output, 'HTTP: 403'), $scenario . ': access is forbidden');
            testSame(false, str_contains($output, 'value="reregister"'), $scenario . ': no replacement form');
        } else {
            testSame(true, str_contains($output, 'value="reregister"'), $scenario . ': renders replacement button');
            testSame(true, str_contains($output, 'name="confirmReregister"'), $scenario . ': renders explicit confirmation');
        }
        if ($scenario === 'missing_confirmation') { testSame(true, str_contains($output, 'Confirm webhook replacement using the checkbox'), 'Enforces confirmation on the server'); }
        if ($scenario === 'get') { testSame(true, str_contains($output, 'Saved webhook ID: OLD'), 'Displays the non-secret webhook ID for safe cleanup'); }
        if (in_array($scenario, ['expired', 'replayed'], true)) { testSame(true, str_contains($output, 'expired or was already used'), $scenario . ': reports invalid confirmation'); }
    }
    echo "Management endpoint authorization, CSRF, confirmation, recovery rendering and secret-redaction tests passed.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($directory);
}
