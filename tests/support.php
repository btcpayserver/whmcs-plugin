<?php

require_once __DIR__ . '/../modules/gateways/btcpay/bootstrap.php';
btcpayLoadDependencies();

use BTCPayServer\Http\ClientInterface;
use BTCPayServer\Http\Response;
use BTCPayServer\Http\ResponseInterface;

function testSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . var_export($actual, true));
    }
}

function testThrows(callable $callback, string $message, ?int $httpStatus = null): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($httpStatus !== null) {
            testSame($httpStatus, $exception->httpStatus ?? null, $message);
        }
        return;
    }
    throw new RuntimeException($message);
}

function testSettings(): array
{
    return ['btcpayUrl' => 'https://pay.example.test/btcpay/', 'apiKey' => 'test-greenfield-key',
        'storeId' => 'STORE-1', 'webhookSecret' => 'test-webhook-secret', 'transactionSpeed' => 'medium'];
}

function testInvoice(): array
{
    return ['id' => 'BTCPAY-123', 'amount' => '10.00', 'currency' => 'USD', 'status' => 'New',
        'additionalStatus' => 'None', 'type' => 'Standard', 'metadata' => ['orderId' => '42'],
        'checkoutLink' => 'https://pay.example.test/btcpay/i/BTCPAY-123'];
}

function testContract(): array
{
    return ['btcpay_invoice_id' => 'BTCPAY-123', 'whmcs_invoice_id' => 42,
        'whmcs_amount' => '9.00', 'whmcs_currency' => 'EUR', 'btcpay_amount' => '10.00',
        'btcpay_currency' => 'USD', 'status' => 'new', 'processed_at' => null, 'connection_key' => null];
}

class FixtureHttp implements ClientInterface
{
    public array $requests = [];
    public $respond;

    public function __construct(?callable $respond = null)
    {
        $this->respond = $respond ?? fn () => testInvoice();
    }

    public function request(string $method, string $url, array $headers = [], string $body = ''): ResponseInterface
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $data = ($this->respond)($method, $url, $body);
        return $data instanceof ResponseInterface ? $data : new Response(200, json_encode($data, JSON_THROW_ON_ERROR), []);
    }
}
