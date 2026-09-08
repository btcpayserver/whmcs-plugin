<?php

// Execute the actual management endpoint with only WHMCS and the HTTP transport
// substituted. The official SDK, Greenfield policy and persistence helpers run
// unchanged, including when $argv[1] is an extracted production release.
namespace {
    $project = realpath($argv[1] ?? '');
    if ($project === false || !is_file($project . '/modules/gateways/btcpay/manage.php')) {
        throw new \RuntimeException('Pass the project or extracted release directory.');
    }
    require $project . '/modules/gateways/btcpay/vendor/autoload.php';
}

namespace WHMCS\User {
    class Admin
    {
        public static function getAuthenticatedUser()
        {
            return $GLOBALS['fixture']['scenario'] === 'anonymous_json' ? null : new self();
        }

        public function isAllowedToAuthenticate()
        {
            return $GLOBALS['fixture']['scenario'] !== 'disabled_json';
        }

        public function hasPermission($permission)
        {
            $allowed = $GLOBALS['fixture']['scenario'] === 'bogus_permission_json'
                ? 'Manage Payment Gateways' : 'Configure Payment Gateways';
            return $permission === $allowed && $GLOBALS['fixture']['scenario'] !== 'no_permission_json';
        }
    }
}

namespace WHMCS\Module {
    class GatewaySetting
    {
        public string $gateway = 'btcpay';
        public string $setting = '';
        public string $value = '';

        public static function where($name, $value)
        {
            return (new SetupSettingQuery())->where($name, $value);
        }

        public function save()
        {
            $fixture = &$GLOBALS['fixture'];
            $fixture['attempted_writes'][] = $this->setting;
            if ($this->setting === 'webhookData') {
                if ($fixture['scenario'] === 'save_failure') {
                    throw new \RuntimeException('NEVER-EXPOSE-DATABASE-BINDINGS');
                }
                if ($fixture['scenario'] === 'save_invalid_argument') {
                    throw new \InvalidArgumentException('NEVER-EXPOSE-DATABASE-BINDINGS');
                }
                if ($fixture['scenario'] === 'save_false') {
                    return false;
                }
            }
            $fixture['values'][$this->setting] = $this->value;
            $fixture['writes'][] = $this->setting;
            return true;
        }
    }

    class SetupSettingQuery
    {
        private array $conditions = [];

        public function where($name, $value)
        {
            $this->conditions[$name] = $value;
            return $this;
        }

        public function lockForUpdate()
        {
            if ($GLOBALS['fixture']['transaction_level'] < 1) {
                throw new \RuntimeException('Expected setup to lock settings inside a transaction.');
            }
            return $this;
        }

        public function get()
        {
            if (($this->conditions['gateway'] ?? '') !== 'btcpay') {
                throw new \RuntimeException('Unexpected settings query.');
            }
            $rows = [];
            foreach ($GLOBALS['fixture']['values'] as $name => $value) {
                if (isset($this->conditions['setting']) && $this->conditions['setting'] !== $name) {
                    continue;
                }
                $row = new GatewaySetting();
                $row->setting = $name;
                $row->value = $value;
                $rows[] = $row;
            }
            return $rows;
        }

        public function first()
        {
            return $this->get()[0] ?? null;
        }
    }
}

namespace WHMCS\Database {
    class Capsule
    {
        public static function connection()
        {
            return new self();
        }

        public function transactionLevel()
        {
            return $GLOBALS['fixture']['transaction_level'];
        }

        public function transaction(callable $callback)
        {
            $fixture = &$GLOBALS['fixture'];
            $beforeValues = $fixture['values'];
            $beforeWrites = $fixture['writes'];
            ++$fixture['transaction_level'];
            try {
                return $callback();
            } catch (\Throwable $exception) {
                $fixture['values'] = $beforeValues;
                $fixture['writes'] = $beforeWrites;
                throw $exception;
            } finally {
                --$fixture['transaction_level'];
            }
        }
    }
}

namespace BTCPayServer\Http {
    class CurlClient implements ClientInterface
    {
        public function setCurlOptions(array $options) {}

        public function request(string $method, string $url, array $headers = [], string $body = ''): ResponseInterface
        {
            $fixture = &$GLOBALS['fixture'];
            $fixture['requests'][] = compact('method', 'url', 'headers', 'body');
            if (!str_starts_with($url, 'https://pay.example.test/btcpay/api/v1/') ||
                ($headers['Authorization'] ?? '') !== 'token NEVER-EXPOSE-SAVED-KEY' ||
                str_contains($url, 'UNSAVED-STORE')) {
                throw new \RuntimeException('Endpoint did not use persisted credentials.');
            }
            if (str_ends_with($url, '/api-keys/current')) {
                if ($fixture['scenario'] === 'api_failure') {
                    return new Response(403, 'NEVER-EXPOSE-API-RESPONSE', []);
                }
                $data = ['permissions' => ['btcpay.store.cancreateinvoice:STORE-1', 'btcpay.store.canviewinvoices:STORE-1']];
                if ($fixture['scenario'] !== 'permission_failure') {
                    $data['permissions'][] = 'btcpay.store.webhooks.canmodifywebhooks:STORE-1';
                }
            } elseif ($method === 'GET' && str_contains($url, '/invoices?')) {
                $data = [];
            } elseif ($method === 'GET' && str_ends_with($url, '/webhooks')) {
                $data = array_values($fixture['hooks']);
            } elseif ($method === 'GET' && preg_match('~/webhooks/([^/]+)$~D', $url, $matches)) {
                if (!isset($fixture['hooks'][$matches[1]])) {
                    return new Response(404, 'Not found', []);
                }
                $data = $fixture['hooks'][$matches[1]];
            } elseif ($method === 'POST' && str_ends_with($url, '/webhooks')) {
                $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
                $fixture['creation_secret_omitted'] = !array_key_exists('secret', $data);
                $data = ['id' => 'HOOK-NEW'] + $data;
                $fixture['hooks']['HOOK-NEW'] = $data;
                $data['secret'] = 'NEVER-EXPOSE-GENERATED-SECRET';
            } elseif ($method === 'PUT' && str_ends_with($url, '/webhooks/HOOK-OLD')) {
                $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
                $fixture['repaired_secret_preserved'] = ($data['secret'] ?? null) === 'NEVER-EXPOSE-OLD-SECRET';
                unset($data['secret']); // Existing secrets are not returned by BTCPay.
                $data = ['id' => 'HOOK-OLD'] + $data;
                $fixture['hooks']['HOOK-OLD'] = $data;
            } else {
                throw new \RuntimeException('Unexpected endpoint request; fixture never connects to a network.');
            }
            return new Response(200, json_encode($data, JSON_THROW_ON_ERROR), []);
        }
    }
}

namespace {
    $scenario = $argv[2] ?? 'first_json';
    $callback = 'https://billing.example.test/whmcs/modules/gateways/callback/btcpay.php';
    $saved = ['id' => 'HOOK-OLD', 'secret' => 'NEVER-EXPOSE-OLD-SECRET', 'url' => $callback,
        'connection_key' => hash('sha256', "https://pay.example.test/btcpay\nSTORE-1")];
    $fixture = ['scenario' => $scenario, 'values' => ['type' => 'invoices',
        'btcpayUrl' => 'https://pay.example.test/btcpay/', 'storeId' => 'STORE-1',
        'apiKey' => 'NEVER-EXPOSE-SAVED-KEY', 'webhookSecret' => ''],
        'writes' => [], 'attempted_writes' => [], 'requests' => [], 'transaction_level' => 0,
        'creation_secret_omitted' => null, 'repaired_secret_preserved' => null,
        'hooks' => ['OTHER' => ['id' => 'OTHER', 'url' => 'https://other.example.test/callback']]];
    if (in_array($scenario, ['repeat_json', 'permission_failure', 'api_failure', 'save_failure', 'save_false', 'save_invalid_argument',
        'check_stale_saved', 'check_damaged_saved'], true)) {
        if ($scenario === 'check_stale_saved') {
            $saved['connection_key'] = str_repeat('0', 64);
        }
        $fixture['values']['webhookData'] = $scenario === 'check_damaged_saved'
            ? '{invalid' : json_encode($saved, JSON_THROW_ON_ERROR);
        $fixture['hooks']['HOOK-OLD'] = ['id' => 'HOOK-OLD', 'url' => $callback, 'enabled' => false];
    }
    if ($scenario === 'check_invalid_manual') {
        $fixture['values']['webhookSecret'] = str_repeat(' ', 12);
    }
    $initialWebhook = $fixture['values']['webhookData'] ?? null;
    $CONFIG['SystemURL'] = 'https://billing.example.test/whmcs';
    $_SERVER = ['REQUEST_METHOD' => 'POST'];
    $_SESSION = ['btcpay_manage_token' => 'test-csrf'];
    $_POST = ['action' => 'setup', 'token' => 'test-csrf', 'responseFormat' => 'json',
        'apiKey' => 'UNSAVED-KEY', 'storeId' => 'UNSAVED-STORE', 'btcpayUrl' => 'https://unsaved.example.test',
        'webhookSecret' => 'UNSAVED-SECRET'];
    if ($scenario === 'first_html' || str_starts_with($scenario, 'check_')) {
        unset($_POST['responseFormat']);
    }
    if (str_starts_with($scenario, 'check_')) {
        $_POST['action'] = 'check';
    }
    if ($scenario === 'missing_csrf_json') { unset($_POST['token']); }
    if ($scenario === 'array_csrf_json') { $_POST['token'] = ['test-csrf']; }
    if ($scenario === 'stale_csrf_json') { $_POST['token'] = 'stale-csrf'; }
    if ($scenario === 'missing_session_json') { $_SESSION = []; }
    if ($scenario === 'json_reregister') { $_POST['action'] = 'reregister'; }
    if ($scenario === 'json_reconcile') { $_POST['action'] = 'reconcile'; }
    if ($scenario === 'json_check') { $_POST['action'] = 'check'; }
    if ($scenario === 'json_unknown') { $_POST['action'] = 'unknown'; }
    if ($scenario === 'json_array_action') { $_POST['action'] = ['setup']; }
    if ($scenario === 'get_query_setup') {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['action' => 'setup', 'responseFormat' => 'json', 'token' => 'test-csrf'];
        $_POST = [];
    }

    $directory = sys_get_temp_dir() . '/btcpay-setup-' . bin2hex(random_bytes(8));
    mkdir($directory . '/modules/gateways/btcpay', 0700, true);
    mkdir($directory . '/includes', 0700);
    foreach (['includes/gatewayfunctions.php', 'includes/invoicefunctions.php'] as $file) {
        file_put_contents($directory . '/' . $file, '<?php');
    }
    copy(__DIR__ . '/manage_whmcs_init.php', $directory . '/init.php');
    copy($project . '/modules/gateways/btcpay/manage.php', $directory . '/modules/gateways/btcpay/manage.php');
    file_put_contents($directory . '/modules/gateways/btcpay/bootstrap.php', '<?php require ' .
        var_export($project . '/modules/gateways/btcpay/bootstrap.php', true) . ';');
    ob_start();
    register_shutdown_function(function () use ($directory, $initialWebhook) {
        $output = ob_get_clean();
        $fixture = $GLOBALS['fixture'];
        $json = json_decode($output, true);
        $saved = json_decode($fixture['values']['webhookData'] ?? 'null', true);
        $mutations = array_values(array_filter($fixture['requests'], fn ($request) => $request['method'] !== 'GET'));
        $requestCredentialsSaved = true;
        foreach ($fixture['requests'] as $request) {
            $requestCredentialsSaved = $requestCredentialsSaved &&
                str_starts_with($request['url'], 'https://pay.example.test/btcpay/api/v1/') &&
                ($request['headers']['Authorization'] ?? '') === 'token NEVER-EXPOSE-SAVED-KEY' &&
                !str_contains($request['url'], 'UNSAVED-STORE');
        }
        $result = ['http_status' => http_response_code() ?: 200, 'is_json' => is_array($json),
            'normal_bootstrap' => !empty($GLOBALS['managementBootstrapPassed']),
            'response_keys' => is_array($json) ? array_keys($json) : [],
            'success' => $json['success'] ?? null, 'message' => $json['message'] ?? '',
            'output_has_secret' => str_contains($output, 'NEVER-EXPOSE') || str_contains($output, 'UNSAVED-'),
            'check_success' => str_contains($output, 'Connection successful.'),
            'green_status' => str_contains($output, '<p class="text-success" role="status">Webhook ready.'),
            'status' => $fixture['values']['webhookSetupStatus'] ?? '',
            'saved_id' => $saved['id'] ?? null,
            'generated_secret_saved' => ($saved['secret'] ?? null) === 'NEVER-EXPOSE-GENERATED-SECRET',
            'previous_secret_preserved' => ($saved['secret'] ?? null) === 'NEVER-EXPOSE-OLD-SECRET',
            'webhook_data_unchanged' => ($fixture['values']['webhookData'] ?? null) === $initialWebhook,
            'manual_secret_blank' => $fixture['values']['webhookSecret'] === '',
            'writes' => $fixture['writes'], 'attempted_writes' => $fixture['attempted_writes'],
            'request_methods' => array_column($fixture['requests'], 'method'),
            'mutation_methods' => array_column($mutations, 'method'),
            'callbacks' => array_map(fn ($request) => json_decode($request['body'], true)['url'] ?? null, $mutations),
            'creation_secret_omitted' => $fixture['creation_secret_omitted'],
            'repaired_secret_preserved' => $fixture['repaired_secret_preserved'],
            'hook_enabled' => $fixture['hooks'][$saved['id'] ?? '']['enabled'] ?? null,
            'other_hook_unchanged' => $fixture['hooks']['OTHER'] === ['id' => 'OTHER', 'url' => 'https://other.example.test/callback'],
            'request_credentials_saved' => $requestCredentialsSaved,
            'transaction_level' => $fixture['transaction_level']];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
        echo json_encode($result, JSON_THROW_ON_ERROR);
    });
    require $directory . '/modules/gateways/btcpay/manage.php';
}
