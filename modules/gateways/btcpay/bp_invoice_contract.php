<?php

use WHMCS\Database\Capsule;

const BP_INVOICE_CONTRACT_TABLE = 'mod_btcpay_invoice_contracts';
const BP_DECIMAL_SCALE = 18;
const BP_CURRENCY_AMOUNT_SCALE = 8;

/**
 * Convert a regular or scientific-notation decimal to a canonical string.
 *
 * @param mixed $value
 * @return string
 */
function bpNormalizeDecimal($value)
{
    if (is_bool($value) || $value === null || (!is_scalar($value))) {
        throw new InvalidArgumentException('Decimal value must be a scalar number.');
    }

    $value = trim((string) $value);
    if ($value === '' || strlen($value) > 128) {
        throw new InvalidArgumentException('Decimal value is empty or too long.');
    }

    $pattern = '/^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/D';
    if (!preg_match($pattern, $value, $matches, PREG_UNMATCHED_AS_NULL)) {
        throw new InvalidArgumentException('Invalid decimal value.');
    }

    $sign = $matches[1] === '-' ? '-' : '';
    $integer = $matches[2] !== null ? $matches[2] : '0';
    $fraction = $matches[2] !== null ? ($matches[3] ?? '') : ($matches[4] ?? '');
    $exponent = isset($matches[5]) && $matches[5] !== null ? (int) $matches[5] : 0;

    if (abs($exponent) > 100) {
        throw new InvalidArgumentException('Decimal exponent is out of range.');
    }

    $digits = $integer . $fraction;
    $decimalPosition = strlen($integer) + $exponent;

    if ($decimalPosition <= 0) {
        $integer = '0';
        $fraction = str_repeat('0', -$decimalPosition) . $digits;
    } elseif ($decimalPosition >= strlen($digits)) {
        $integer = $digits . str_repeat('0', $decimalPosition - strlen($digits));
        $fraction = '';
    } else {
        $integer = substr($digits, 0, $decimalPosition);
        $fraction = substr($digits, $decimalPosition);
    }

    $integer = ltrim($integer, '0');
    $integer = $integer === '' ? '0' : $integer;
    $fraction = rtrim($fraction, '0');

    if ($integer === '0' && $fraction === '') {
        return '0';
    }

    $normalized = $sign . $integer;
    if ($fraction !== '') {
        $normalized .= '.' . $fraction;
    }

    if (strlen($normalized) > 128) {
        throw new InvalidArgumentException('Normalized decimal value is too long.');
    }

    return $normalized;
}

/**
 * @param mixed $left
 * @param mixed $right
 * @return bool
 */
function bpDecimalEquals($left, $right)
{
    return bpNormalizeDecimal($left) === bpNormalizeDecimal($right);
}

/**
 * @param mixed $value
 * @return bool
 */
function bpDecimalIsPositive($value)
{
    $value = bpNormalizeDecimal($value);

    return $value !== '0' && $value[0] !== '-';
}

/**
 * Convert a WHMCS amount between currencies using exact decimal arithmetic.
 *
 * WHMCS rates are relative to the base currency, so the conversion is:
 * amount / current rate * target rate. Results are rounded half-up to eight
 * decimal places, matching the maximum precision this legacy integration sends
 * to BTCPay.
 *
 * @param mixed $amount
 * @param mixed $currentRate
 * @param mixed $targetRate
 * @return string
 */
function bpConvertCurrencyAmount($amount, $currentRate, $targetRate)
{
    if (!function_exists('bcmul') || !function_exists('bcdiv') || !function_exists('bcadd')) {
        throw new RuntimeException('The bcmath PHP extension is required for exact currency conversion.');
    }

    $amount = bpNormalizeDecimal($amount);
    $currentRate = bpNormalizeDecimal($currentRate);
    $targetRate = bpNormalizeDecimal($targetRate);

    if (!bpDecimalIsPositive($amount)) {
        throw new InvalidArgumentException('Invoice amount must be greater than zero.');
    }
    if (!bpDecimalIsPositive($currentRate)) {
        throw new InvalidArgumentException('Current currency rate must be greater than zero.');
    }
    if (!bpDecimalIsPositive($targetRate)) {
        throw new InvalidArgumentException('Target currency rate must be greater than zero.');
    }

    $workingScale = BP_DECIMAL_SCALE + 14;
    $unrounded = bcdiv(
        bcmul($amount, $targetRate, $workingScale),
        $currentRate,
        BP_CURRENCY_AMOUNT_SCALE + 1
    );

    $roundingIncrement = '0.' . str_repeat('0', BP_CURRENCY_AMOUNT_SCALE) . '5';
    $converted = bcadd($unrounded, $roundingIncrement, BP_CURRENCY_AMOUNT_SCALE);

    return bpNormalizeDecimal($converted);
}

/**
 * @param mixed $currency
 * @return string
 */
function bpNormalizeCurrencyCode($currency)
{
    if (!is_string($currency)) {
        throw new InvalidArgumentException('Currency code must be a string.');
    }

    $currency = strtoupper(trim($currency));
    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,15}$/D', $currency)) {
        throw new InvalidArgumentException('Invalid currency code.');
    }

    return $currency;
}

/**
 * Validate authenticated BTCPay invoice data against a stored contract.
 *
 * @param array              $invoiceData
 * @param array|object       $contract
 * @param bool               $requireStatus
 * @return string|null       A validation error, or null when valid.
 */
function bpValidateBtcpayInvoiceContract(array $invoiceData, $contract, $requireStatus = true)
{
    $contract = (array) $contract;
    $requiredData = array('id', 'orderId', 'price', 'currency');
    if ($requireStatus) {
        $requiredData[] = 'status';
    }

    foreach ($requiredData as $field) {
        if (!array_key_exists($field, $invoiceData)) {
            return 'BTCPay invoice response is missing ' . $field . '.';
        }
    }

    $requiredContract = array(
        'btcpay_invoice_id',
        'whmcs_invoice_id',
        'btcpay_amount',
        'btcpay_currency',
    );
    foreach ($requiredContract as $field) {
        if (!array_key_exists($field, $contract)) {
            return 'Stored invoice contract is missing ' . $field . '.';
        }
    }

    if (!is_scalar($invoiceData['id']) ||
        !hash_equals((string) $contract['btcpay_invoice_id'], (string) $invoiceData['id'])) {
        return 'BTCPay invoice ID does not match the stored invoice contract.';
    }

    if (!is_scalar($invoiceData['orderId']) ||
        (string) $contract['whmcs_invoice_id'] !== (string) $invoiceData['orderId']) {
        return 'BTCPay order ID does not match the stored WHMCS invoice ID.';
    }

    try {
        if (!bpDecimalEquals($contract['btcpay_amount'], $invoiceData['price'])) {
            return 'BTCPay amount does not match the stored invoice contract.';
        }
        if (bpNormalizeCurrencyCode($contract['btcpay_currency']) !==
            bpNormalizeCurrencyCode($invoiceData['currency'])) {
            return 'BTCPay currency does not match the stored invoice contract.';
        }
    } catch (InvalidArgumentException $exception) {
        return 'BTCPay invoice response contains an invalid amount or currency.';
    }

    if ($requireStatus && (!is_string($invoiceData['status']) || trim($invoiceData['status']) === '')) {
        return 'BTCPay invoice response contains an invalid status.';
    }

    return null;
}

/**
 * @param array        $invoiceData
 * @param int|string   $whmcsInvoiceId
 * @param mixed        $expectedAmount
 * @param string       $expectedCurrency
 * @return string|null
 */
function bpValidateCreatedInvoiceData(
    array $invoiceData,
    $whmcsInvoiceId,
    $expectedAmount,
    $expectedCurrency
) {
    if (!array_key_exists('id', $invoiceData) || !is_scalar($invoiceData['id']) ||
        trim((string) $invoiceData['id']) === '') {
        return 'BTCPay invoice creation response is missing a valid invoice ID.';
    }

    if (!array_key_exists('url', $invoiceData) || !is_string($invoiceData['url']) ||
        trim($invoiceData['url']) === '') {
        return 'BTCPay invoice creation response is missing a valid checkout URL.';
    }

    $contract = array(
        'btcpay_invoice_id' => (string) $invoiceData['id'],
        'whmcs_invoice_id' => $whmcsInvoiceId,
        'btcpay_amount' => $expectedAmount,
        'btcpay_currency' => $expectedCurrency,
    );

    return bpValidateBtcpayInvoiceContract($invoiceData, $contract, false);
}

/**
 * @param array|object $invoice
 * @param array|object $contract
 * @return string|null
 */
function bpValidateWhmcsInvoiceContract($invoice, $contract)
{
    $invoice = (array) $invoice;
    $contract = (array) $contract;

    foreach (array('id', 'total', 'currency') as $field) {
        if (!array_key_exists($field, $invoice)) {
            return 'WHMCS invoice record is missing ' . $field . '.';
        }
    }

    foreach (array('whmcs_invoice_id', 'whmcs_amount', 'whmcs_currency') as $field) {
        if (!array_key_exists($field, $contract)) {
            return 'Stored invoice contract is missing ' . $field . '.';
        }
    }

    if ((string) $invoice['id'] !== (string) $contract['whmcs_invoice_id']) {
        return 'WHMCS invoice ID does not match the stored invoice contract.';
    }

    try {
        if (!bpDecimalEquals($invoice['total'], $contract['whmcs_amount'])) {
            return 'WHMCS invoice total changed after the BTCPay invoice was created.';
        }
        if (bpNormalizeCurrencyCode($invoice['currency']) !==
            bpNormalizeCurrencyCode($contract['whmcs_currency'])) {
            return 'WHMCS invoice currency changed after the BTCPay invoice was created.';
        }
    } catch (InvalidArgumentException $exception) {
        return 'WHMCS invoice or stored contract contains an invalid amount or currency.';
    }

    return null;
}

/**
 * Create the contract table before any external invoice is issued.
 *
 * @return void
 */
function bpEnsureInvoiceContractTable()
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $schema = Capsule::schema();
    if (!$schema->hasTable(BP_INVOICE_CONTRACT_TABLE)) {
        try {
            $schema->create(BP_INVOICE_CONTRACT_TABLE, function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->engine = 'InnoDB';
                $table->increments('id');
                $table->string('btcpay_invoice_id', 128);
                $table->string('btcpay_invoice_hash', 64);
                $table->integer('whmcs_invoice_id')->unsigned();
                $table->string('whmcs_amount', 128);
                $table->string('whmcs_currency', 16);
                $table->string('btcpay_amount', 128);
                $table->string('btcpay_currency', 16);
                $table->string('status', 32)->default('new');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->dateTime('processed_at')->nullable();
                // Default WHMCS/MySQL collations are often case-insensitive, while
                // BTCPay invoice IDs are case-sensitive. Index the SHA-256 lookup key.
                $table->unique('btcpay_invoice_hash', 'mod_btcpay_invoice_unique');
                $table->index('whmcs_invoice_id', 'mod_btcpay_whmcs_invoice_idx');
            });
        } catch (Exception $exception) {
            // A concurrent request may have created the table first.
            if (!$schema->hasTable(BP_INVOICE_CONTRACT_TABLE)) {
                throw new RuntimeException('Unable to create the BTCPay invoice contract table.', 0, $exception);
            }
        }
    }

    $ready = true;
}

/**
 * @param array $contract
 * @return void
 */
function bpStoreInvoiceContract(array $contract)
{
    bpEnsureInvoiceContractTable();

    foreach (array(
        'btcpay_invoice_id',
        'whmcs_invoice_id',
        'whmcs_amount',
        'whmcs_currency',
        'btcpay_amount',
        'btcpay_currency',
    ) as $field) {
        if (!array_key_exists($field, $contract)) {
            throw new InvalidArgumentException('Invoice contract is missing ' . $field . '.');
        }
    }

    $btcpayInvoiceId = (string) $contract['btcpay_invoice_id'];
    if ($btcpayInvoiceId === '' || strlen($btcpayInvoiceId) > 128) {
        throw new InvalidArgumentException('Invalid BTCPay invoice ID.');
    }

    $now = gmdate('Y-m-d H:i:s');
    Capsule::table(BP_INVOICE_CONTRACT_TABLE)->insert(array(
        'btcpay_invoice_id' => $btcpayInvoiceId,
        'btcpay_invoice_hash' => hash('sha256', $btcpayInvoiceId),
        'whmcs_invoice_id' => (int) $contract['whmcs_invoice_id'],
        'whmcs_amount' => bpNormalizeDecimal($contract['whmcs_amount']),
        'whmcs_currency' => bpNormalizeCurrencyCode($contract['whmcs_currency']),
        'btcpay_amount' => bpNormalizeDecimal($contract['btcpay_amount']),
        'btcpay_currency' => bpNormalizeCurrencyCode($contract['btcpay_currency']),
        'status' => 'new',
        'created_at' => $now,
        'updated_at' => $now,
        'processed_at' => null,
    ));
}

/**
 * @param string $btcpayInvoiceId
 * @return object|null
 */
function bpFindInvoiceContract($btcpayInvoiceId)
{
    bpEnsureInvoiceContractTable();

    return Capsule::table(BP_INVOICE_CONTRACT_TABLE)
        ->where('btcpay_invoice_hash', hash('sha256', (string) $btcpayInvoiceId))
        ->first();
}

/**
 * @param int|string      $whmcsInvoiceId
 * @param bool            $forUpdate
 * @param int|string|null $clientId
 * @return object|null
 */
function bpGetWhmcsInvoiceForContract($whmcsInvoiceId, $forUpdate = false, $clientId = null)
{
    $query = Capsule::table('tblinvoices')
        ->join('tblclients', 'tblinvoices.userid', '=', 'tblclients.id')
        ->join('tblcurrencies', 'tblclients.currency', '=', 'tblcurrencies.id')
        ->where('tblinvoices.id', (int) $whmcsInvoiceId)
        ->select(
            'tblinvoices.id',
            'tblinvoices.userid',
            'tblinvoices.total',
            'tblinvoices.status',
            'tblinvoices.paymentmethod',
            'tblcurrencies.code as currency'
        );

    if ($clientId !== null) {
        $query->where('tblinvoices.userid', (int) $clientId);
    }

    if ($forUpdate) {
        $query->lockForUpdate();
    }

    return $query->first();
}

/**
 * Locate unprocessed contracts that exactly match the invoice being rendered.
 * Callers serialize this query by locking the corresponding WHMCS invoice row.
 *
 * @param int|string $whmcsInvoiceId
 * @param mixed      $whmcsAmount
 * @param string     $whmcsCurrency
 * @param mixed      $btcpayAmount
 * @param string     $btcpayCurrency
 * @return iterable
 */
function bpFindReusableInvoiceContracts(
    $whmcsInvoiceId,
    $whmcsAmount,
    $whmcsCurrency,
    $btcpayAmount,
    $btcpayCurrency
) {
    bpEnsureInvoiceContractTable();

    return Capsule::table(BP_INVOICE_CONTRACT_TABLE)
        ->where('whmcs_invoice_id', (int) $whmcsInvoiceId)
        ->where('whmcs_amount', bpNormalizeDecimal($whmcsAmount))
        ->where('whmcs_currency', bpNormalizeCurrencyCode($whmcsCurrency))
        ->where('btcpay_amount', bpNormalizeDecimal($btcpayAmount))
        ->where('btcpay_currency', bpNormalizeCurrencyCode($btcpayCurrency))
        ->whereNull('processed_at')
        ->whereNotIn('status', array('expired', 'invalid'))
        ->orderBy('id', 'desc')
        ->limit(20)
        ->get();
}

/**
 * Never reverse a WHMCS payment automatically. A terminal invalid state that
 * arrives after crediting must instead be escalated for administrator review.
 *
 * @param mixed  $processedAt
 * @param string $newStatus
 * @return bool
 */
function bpInvoiceStatusRequiresManualReview($processedAt, $newStatus)
{
    return $processedAt !== null && in_array(
        strtolower(trim($newStatus)),
        array('expired', 'invalid'),
        true
    );
}

/**
 * Run callback processing while holding a row lock on the invoice contract.
 *
 * @param string   $btcpayInvoiceId
 * @param callable $callback
 * @return mixed
 */
function bpWithLockedInvoiceContract($btcpayInvoiceId, callable $callback)
{
    bpEnsureInvoiceContractTable();

    return Capsule::connection()->transaction(function () use ($btcpayInvoiceId, $callback) {
        $contract = Capsule::table(BP_INVOICE_CONTRACT_TABLE)
            ->where('btcpay_invoice_hash', hash('sha256', (string) $btcpayInvoiceId))
            ->lockForUpdate()
            ->first();

        if (!$contract) {
            throw new RuntimeException('No stored contract exists for this BTCPay invoice.');
        }

        return $callback($contract);
    });
}

/**
 * @param int|string $contractId
 * @param string     $status
 * @param bool       $processed
 * @return void
 */
function bpUpdateInvoiceContractStatus($contractId, $status, $processed = false)
{
    $status = strtolower(trim($status));
    if (!preg_match('/^[a-z0-9_-]{1,32}$/D', $status)) {
        $status = 'unknown';
    }

    $values = array(
        'status' => $status,
        'updated_at' => gmdate('Y-m-d H:i:s'),
    );
    if ($processed) {
        $values['processed_at'] = gmdate('Y-m-d H:i:s');
    }

    Capsule::table(BP_INVOICE_CONTRACT_TABLE)
        ->where('id', (int) $contractId)
        ->update($values);
}
