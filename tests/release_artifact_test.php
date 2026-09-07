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
        'includes/hooks/btcpay.php', 'GUIDE.md', 'LICENSE'] as $file) {
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
    echo "Release ZIP autoload, overwrite-upgrade and missing-dependency tests passed.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
