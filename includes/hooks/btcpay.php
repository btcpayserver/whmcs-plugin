<?php

// Overwrite older experimental save hooks with this render-only hook.
// No settings-save interception, shutdown work, database writes or API calls.
if (!defined('WHMCS') || !defined('ADMINAREA') || !ADMINAREA || !function_exists('add_hook')) {
    return;
}
add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    // The handler only reacts to our own explicit button. Delegation also
    // supports gateway forms inserted by AJAX, without relying on WHMCS routes.
    $script = __DIR__ . '/../../modules/gateways/btcpay/webhook-setup.js';
    if (!is_readable($script)) {
        return '';
    }
    return '<script>' . file_get_contents($script) . '</script>';
});
