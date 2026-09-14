<?php

namespace BTCPayWHMCS;

use BTCPayServer\Client\ApiKey;
use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Client\Webhook;
use BTCPayServer\Exception\RequestException;
use BTCPayServer\Http\ClientInterface;
use BTCPayServer\Http\CurlClient;
use BTCPayServer\Util\PreciseNumber;

class GatewayException extends \RuntimeException
{
    public function __construct(string $message, public int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}

/** WHMCS policy and error handling around the official Greenfield clients. */
class Greenfield
{
    public const WEBHOOK_PERMISSION = 'btcpay.store.webhooks.canmodifywebhooks';
    public const WEBHOOK_EVENTS = ['InvoiceCreated', 'InvoiceReceivedPayment', 'InvoicePaymentSettled',
        'InvoiceProcessing', 'InvoiceSettled', 'InvoiceExpired', 'InvoiceInvalid'];
    private Invoice $invoices;
    private ApiKey $keys;
    private Webhook $webhooks;
    public string $baseUrl;
    public string $storeId;
    private string $secret;
    private bool $sendCustomerEmail;

    public function __construct(array $settings, ?ClientInterface $http = null, bool $requireWebhook = true)
    {
        \btcpayLoadDependencies();
        $this->baseUrl = \bpNormalizeConfiguredBaseUrl($settings['btcpayUrl'] ?? null);
        $this->storeId = self::setting($settings, 'storeId', 'Store ID');
        if (!\bpIsValidInvoiceIdentifier($this->storeId)) {
            throw new GatewayException('Store ID is invalid.', 503);
        }
        $key = self::setting($settings, 'apiKey', 'Greenfield API Key');
        $this->secret = $requireWebhook || !empty($settings['webhookSecret'])
            ? self::setting($settings, 'webhookSecret', 'Webhook Secret') : '';
        $this->sendCustomerEmail = \bpGatewayOptionEnabled($settings['sendCustomerEmail'] ?? null);
        if (preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new GatewayException('Greenfield API Key is invalid.', 503);
        }
        self::speedPolicy($settings['transactionSpeed'] ?? 'medium');
        foreach (['btcpayUrlTor', 'redirectURL'] as $field) {
            if (!empty($settings[$field])) {
                \bpValidateAbsoluteHttpUrl($settings[$field], $field === 'redirectURL');
            }
        }
        if ($http === null) {
            $http = new CurlClient();
            $http->setCurlOptions([
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'BTCPay-WHMCS/' . BTCPAY_WHMCS_VERSION,
            ]);
        }
        $this->invoices = new Invoice($this->baseUrl, $key, $http);
        $this->keys = new ApiKey($this->baseUrl, $key, $http);
        $this->webhooks = new Webhook($this->baseUrl, $key, $http);
    }

    private static function setting(array $settings, string $name, string $label): string
    {
        $value = $settings[$name] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > 1024) {
            throw new GatewayException($label . ' is required. Complete the Greenfield gateway configuration.', 503);
        }
        return trim($value);
    }

    public function connectionKey(): string
    {
        // Credentials can rotate without changing the identity of this store.
        return hash('sha256', $this->baseUrl . "\n" . $this->storeId);
    }

    public static function speedPolicy($value): ?string
    {
        $policies = ['default' => null, 'high' => 'HighSpeed', 'medium' => 'MediumSpeed',
            'lowmedium' => 'LowMediumSpeed', 'low' => 'LowSpeed'];
        if (!is_string($value) || !array_key_exists(strtolower(trim($value)), $policies)) {
            throw new GatewayException('Transaction Speed is invalid.', 503);
        }
        return $policies[strtolower(trim($value))];
    }

    private function request(callable $request)
    {
        try {
            return $request();
        } catch (RequestException $exception) {
            // SDK exception messages contain response bodies. Never expose or log them.
            $status = (int) $exception->getCode();
            $hint = match ($status) {
                401 => 'Greenfield API key rejected. Replace the legacy key with an account API key.',
                403 => 'The API key cannot perform this operation for the selected store.',
                404 => 'BTCPay store or invoice not found. Check the Store ID and server URL.',
                default => 'BTCPay returned HTTP ' . $status . '. Check the store configuration.',
            };
            throw new GatewayException($hint);
        } catch (\Throwable $exception) {
            throw new GatewayException('Unable to read the BTCPay API response. Check connectivity and server configuration.');
        }
    }

    public function checkConnection(bool $manageWebhooks = false): void
    {
        $permissions = $this->request(fn () => $this->keys->getCurrent()->getPermissions());
        $required = ['btcpay.store.cancreateinvoice', 'btcpay.store.canviewinvoices'];
        if ($manageWebhooks) {
            $required[] = self::WEBHOOK_PERMISSION;
        }
        foreach ($required as $permission) {
            if (!self::grants($permissions, $permission, $this->storeId)) {
                throw new GatewayException('API key is missing ' . $permission . ' for the selected store.', 503);
            }
        }
        // Do not require store-settings permissions just to test the connection.
        $this->request(fn () => $this->invoices->getAllInvoices($this->storeId, 1, 0));
    }

    public static function grants(array $permissions, string $required, string $storeId): bool
    {
        $parents = [
            'btcpay.store.cancreateinvoice' => ['btcpay.store.canmodifyinvoices', 'btcpay.store.canmodifystoresettings'],
            'btcpay.store.canviewinvoices' => ['btcpay.store.canmodifyinvoices', 'btcpay.store.canviewstoresettings', 'btcpay.store.canmodifystoresettings'],
            self::WEBHOOK_PERMISSION => ['btcpay.store.canmodifystoresettings'],
        ];
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                continue;
            }
            if ($permission === 'unrestricted') {
                return true;
            }
            [$name, $scope] = array_pad(explode(':', $permission, 2), 2, null);
            if (in_array($name, array_merge([$required], $parents[$required] ?? []), true) &&
                ($scope === null || hash_equals($storeId, $scope))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reuse only a locally owned webhook, or adopt a manual webhook whose secret
     * the administrator supplied. Never delete or change another integration's hook.
     * The returned secret must be persisted securely; never log this result.
     */
    public function provisionWebhook(string $callbackUrl, ?array $saved = null): array
    {
        \bpValidateAbsoluteHttpUrl($callbackUrl, true);
        $existing = null;
        $secret = '';
        if ($saved !== null && ($saved['connection_key'] ?? '') === $this->connectionKey()) {
            if (!\bpIsValidInvoiceIdentifier($saved['id'] ?? null)) {
                throw new GatewayException('Saved webhook ID is invalid. Review the webhook configuration.', 503);
            }
            $secret = self::setting($saved, 'secret', 'Saved Webhook Secret');
            $existing = $this->getWebhookIfExists($saved['id']);
            if ($existing !== null && (($existing['id'] ?? '') !== $saved['id'] ||
                !in_array($existing['url'] ?? null, [$saved['url'] ?? null, $callbackUrl], true))) {
                throw new GatewayException('The saved webhook was changed in BTCPay. Review it before reconnecting.', 409);
            }
        }
        if ($existing === null) {
            $matches = [];
            $hooks = $this->request(fn () => $this->webhooks->getStoreWebhooks($this->storeId)->getData());
            foreach ($hooks as $hook) {
                if (is_array($hook) && ($hook['url'] ?? null) === $callbackUrl) {
                    $matches[] = $hook;
                }
            }
            if ($matches !== []) {
                // A timed-out creation may have succeeded remotely. Do not create
                // duplicates or silently rotate a secret we could not save locally.
                if (count($matches) !== 1 || $this->secret === '' || $saved !== null) {
                    throw new GatewayException('A webhook already targets this callback URL, but it cannot be adopted automatically. Use the confirmed Re-register webhook action in connection and recovery. Multiple matching webhooks need manual review.', 409);
                }
                $existing = $matches[0];
                $secret = $this->secret;
            }
        }
        if ($existing !== null) {
            if (!\bpIsValidInvoiceIdentifier($existing['id'] ?? null)) {
                throw new GatewayException('BTCPay returned an invalid webhook ID.');
            }
            // Keep the secret explicitly, including on older BTCPay releases.
            $this->request(fn () => $this->webhooks->updateWebhook($this->storeId, $callbackUrl,
                $existing['id'], self::WEBHOOK_EVENTS, true, true, $secret));
            $id = $existing['id'];
        } else {
            return $this->createManagedWebhook($callbackUrl);
        }
        return ['id' => $id, 'secret' => $secret, 'url' => $callbackUrl, 'connection_key' => $this->connectionKey()];
    }

    private function getWebhookIfExists(string $id): ?array
    {
        return $this->request(function () use ($id) {
            try {
                return $this->webhooks->getWebhook($this->storeId, $id)->getData();
            } catch (RequestException $exception) {
                if ((int) $exception->getCode() === 404) {
                    return null;
                }
                throw $exception;
            }
        });
    }

    private function createManagedWebhook(string $callbackUrl): array
    {
        // Like WooCommerce, ask BTCPay to generate the secret by passing null.
        $created = $this->request(fn () => $this->webhooks->createWebhook($this->storeId,
            $callbackUrl, self::WEBHOOK_EVENTS, null, true, true)->getData());
        if (!\bpIsValidInvoiceIdentifier($created['id'] ?? null) || ($created['url'] ?? null) !== $callbackUrl) {
            throw new GatewayException('BTCPay returned an invalid webhook. Review the store webhooks before saving again.');
        }
        return ['id' => $created['id'],
            'secret' => self::setting($created, 'secret', 'BTCPay-generated Webhook Secret'),
            'url' => $callbackUrl, 'connection_key' => $this->connectionKey()];
    }

    /**
     * Explicit admin confirmation only, never an ordinary settings save.
     * Create first; the caller MUST commit the new secret before deleting the old hook.
     * A missing old secret or stale/deleted ID does not prevent explicit recovery.
     */
    public function prepareWebhookReplacement(string $callbackUrl, ?array $saved = null): array
    {
        \bpValidateAbsoluteHttpUrl($callbackUrl, true);
        $candidates = [];
        if (($saved['connection_key'] ?? '') === $this->connectionKey() &&
            \bpIsValidInvoiceIdentifier($saved['id'] ?? null)) {
            $existing = $this->getWebhookIfExists($saved['id']);
            if ($existing !== null) {
                if (($existing['id'] ?? '') !== $saved['id'] ||
                    !in_array($existing['url'] ?? null, [$saved['url'] ?? null, $callbackUrl], true)) {
                    throw new GatewayException('The saved webhook was changed in BTCPay. Review it before replacing it.', 409);
                }
                $candidates[$existing['id']] = $existing;
            }
        }
        $hooks = $this->request(fn () => $this->webhooks->getStoreWebhooks($this->storeId)->getData());
        foreach ($hooks as $hook) {
            if (is_array($hook) && ($hook['url'] ?? null) === $callbackUrl) {
                if (!\bpIsValidInvoiceIdentifier($hook['id'] ?? null)) {
                    throw new GatewayException('BTCPay returned an invalid webhook ID.');
                }
                $candidates[$hook['id']] = $hook;
            }
        }
        if (count($candidates) > 1) {
            throw new GatewayException('Multiple webhooks match this WHMCS connection. Review them in BTCPay before re-registering; no webhook was replaced.', 409);
        }
        $previous = $candidates === [] ? null : array_values($candidates)[0];
        if ($previous !== null) {
            $previous = ['id' => $previous['id'], 'url' => \bpValidateAbsoluteHttpUrl($previous['url']),
                'connection_key' => $this->connectionKey()];
        }
        $webhook = $this->createManagedWebhook($callbackUrl);
        if ($previous !== null && $previous['id'] === $webhook['id']) {
            throw new GatewayException('BTCPay did not return a new webhook ID. Review the store webhooks before retrying.');
        }
        return ['webhook' => $webhook, 'previous' => $previous];
    }

    /** Delete only the resolved old hook, AFTER its replacement was committed locally. */
    public function deleteReplacedWebhook(array $previous, array $replacement): void
    {
        foreach ([$previous, $replacement] as $hook) {
            if (($hook['connection_key'] ?? '') !== $this->connectionKey() ||
                !\bpIsValidInvoiceIdentifier($hook['id'] ?? null)) {
                throw new GatewayException('Webhook cleanup does not match the selected connection.', 409);
            }
            \bpValidateAbsoluteHttpUrl($hook['url'] ?? null);
        }
        if ($previous['id'] === $replacement['id']) {
            throw new GatewayException('The active webhook cannot be deleted during cleanup.', 409);
        }
        $active = $this->getWebhookIfExists($replacement['id']);
        if ($active === null || ($active['id'] ?? '') !== $replacement['id'] ||
            ($active['url'] ?? '') !== $replacement['url'] || ($active['enabled'] ?? false) !== true) {
            throw new GatewayException('The replacement webhook is missing or changed. The previous webhook was not deleted.', 409);
        }
        $old = $this->getWebhookIfExists($previous['id']);
        if ($old === null) {
            return;
        }
        if (($old['id'] ?? '') !== $previous['id'] || ($old['url'] ?? '') !== $previous['url']) {
            throw new GatewayException('The previous webhook was changed in BTCPay. Review it before deleting it.', 409);
        }
        $this->request(function () use ($previous) {
            try {
                $this->webhooks->deleteWebhook($this->storeId, $previous['id']);
            } catch (RequestException $exception) {
                if ((int) $exception->getCode() !== 404) {
                    throw $exception;
                }
            }
        });
    }

    public function createInvoice(
        int $id,
        string $amount,
        string $currency,
        string $returnUrl,
        string $orderUrl,
        $speed,
        ?string $buyerEmail = null
    ): array
    {
        if ($this->secret === '') {
            throw new GatewayException('Webhook setup is incomplete. Save the BTCPay gateway settings again.', 503);
        }
        $options = new InvoiceCheckoutOptions();
        $options->setSpeedPolicy(self::speedPolicy($speed));
        $options->setRedirectURL($returnUrl);
        $options->setRedirectAutomatically(true);
        // A WHMCS invoice must be paid in full. Do not inherit a store's underpayment tolerance.
        $options->setPaymentTolerance(0);
        // Enforce the saved opt-in at the API boundary, even if a caller supplies an email.
        $buyerEmail = $this->sendCustomerEmail ? trim($buyerEmail ?? '') : null;
        $result = $this->request(fn () => $this->invoices->createInvoice(
            $this->storeId, $currency, PreciseNumber::parseString($amount), (string) $id, $buyerEmail,
            ['orderUrl' => $orderUrl, 'itemDesc' => 'WHMCS invoice #' . $id, 'integration' => 'whmcs'],
            $options
        ));
        return $this->validateInvoice($result->getData());
    }

    public function getInvoice(string $id): array
    {
        if (!\bpIsValidInvoiceIdentifier($id)) {
            throw new GatewayException('Invalid BTCPay invoice ID.', 400);
        }
        $data = $this->request(fn () => $this->invoices->getInvoice($this->storeId, $id))->getData();
        if (!isset($data['id']) || !is_string($data['id']) || !hash_equals($id, $data['id'])) {
            throw new GatewayException('BTCPay returned a different invoice ID.');
        }
        if (isset($data['storeId']) && $data['storeId'] !== $this->storeId) {
            throw new GatewayException('BTCPay returned an invoice for a different store.');
        }
        return $data;
    }

    private function validateInvoice(array $data): array
    {
        $error = \bpValidateBtcpayInvoiceResponseData($data);
        if ($error !== null) {
            throw new GatewayException($error);
        }
        if (isset($data['storeId']) && $data['storeId'] !== $this->storeId) {
            throw new GatewayException('BTCPay returned an invoice for a different store.');
        }
        return $data;
    }

    public function webhook(array $server, string $body): array
    {
        if ($this->secret === '') {
            throw new GatewayException('Webhook setup is incomplete. Save the BTCPay gateway settings again.', 503);
        }
        $parsed = \bpParseCallbackRequest($server, $body);
        if (isset($parsed['error'])) {
            throw new GatewayException($parsed['error'], $parsed['status']);
        }
        $signature = $server['HTTP_BTCPAY_SIG'] ?? '';
        if (!is_string($signature) || !Webhook::isIncomingWebhookRequestValid($body, $signature, $this->secret)) {
            throw new GatewayException('Missing or invalid BTCPay-Sig signature.', 401);
        }
        $event = $parsed['payload'];
        if (!isset($event['storeId']) || $event['storeId'] !== $this->storeId) {
            throw new GatewayException('Webhook belongs to a different store.', 409);
        }
        if (!isset($event['type']) || !is_string($event['type']) ||
            !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $event['type'])) {
            throw new GatewayException('Invalid webhook event type.', 400);
        }
        if (strpos($event['type'], 'Invoice') !== 0) {
            return ['ignored' => true];
        }
        if (!\bpIsValidInvoiceIdentifier($event['invoiceId'] ?? null)) {
            throw new GatewayException('Invalid webhook invoice ID.', 400);
        }
        return ['invoice_id' => $event['invoiceId'], 'event_type' => $event['type']];
    }
}
