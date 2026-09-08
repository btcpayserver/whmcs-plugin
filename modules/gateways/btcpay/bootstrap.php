<?php

// Load only this module's helpers. Composer is loaded lazily so an incomplete
// upload does not make WHMCS's gateway configuration page inaccessible.
require_once __DIR__ . '/bp_security.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/bp_invoice_contract.php';
require_once __DIR__ . '/Greenfield.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/settings.php';

function btcpayLoadDependencies(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    if (PHP_VERSION_ID < 80100) {
        throw new RuntimeException('BTCPay requires PHP 8.1 or newer.');
    }
    foreach (['bcmath', 'curl', 'json', 'mbstring'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('BTCPay requires the PHP ' . $extension . ' extension.');
        }
    }
    if (!is_file(__DIR__ . '/vendor/autoload.php')) {
        throw new RuntimeException('BTCPay dependencies are missing. Upload the complete release ZIP, or run composer install in the source checkout.');
    }
    require_once __DIR__ . '/vendor/autoload.php';
    $loaded = true;
}
