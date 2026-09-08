<?php

// Model the WHMCS admin-directory guard for our nested module endpoint. An
// empty init stub previously concealed the invalid ADMINAREA bootstrap.
// This is deliberately not a substitute for testing the real WHMCS runtime.
if (defined('ADMINAREA') && ADMINAREA) {
    throw new RuntimeException('Management endpoint incorrectly requests admin-directory initialization.');
}
define('WHMCS', true);
$GLOBALS['managementBootstrapPassed'] = true;
