<?php

use WHMCS\Database\Capsule;

const BP_MAX_CALLBACK_BODY_BYTES = 65536;

/**
 * Escape a value for use in a generated HTML attribute.
 *
 * @param mixed $value
 * @return string
 */
function bpEscapeHtmlAttribute($value)
{
    if ($value === null) {
        $value = '';
    } elseif (!is_scalar($value)) {
        throw new InvalidArgumentException('HTML attribute values must be scalar.');
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Validate an absolute HTTP(S) URL supplied by trusted configuration.
 *
 * @param mixed $url
 * @param bool  $allowQuery
 * @return string
 */
function bpValidateAbsoluteHttpUrl($url, $allowQuery = true)
{
    if (!is_string($url)) {
        throw new InvalidArgumentException('URL must be a string.');
    }

    $url = trim($url);
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
        throw new InvalidArgumentException('URL is empty, too long, or contains unsafe characters.');
    }

    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        throw new InvalidArgumentException('URL must be absolute.');
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        throw new InvalidArgumentException('URL must use HTTP or HTTPS.');
    }
    if ($parts['host'] === '' || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('URL host or credentials are invalid.');
    }
    if (isset($parts['fragment'])) {
        throw new InvalidArgumentException('URL fragments are not allowed.');
    }
    if (!$allowQuery && isset($parts['query'])) {
        throw new InvalidArgumentException('URL query strings are not allowed here.');
    }

    return $url;
}

/**
 * @param mixed $url
 * @return string
 */
function bpNormalizeConfiguredBaseUrl($url)
{
    return rtrim(bpValidateAbsoluteHttpUrl($url, false), '/');
}

/**
 * @param string $baseUrl
 * @param string $path
 * @param array  $query
 * @return string
 */
function bpBuildTrustedUrl($baseUrl, $path, array $query = array())
{
    $baseUrl = bpNormalizeConfiguredBaseUrl($baseUrl);
    $url = $baseUrl . '/' . ltrim($path, '/');

    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return bpValidateAbsoluteHttpUrl($url);
}

/**
 * @param string     $systemUrl
 * @param int|string $invoiceId
 * @param mixed      $configuredRedirectUrl
 * @return string
 */
function bpBuildTrustedReturnUrl($systemUrl, $invoiceId, $configuredRedirectUrl)
{
    if (is_string($configuredRedirectUrl) && trim($configuredRedirectUrl) !== '') {
        return bpValidateAbsoluteHttpUrl($configuredRedirectUrl);
    }

    return bpBuildTrustedUrl($systemUrl, 'viewinvoice.php', array(
        'id' => (int) $invoiceId,
        'paymentsuccess' => 'true',
    ));
}

/**
 * Use a same-site relative action so WHMCS installations accessed through a
 * configured onion mirror do not need to trust the request Host header.
 *
 * @param string $systemUrl
 * @return string
 */
function bpBuildGatewayFormAction($systemUrl)
{
    $systemUrl = bpNormalizeConfiguredBaseUrl($systemUrl);
    $parts = parse_url($systemUrl);
    $basePath = isset($parts['path']) ? rtrim($parts['path'], '/') : '';

    return ($basePath === '' ? '' : $basePath) . '/modules/gateways/btcpay/createinvoice.php';
}

/**
 * Load the canonical public WHMCS URL from server-side configuration.
 *
 * @return string
 */
function bpGetConfiguredWhmcsSystemUrl()
{
    global $CONFIG;

    $systemUrl = isset($CONFIG['SystemURL']) ? $CONFIG['SystemURL'] : null;
    if (!is_string($systemUrl) || trim($systemUrl) === '') {
        $setting = Capsule::table('tblconfiguration')
            ->where('setting', 'SystemURL')
            ->first();
        $systemUrl = $setting ? $setting->value : null;
    }

    return bpNormalizeConfiguredBaseUrl($systemUrl);
}

/**
 * @param array $parts
 * @return int
 */
function bpEffectiveUrlPort(array $parts)
{
    if (isset($parts['port'])) {
        return (int) $parts['port'];
    }

    return strtolower($parts['scheme']) === 'https' ? 443 : 80;
}

/**
 * Require a checkout URL returned by BTCPay to remain under the configured
 * BTCPay origin and base path.
 *
 * @param mixed  $checkoutUrl
 * @param string $btcpayBaseUrl
 * @return string
 */
function bpValidateCheckoutUrl($checkoutUrl, $btcpayBaseUrl)
{
    $checkoutUrl = bpValidateAbsoluteHttpUrl($checkoutUrl);
    $btcpayBaseUrl = bpNormalizeConfiguredBaseUrl($btcpayBaseUrl);

    $checkoutParts = parse_url($checkoutUrl);
    $baseParts = parse_url($btcpayBaseUrl);

    if (strtolower($checkoutParts['scheme']) !== strtolower($baseParts['scheme']) ||
        strtolower($checkoutParts['host']) !== strtolower($baseParts['host']) ||
        bpEffectiveUrlPort($checkoutParts) !== bpEffectiveUrlPort($baseParts)) {
        throw new InvalidArgumentException('BTCPay checkout URL does not match the configured origin.');
    }

    $basePath = isset($baseParts['path']) ? rtrim($baseParts['path'], '/') : '';
    $checkoutPath = isset($checkoutParts['path']) ? $checkoutParts['path'] : '/';
    if ($basePath !== '' && $checkoutPath !== $basePath &&
        strpos($checkoutPath, $basePath . '/') !== 0) {
        throw new InvalidArgumentException('BTCPay checkout URL is outside the configured base path.');
    }

    return $checkoutUrl;
}

/**
 * Map a validated checkout URL from the configured public BTCPay base to its
 * separately configured Tor base.
 *
 * @param string $checkoutUrl
 * @param string $sourceBaseUrl
 * @param string $targetBaseUrl
 * @return string
 */
function bpMapCheckoutUrlToBase($checkoutUrl, $sourceBaseUrl, $targetBaseUrl)
{
    $checkoutUrl = bpValidateCheckoutUrl($checkoutUrl, $sourceBaseUrl);
    $sourceBaseUrl = bpNormalizeConfiguredBaseUrl($sourceBaseUrl);
    $targetBaseUrl = bpNormalizeConfiguredBaseUrl($targetBaseUrl);

    $checkoutParts = parse_url($checkoutUrl);
    $sourceParts = parse_url($sourceBaseUrl);
    $sourcePath = isset($sourceParts['path']) ? rtrim($sourceParts['path'], '/') : '';
    $checkoutPath = isset($checkoutParts['path']) ? $checkoutParts['path'] : '';
    $relativePath = substr($checkoutPath, strlen($sourcePath));

    $mapped = $targetBaseUrl . $relativePath;
    if (isset($checkoutParts['query'])) {
        $mapped .= '?' . $checkoutParts['query'];
    }

    return bpValidateCheckoutUrl($mapped, $targetBaseUrl);
}

/**
 * The Host header is used only to choose between two administrator-configured
 * checkout origins; it is never copied into a URL.
 *
 * @param array $server
 * @return bool
 */
function bpRequestUsesOnionHost(array $server)
{
    if (!isset($server['HTTP_HOST']) || !is_string($server['HTTP_HOST']) ||
        strlen($server['HTTP_HOST']) > 255 || preg_match('/[\x00-\x20\x7F]/', $server['HTTP_HOST'])) {
        return false;
    }

    $parts = parse_url('http://' . $server['HTTP_HOST']);
    if (!is_array($parts) || !isset($parts['host'])) {
        return false;
    }

    $host = strtolower(rtrim($parts['host'], '.'));

    return strlen($host) > 6 && substr($host, -6) === '.onion';
}

/**
 * Parse and bound the untrusted legacy callback request before accessing it.
 *
 * @param array  $server
 * @param mixed  $body
 * @param int    $maximumBytes
 * @return array
 */
function bpParseCallbackRequest(array $server, $body, $maximumBytes = BP_MAX_CALLBACK_BODY_BYTES)
{
    $method = isset($server['REQUEST_METHOD']) && is_string($server['REQUEST_METHOD'])
        ? strtoupper($server['REQUEST_METHOD'])
        : '';
    if ($method !== 'POST') {
        return array('error' => 'Only POST requests are accepted.', 'status' => 405);
    }

    $contentType = isset($server['CONTENT_TYPE']) && is_string($server['CONTENT_TYPE'])
        ? $server['CONTENT_TYPE']
        : '';
    $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
    if ($mediaType !== 'application/json') {
        return array('error' => 'Content-Type must be application/json.', 'status' => 415);
    }

    if (isset($server['CONTENT_LENGTH'])) {
        $declaredLength = (string) $server['CONTENT_LENGTH'];
        if (!preg_match('/^\d+$/D', $declaredLength)) {
            return array('error' => 'Content-Length is invalid.', 'status' => 400);
        }
        if (strlen(ltrim($declaredLength, '0')) > strlen((string) $maximumBytes) ||
            (int) $declaredLength > $maximumBytes) {
            return array('error' => 'Callback request body is too large.', 'status' => 413);
        }
    }

    if (!is_string($body) || $body === '') {
        return array('error' => 'Callback request body is empty.', 'status' => 400);
    }
    if (strlen($body) > $maximumBytes) {
        return array('error' => 'Callback request body is too large.', 'status' => 413);
    }

    try {
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        return array('error' => 'Callback request body is not valid JSON.', 'status' => 400);
    }

    if (!is_array($payload)) {
        return array('error' => 'Callback JSON must be an object.', 'status' => 400);
    }

    return array('payload' => $payload);
}

/**
 * Interpret WHMCS yes/no gateway settings without treating arbitrary non-empty
 * strings (including "off") as enabled.
 *
 * @param mixed $value
 * @return bool
 */
function bpGatewayOptionEnabled($value)
{
    if ($value === true || $value === 1) {
        return true;
    }
    if (!is_string($value)) {
        return false;
    }

    return in_array(strtolower(trim($value)), array('1', 'on', 'yes', 'true', 'enabled'), true);
}

/**
 * Generate a short correlation ID suitable for response headers and logs.
 * It authenticates nothing and deliberately contains no invoice information.
 *
 * @return string
 */
function bpGenerateCallbackTraceId()
{
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable $exception) {
        return substr(hash('sha256', uniqid('btcpay-callback-', true)), 0, 16);
    }
}

/**
 * @param mixed $value
 * @param int   $maximumLength
 * @return string
 */
function bpSanitizeCallbackDiagnosticText($value, $maximumLength = 160)
{
    if (!is_scalar($value) || is_bool($value)) {
        return '[unavailable]';
    }

    $value = preg_replace('/[\x00-\x1F\x7F]/', '', (string) $value);
    if (!is_string($value) || $value === '') {
        return '[unavailable]';
    }

    return substr($value, 0, $maximumLength);
}

/**
 * Build a request summary for diagnostics without retaining the callback body,
 * cookies, forwarded headers, API credentials, or buyer data.
 *
 * REMOTE_ADDR is intentionally used instead of X-Forwarded-For because the
 * latter is attacker-controlled unless WHMCS's proxy chain is configured and
 * trusted separately.
 *
 * @param array $server
 * @param mixed $body
 * @return array
 */
function bpBuildCallbackRequestDiagnostics(array $server, $body)
{
    $remoteAddress = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR']) &&
        filter_var($server['REMOTE_ADDR'], FILTER_VALIDATE_IP) !== false
        ? $server['REMOTE_ADDR']
        : '[unavailable]';

    $diagnostics = array(
        'source_ip' => $remoteAddress,
        'method' => bpSanitizeCallbackDiagnosticText(
            isset($server['REQUEST_METHOD']) ? $server['REQUEST_METHOD'] : null,
            16
        ),
        'content_type' => bpSanitizeCallbackDiagnosticText(
            isset($server['CONTENT_TYPE']) ? $server['CONTENT_TYPE'] : null,
            128
        ),
        'declared_bytes' => bpSanitizeCallbackDiagnosticText(
            isset($server['CONTENT_LENGTH']) ? $server['CONTENT_LENGTH'] : null,
            24
        ),
        'received_bytes' => is_string($body) ? strlen($body) : '[unavailable]',
        'body_sha256' => is_string($body) ? hash('sha256', $body) : '[unavailable]',
    );

    return $diagnostics;
}

/**
 * Encode a controlled callback trace context as a single log-safe line.
 *
 * @param array $context
 * @return string
 */
function bpFormatCallbackDiagnosticContext(array $context)
{
    $safeContext = array();
    foreach ($context as $key => $value) {
        if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1) {
            continue;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            $safeContext[$key] = $value;
        } else {
            $safeContext[$key] = bpSanitizeCallbackDiagnosticText($value);
        }
    }

    try {
        return json_encode(
            $safeContext,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES
        );
    } catch (Throwable $exception) {
        return '{}';
    }
}
