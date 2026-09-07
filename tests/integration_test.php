<?php

require __DIR__ . '/support.php';

use BTCPayWHMCS\Greenfield;
use Illuminate\Database\Capsule\Manager as Capsule;

if (!getenv('BTCPAY_TEST_DB_HOST')) {
    fwrite(STDERR, "Set BTCPAY_TEST_DB_HOST, BTCPAY_TEST_DB_PORT and BTCPAY_TEST_DB_PASSWORD for a disposable MySQL database named btcpay_test.\n");
    exit(1);
}
class_alias(Capsule::class, 'WHMCS\Database\Capsule');
$capsule = new Capsule();
// Random prefixes isolate this run, including its cleanup, from all other data.
$capsule->addConnection([
    'driver' => 'mysql', 'host' => getenv('BTCPAY_TEST_DB_HOST'),
    'port' => getenv('BTCPAY_TEST_DB_PORT') ?: '3306', 'database' => 'btcpay_test',
    'username' => 'root', 'password' => getenv('BTCPAY_TEST_DB_PASSWORD'),
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => 'gf_' . bin2hex(random_bytes(6)) . '_',
]);
$capsule->setAsGlobal();

// Only the WHMCS accounting boundary is substituted. Production code uses the
// real Illuminate query builder, MySQL transactions and SELECT FOR UPDATE.
function addInvoicePayment($invoiceId, $transactionId, $amount, $fee, $gateway): void
{
    Capsule::table('tblaccounts')->insert(['invoiceid' => $invoiceId, 'transid' => $transactionId, 'amountin' => $amount]);
    Capsule::table('tblinvoices')->where('id', $invoiceId)->update(['status' => 'Paid']);
}

$tables = ['tblaccounts', 'tblinvoices', 'tblclients', 'tblcurrencies', BP_INVOICE_CONTRACT_TABLE];
try {
    Capsule::schema()->create('tblcurrencies', function ($table) {
        $table->increments('id'); $table->string('code');
    });
    Capsule::schema()->create('tblclients', function ($table) {
        $table->increments('id'); $table->integer('currency');
    });
    Capsule::schema()->create('tblinvoices', function ($table) {
        $table->increments('id'); $table->integer('userid'); $table->string('total');
        $table->string('status'); $table->string('paymentmethod');
    });
    Capsule::schema()->create('tblaccounts', function ($table) {
        $table->increments('id'); $table->integer('invoiceid'); $table->string('transid'); $table->string('amountin');
    });
    // Reproduce the released v3.3 table, deliberately without connection_key.
    Capsule::schema()->create(BP_INVOICE_CONTRACT_TABLE, function ($table) {
        $table->increments('id');
        $table->string('btcpay_invoice_id', 128); $table->string('btcpay_invoice_hash', 64)->unique();
        $table->integer('whmcs_invoice_id');
        foreach (['whmcs_amount', 'whmcs_currency', 'btcpay_amount', 'btcpay_currency', 'status'] as $field) {
            $table->string($field);
        }
        $table->dateTime('created_at'); $table->dateTime('updated_at'); $table->dateTime('processed_at')->nullable();
    });
    Capsule::table('tblcurrencies')->insert(['id' => 1, 'code' => 'EUR']);
    Capsule::table('tblclients')->insert(['id' => 1, 'currency' => 1]);
    Capsule::table('tblinvoices')->insert(['id' => 42, 'userid' => 1, 'total' => '9', 'status' => 'Unpaid', 'paymentmethod' => 'btcpay']);
    $legacy = testContract();
    unset($legacy['connection_key']);
    $legacy += ['btcpay_invoice_hash' => hash('sha256', 'BTCPAY-123'), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
    Capsule::table(BP_INVOICE_CONTRACT_TABLE)->insert($legacy);
    bpEnsureInvoiceContractTable();
    testSame(true, Capsule::schema()->hasColumn(BP_INVOICE_CONTRACT_TABLE, 'connection_key'), 'Upgrades the legacy schema');
    testSame(1, Capsule::table(BP_INVOICE_CONTRACT_TABLE)->count(), 'Preserves old records');
    $event = ['invoice_id' => 'BTCPAY-OTHER', 'event_type' => 'InvoiceSettled'];
    $unrelated = new Greenfield(testSettings(), new FixtureHttp(fn () => ['id' => 'BTCPAY-OTHER', 'metadata' => null, 'amount' => null]));
    testSame('unmapped_invoice_ignored', bpProcessWebhook($unrelated, $event)['outcome'], 'Store-wide webhook ignores unrelated invoices without WHMCS metadata');
    testSame('event_ignored', bpProcessWebhook($unrelated, ['ignored' => true])['outcome'], 'Acknowledges unrelated event types');
    $early = new Greenfield(testSettings(), new FixtureHttp(fn () => ['id' => 'BTCPAY-OTHER', 'metadata' => ['integration' => 'whmcs']]));
    testThrows(fn () => bpProcessWebhook($early, $event), 'Retries a webhook before its mapping commits', 503);
    testSame(0, Capsule::table('tblaccounts')->count(), 'Unmapped invoices never credit WHMCS');
    $remote = testInvoice();
    $http = new FixtureHttp(function () use (&$remote) {
        testSame(true, Capsule::connection()->transactionLevel() > 0, 'Fetches payment state within the locked transaction');
        return $remote;
    });
    $client = new Greenfield(testSettings(), $http);
    $result = bpSynchronizeInvoice($client, 'BTCPAY-123');
    testSame('awaiting_payment', $result['outcome'], 'Legacy pending invoice resumes through Greenfield');
    testSame($client->connectionKey(), bpFindInvoiceContract('BTCPAY-123')->connection_key, 'Binds validated legacy record');
    $remote['status'] = 'Settled';
    $result = bpSynchronizeInvoice($client, 'BTCPAY-123');
    testSame('payment_applied', $result['outcome'], 'Recovers a missed settlement');
    testSame('9', Capsule::table('tblaccounts')->first()->amountin, 'Credits the original WHMCS currency amount');
    testSame('duplicate_callback', bpSynchronizeInvoice($client, 'BTCPAY-123')['outcome'], 'Webhook after reconciliation is idempotent');
    testSame(1, Capsule::table('tblaccounts')->count(), 'Does not credit twice');
    $remote['status'] = 'Invalid';
    Capsule::table('tblinvoices')->where('id', 42)->update(['total' => '100']);
    testSame(true, bpSynchronizeInvoice($client, 'BTCPAY-123')['manual_review'], 'Flags invalidation despite edited WHMCS bill');
    testSame(1, Capsule::table('tblaccounts')->count(), 'Never reverses automatically');

    Capsule::table('tblinvoices')->where('id', 42)->update(['total' => '9', 'status' => 'Unpaid']);
    Capsule::table('tblaccounts')->delete();
    Capsule::table(BP_INVOICE_CONTRACT_TABLE)->update(['processed_at' => null, 'status' => 'new']);
    $remote['status'] = 'Settled';
    $remote['amount'] = '0.01';
    testThrows(fn () => bpSynchronizeInvoice($client, 'BTCPAY-123'), 'Mismatch rolls back', 409);
    testSame(0, Capsule::table('tblaccounts')->count(), 'Mismatch creates no transaction');
    testSame(null, bpFindInvoiceContract('BTCPAY-123')->processed_at, 'Mismatch leaves contract uncredited');
    $remote['amount'] = '10.00';
    foreach (['PaidPartial', 'Invalid', 'FutureStatus', null] as $additional) {
        $remote['additionalStatus'] = $additional;
        testThrows(fn () => bpSynchronizeInvoice($client, 'BTCPAY-123'), 'Unsupported settlement rolls back', 409);
        testSame(0, Capsule::table('tblaccounts')->count(), 'Unsupported settlement cannot write to the payment ledger');
        testSame(null, bpFindInvoiceContract('BTCPAY-123')->processed_at, 'Unsupported settlement stays uncredited');
        testSame('Unpaid', Capsule::table('tblinvoices')->where('id', 42)->value('status'), 'Unsupported settlement leaves WHMCS invoice unpaid');
    }
    unset($remote['additionalStatus']);
    testThrows(fn () => bpSynchronizeInvoice($client, 'BTCPAY-123'), 'Missing additional status cannot settle', 409);
    testSame(0, Capsule::table('tblaccounts')->count(), 'Missing additional status cannot write to the ledger');
    $remote['additionalStatus'] = 'None';
    $other = testSettings(); $other['storeId'] = 'STORE-2';
    testThrows(fn () => bpSynchronizeInvoice(new Greenfield($other, $http), 'BTCPAY-123'), 'Connection changes fail closed', 409);

    if (function_exists('pcntl_fork')) {
        // Two independent connections race to settle the same invoice.
        Capsule::connection()->disconnect();
        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) { throw new RuntimeException('Unable to fork concurrency test'); }
            if ($pid === 0) {
                try {
                    $parallelClient = new Greenfield(testSettings(), new FixtureHttp(function () {
                        usleep(150000);
                        return array_replace(testInvoice(), ['status' => 'Settled']);
                    }));
                    bpSynchronizeInvoice($parallelClient, 'BTCPAY-123');
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite(STDERR, $exception->getMessage() . "\n");
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $childStatus);
            testSame(0, pcntl_wexitstatus($childStatus), 'Concurrent worker succeeded');
        }
        testSame(1, Capsule::table('tblaccounts')->count(), 'Concurrent settlement credits once');
        testSame('settled', bpFindInvoiceContract('BTCPAY-123')->status, 'Concurrent callbacks preserve settlement');
    }
    require __DIR__ . '/settings_integration.php';
    echo "MySQL migration, reconciliation, accounting and concurrency tests passed.\n";
} finally {
    foreach ($tables as $table) {
        Capsule::schema()->dropIfExists($table);
    }
}
