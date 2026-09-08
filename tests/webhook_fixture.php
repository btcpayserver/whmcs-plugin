<?php

require_once __DIR__ . '/support.php';

use BTCPayWHMCS\Greenfield;
use BTCPayServer\Http\Response;

/** In-memory BTCPay endpoints; no real server or credentials are used. */
class WebhookFixtureHttp extends FixtureHttp
{
    public array $hooks;
    public int $creations = 0;
    public int $createStatus = 200;
    public int $deleteStatus = 200;
    public bool $loseCreateResponse = false;
    public bool $omitCreatedSecret = false;
    public array $getFailures = [];
    public array $permissions = ['btcpay.store.cancreateinvoice:STORE-1', 'btcpay.store.canviewinvoices:STORE-1',
        Greenfield::WEBHOOK_PERMISSION . ':STORE-1'];
    public $beforeDelete = null;

    public function __construct(string $callbackUrl, string $oldId = 'OLD')
    {
        $this->hooks = [$oldId => ['id' => $oldId, 'url' => $callbackUrl, 'enabled' => true],
            'OTHER' => ['id' => 'OTHER', 'url' => 'https://another.example.test/callback', 'enabled' => true]];
        parent::__construct(function ($method, $url, $body) {
            if (str_ends_with($url, '/api-keys/current')) { return ['permissions' => $this->permissions]; }
            if (str_contains($url, '/invoices?')) { return []; }
            $id = basename($url);
            if ($method === 'GET') {
                if (isset($this->getFailures[$id])) { return new Response($this->getFailures[$id], 'sensitive-response', []); }
                return $id === 'webhooks' ? array_values($this->hooks)
                    : ($this->hooks[$id] ?? new Response(404, 'Not found', []));
            }
            if ($method === 'DELETE') {
                if ($this->beforeDelete) { ($this->beforeDelete)($id); }
                if (in_array($this->deleteStatus, [200, 404], true)) { unset($this->hooks[$id]); }
                return new Response($this->deleteStatus, '', []);
            }
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if ($method === 'POST') {
                testSame(false, array_key_exists('secret', $data), 'BTCPay generates every replacement secret');
                testSame(true, $data['enabled'], 'Replacement is enabled');
                testSame(true, $data['automaticRedelivery'], 'Replacement enables redelivery');
                testSame(Greenfield::WEBHOOK_EVENTS, $data['authorizedEvents']['specificEvents'], 'Replacement retains lifecycle subscriptions');
                if ($this->createStatus !== 200) { return new Response($this->createStatus, 'sensitive-response', []); }
                $id = 'NEW-' . ++$this->creations;
                $this->hooks[$id] = ['id' => $id] + $data;
                if ($this->loseCreateResponse) { throw new RuntimeException('sensitive-transport-error'); }
                return $this->hooks[$id] + ($this->omitCreatedSecret ? [] : ['secret' => 'generated-secret-' . $this->creations]);
            }
            testSame('PUT', $method, 'Uses only documented webhook operations');
            unset($data['secret']);
            return $this->hooks[$id] = ['id' => $id] + $data;
        });
    }
}
