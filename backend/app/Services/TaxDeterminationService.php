<?php

namespace App\Services;

use App\Models\CustomerAddress;
use App\Models\Invoice;
use App\Models\TaxDeterminationResult;
use Illuminate\Support\Str;

/**
 * Builds canonical, versioned TaxDeterminationResults for every tax-bearing
 * document (invoice, bill, POS order, web order, credit notes) and persists
 * them to tax_determination_results before the PostingEngine consumes them.
 *
 * The persisted result is passed to PostingEngine which writes
 * tax_transaction_details with the correct rate, treatment, and regime
 * per ADR-005 §26.2, Issue #42 and the Tax Engine MRD (TAX-REQ-07).
 *
 * Determination is booked-first: where a document line already carries a tax
 * amount (a historical document override), the determination preserves the
 * booked value and only the tax IDENTITY (code/treatment/rate source) is
 * resolved from the engine. This keeps GL ↔ subledger ↔ return consistent.
 */
class TaxDeterminationService
{
    public function __construct(private readonly TaxEngineService $engine) {}

    /**
     * Country → jurisdiction code mapping.
     */
    private const COUNTRY_JURISDICTION = [
        'SG' => 'SG',
        'MY' => 'MY',
        'AU' => 'AU',
        'NZ' => 'NZ',
        'GB' => 'GB',
        'US' => 'US',
        'ID' => 'ID',
        'TH' => 'TH',
        'PH' => 'PH',
        'VN' => 'VN',
        'IN' => 'IN',
    ];

    /**
     * Country → default tax regime mapping.
     */
    private const COUNTRY_REGIME = [
        'SG' => 'GST',
        'MY' => 'SST',
        'AU' => 'GST',
        'NZ' => 'GST',
        'GB' => 'VAT',
        'US' => 'SALES_TAX',
        'ID' => 'VAT',
        'TH' => 'VAT',
        'PH' => 'VAT',
        'VN' => 'VAT',
        'IN' => 'GST',
    ];

    /**
     * Compute and persist a TaxDeterminationResult for the given invoice.
     *
     * Returns the array format expected by PostingEngine::post()/domainPosting()
     * via its taxDeterminationResult parameter. The 'tax_lines' returned to the
     * engine are AGGREGATED per tax_code so multi-rate documents write one
     * correct taxable base per GL tax line; the persisted row keeps per-source-
     * line detail for audit.
     */
    public function determineForInvoice(
        Invoice $invoice,
        ?array $overrides = null,
    ): array {
        $invoice->loadMissing('lines.product', 'customer');

        $jurisdiction = $overrides['jurisdiction']
            ?? $this->resolveJurisdiction($invoice);
        $regime = $overrides['tax_regime']
            ?? self::COUNTRY_REGIME[$jurisdiction] ?? 'GST';
        $transactionDate = $invoice->invoice_date instanceof \DateTimeInterface
            ? $invoice->invoice_date->toDateString()
            : (string) $invoice->invoice_date;

        $defaultTaxId = ! empty(\App\Models\Setting::getValue('tax', [])['default_tax_id']);

        // Build per-line tax determinations.
        $taxLines = [];

        foreach ($invoice->lines as $line) {
            $bookedRate = (float) ($line->tax_rate ?? 0);
            $resolved = $line->product
                ? $this->engine->resolve(
                    $line->product,
                    $jurisdiction,
                    'output',
                    ($line->product->type ?? null) === 'service' ? 'service' : null,
                    $transactionDate,
                )
                : null;
            // A non-zero line rate is a historical/document override: the
            // booked amounts stay authoritative and only identity is resolved.
            $usesLocalizedDefault = $line->product && (
                $line->product->tax_id
                || $line->product->tax_mode === 'custom'
                || $defaultTaxId
            );

            $discount = (float) ($line->discount_amount ?? 0);
            $gross = (float) $line->quantity * (float) $line->unit_price;

            if ($bookedRate <= 0 && ($line->tax_rate === null || $usesLocalizedDefault) && $resolved && (float) $resolved['rate'] > 0) {
                // Engine-applied tax: compute through the shared calculator so
                // inclusive/exclusive and line discounts are handled once.
                $calc = $this->engine->calculate(
                    $gross,
                    (float) $resolved['rate'],
                    (bool) $resolved['is_inclusive'],
                    $discount,
                );
                $taxRate = (float) $resolved['rate'];
                $taxableBase = $calc['net'];
                $taxAmount = $calc['tax'];
            } else {
                $taxRate = $bookedRate;
                // line_total always includes tax (InvoiceLine::calculateTotals),
                // for both inclusive and exclusive lines, after discounts.
                $taxAmount = round((float) $line->tax_amount, 2);
                $taxableBase = round((float) $line->line_total - $taxAmount, 2);
            }

            $treatment = match (true) {
                $taxRate > 0 => 'standard_rated',
                $taxRate === 0.0 && ($overrides['treatment'] ?? null) !== 'exempt' => 'zero_rated',
                default => 'exempt',
            };

            $taxCode = $this->resolveTaxCode(
                $line->product ?? null,
                $jurisdiction,
                $regime,
                $taxRate,
            );
            $taxId = null;
            if ($resolved && ($line->tax_rate === null || $usesLocalizedDefault)) {
                $taxCode = $resolved['tax_code'];
                $treatment = $resolved['treatment'];
                $taxId = $resolved['tax_id'] ?? null;
            }

            $taxLines[] = [
                'tax_code' => $taxCode,
                'taxable_base' => $taxableBase,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'treatment' => $treatment,
                'tax_id' => $taxId,
                'creditable' => $resolved['creditable'] ?? true,
                'blocked_account_id' => $resolved['blocked_account_id'] ?? null,
                'source_line_id' => $line->id,
            ];
        }

        $aggregated = $this->aggregateTaxLines($taxLines);
        [$id, $version] = $this->persistResult('invoice', (int) $invoice->id, $jurisdiction, $regime, $taxLines, $transactionDate);

        return $this->buildResult($id, $version, $jurisdiction, $regime, $taxLines, $aggregated);
    }

    /**
     * Determine purchase-side tax using the same rule contract as sales.
     * Vendor bill tax is input tax for GST/VAT regimes and non-creditable for
     * Malaysia SST; the statutory aggregator handles that distinction.
     *
     * Issue #42 follow-up (MRD D10): the bill determination is now PERSISTED
     * as a versioned TaxDeterminationResult, exactly like invoices — vendor
     * credit notes later inherit its tax identity from the stored row.
     */
    public function determineForBill(\App\Models\Bill $bill, ?array $overrides = null): array
    {
        $bill->loadMissing('lines.product', 'vendor.addresses');
        $jurisdiction = $overrides['jurisdiction'] ?? $this->resolveVendorJurisdiction($bill);
        $regime = $overrides['tax_regime'] ?? self::COUNTRY_REGIME[$jurisdiction] ?? 'GST';
        $date = $bill->bill_date?->toDateString() ?? now()->toDateString();
        $taxLines = [];

        foreach ($bill->lines as $line) {
            $rate = (float) ($line->tax_rate ?? 0);
            $resolved = $line->product ? $this->engine->resolve($line->product, $jurisdiction, 'input', null, $date) : null;
            if ($rate <= 0 && $line->tax_rate === null && $resolved) {
                $rate = (float) $resolved['rate'];
            }
            // BillLine::line_total is the pre-tax line value; unlike an
            // InvoiceLine it does not include tax.
            $base = max((float) $line->line_total, 0.0);
            $tax = $this->engine::calculate($base, $rate, false)['tax'];
            if ($line->tax_rate !== null && (float) $line->tax_amount > 0) {
                $tax = (float) $line->tax_amount;
            }
            $taxLines[] = [
                'tax_code' => $resolved['tax_code'] ?? $this->resolveTaxCode($line->product, $jurisdiction, $regime, $rate),
                'taxable_base' => round($base, 2),
                'tax_rate' => $rate,
                'tax_amount' => $tax,
                'treatment' => $resolved['treatment'] ?? ($rate > 0 ? 'standard_rated' : 'zero_rated'),
                'tax_id' => $resolved['tax_id'] ?? null,
                'creditable' => $resolved['creditable'] ?? true,
                'blocked_account_id' => $resolved['blocked_account_id'] ?? null,
                'source_line_id' => $line->id,
            ];
        }

        $aggregated = $this->aggregateTaxLines($taxLines);
        [$id, $version] = $this->persistResult('bill', (int) $bill->id, $jurisdiction, $regime, $taxLines, $date);

        return $this->buildResult($id, $version, $jurisdiction, $regime, $taxLines, $aggregated);
    }

    /**
     * Canonical determination for line sets from non-invoice/bill documents:
     * POS orders, web orders, and customer/vendor credit notes.
     *
     * Lines are booked-first: the caller supplies the amounts already stored
     * on the document lines; this method resolves the tax IDENTITY (code,
     * treatment, rule reference) through the shared engine and aggregates one
     * determination row per tax_code for PostingEngine to match against.
     *
     * @param  array<int, array{source_line_id?:int|null, product?:\App\Models\Product|null, base:float, rate:float, tax:float, tax_code_override?:string|null, treatment_override?:string|null, tax_id_override?:int|null, tax_type?:string|null}>  $lines
     */
    public function determineForLineSet(
        string $documentType,
        int $documentId,
        string $date,
        array $lines,
        string $direction = 'output',
        ?string $jurisdiction = null,
        ?int $customerId = null,
        ?int $vendorId = null,
    ): array {
        $direction = $direction === 'input' ? 'input' : 'output';
        $jurisdiction = strtoupper($jurisdiction
            ?? $this->jurisdictionForCustomer($customerId)
            ?? $this->jurisdictionForVendor($vendorId)
            ?? $this->engine->tenantJurisdiction());
        $regime = self::COUNTRY_REGIME[$jurisdiction] ?? 'GST';

        $taxLines = [];

        foreach ($lines as $line) {
            $rate = (float) ($line['rate'] ?? 0);
            $tax = round((float) ($line['tax'] ?? 0), 2);
            $base = round(max((float) ($line['base'] ?? 0), 0.0), 2);
            $product = $line['product'] ?? null;

            // Product-less lines still resolve through the engine ladder
            // (tenant default → jurisdiction/direction rule) so expense-style
            // documents get real rule identity, not synthetic codes.
            $resolved = $this->engine->resolve($product, $jurisdiction, $direction, $line['tax_type'] ?? null, $date);

            if (! empty($line['tax_code_override'])) {
                $taxCode = (string) $line['tax_code_override'];
                $treatment = (string) ($line['treatment_override'] ?? ($rate > 0 ? 'standard_rated' : 'zero_rated'));
                $taxId = $line['tax_id_override'] ?? null;
            } else {
                $rateMatchesEngine = $resolved && $rate > 0 && abs((float) $resolved['rate'] - $rate) < 0.005;
                $engineIsZero = $resolved && $rate <= 0 && (float) $resolved['rate'] <= 0;

                if ($rateMatchesEngine || $engineIsZero) {
                    $taxCode = $resolved['tax_code'];
                    $treatment = $resolved['treatment'];
                    $taxId = $rateMatchesEngine ? ($resolved['tax_id'] ?? null) : null;
                } else {
                    $taxCode = $this->resolveTaxCode($product, $jurisdiction, $regime, $rate);
                    $treatment = $rate > 0 ? 'standard_rated' : 'zero_rated';
                    $taxId = null;
                }
            }

            $taxLines[] = [
                'tax_code' => $taxCode,
                'taxable_base' => $base,
                'tax_rate' => $rate,
                'tax_amount' => $tax,
                'treatment' => $treatment,
                'tax_id' => $taxId,
                'creditable' => $line['creditable_override'] ?? ($resolved['creditable'] ?? true),
                'blocked_account_id' => $resolved['blocked_account_id'] ?? null,
                'source_line_id' => $line['source_line_id'] ?? null,
            ];
        }

        $aggregated = $this->aggregateTaxLines($taxLines);
        [$id, $version] = $this->persistResult($documentType, $documentId, $jurisdiction, $regime, $taxLines, $date);

        return $this->buildResult($id, $version, $jurisdiction, $regime, $taxLines, $aggregated);
    }

    /**
     * Allocate a document's BOOKED tax total across the determination's
     * per-code tax lines. The sum of allocations always equals the booked
     * total exactly (last line absorbs rounding drift), so journal balance is
     * preserved while each tax GL line carries the right tax_code.
     *
     * @param  array<int, array<string, mixed>>  $taxLines  Aggregated determination lines.
     * @return array<int, array{tax_code: string|null, tax_id: int|null, amount: float}>
     */
    public static function allocateBookedTax(array $taxLines, float $bookedTotal): array
    {
        $bookedTotal = round($bookedTotal, 2);

        if ($bookedTotal <= 0) {
            return [];
        }

        $rows = array_values(array_filter($taxLines, fn ($t) => (float) $t['tax_amount'] > 0));
        $detSum = array_sum(array_map(fn ($t) => (float) $t['tax_amount'], $rows));

        if ($rows === [] || $detSum <= 0) {
            return [['tax_code' => null, 'tax_id' => null, 'amount' => $bookedTotal]];
        }

        $allocations = [];
        $running = 0.0;
        $last = count($rows) - 1;

        foreach ($rows as $index => $row) {
            $amount = $index === $last
                ? round($bookedTotal - $running, 2)
                : round($bookedTotal * ((float) $row['tax_amount'] / $detSum), 2);

            if ($amount <= 0 && $index !== $last) {
                continue;
            }

            $allocations[] = [
                'tax_code' => $row['tax_code'],
                'tax_id' => $row['tax_id'] ?? null,
                'treatment' => $row['treatment'] ?? null,
                'creditable' => $row['creditable'] ?? true,
                'blocked_account_id' => $row['blocked_account_id'] ?? null,
                'amount' => $amount,
            ];
            $running = round($running + $amount, 2);
        }

        if ($allocations === []) {
            return [['tax_code' => $rows[0]['tax_code'] ?? null, 'tax_id' => $rows[0]['tax_id'] ?? null, 'amount' => $bookedTotal]];
        }

        // Residual from skipped tiny allocations folds into the last line.
        $residual = round($bookedTotal - $running, 2);
        if (abs($residual) >= 0.01) {
            $lastIndex = array_key_last($allocations);
            $allocations[$lastIndex]['amount'] = round($allocations[$lastIndex]['amount'] + $residual, 2);
        }

        return $allocations;
    }

    /**
     * The latest persisted determination for a source document, keyed per
     * source line id — used by credit notes to inherit the original
     * document's tax identity.
     */
    public function latestDeterminationLines(string $sourceType, int $sourceId): array
    {
        $result = TaxDeterminationResult::where('source_record_type', $sourceType)
            ->where('source_record_id', $sourceId)
            ->current()
            ->orderByDesc('result_version')
            ->first();

        if (! $result) {
            return [];
        }

        return collect($result->tax_lines ?? [])
            ->filter(fn ($line) => isset($line['source_line_id']))
            ->keyBy('source_line_id')
            ->all();
    }

    /**
     * Aggregate per-source-line determination rows into one row per tax_code.
     */
    private function aggregateTaxLines(array $taxLines): array
    {
        $groups = [];

        foreach ($taxLines as $line) {
            $code = (string) $line['tax_code'];

            if (! isset($groups[$code])) {
                $groups[$code] = [
                    'tax_code' => $code,
                    'taxable_base' => 0.0,
                    'tax_amount' => 0.0,
                    'tax_rate' => (float) $line['tax_rate'],
                    'treatment' => $line['treatment'],
                    'tax_id' => $line['tax_id'] ?? null,
                    'creditable' => $line['creditable'] ?? true,
                    'blocked_account_id' => $line['blocked_account_id'] ?? null,
                    'source_line_ids' => [],
                ];
            }

            $groups[$code]['taxable_base'] = round($groups[$code]['taxable_base'] + (float) $line['taxable_base'], 2);
            $groups[$code]['tax_amount'] = round($groups[$code]['tax_amount'] + (float) $line['tax_amount'], 2);

            if (empty($groups[$code]['tax_id']) && ! empty($line['tax_id'])) {
                $groups[$code]['tax_id'] = $line['tax_id'];
            }

            if ((float) $groups[$code]['tax_rate'] <= 0 && (float) $line['tax_rate'] > 0) {
                $groups[$code]['tax_rate'] = (float) $line['tax_rate'];
                $groups[$code]['treatment'] = $line['treatment'];
            }

            if (($groups[$code]['creditable'] ?? true) && (isset($line['creditable']) && $line['creditable'] === false)) {
                $groups[$code]['creditable'] = false;
                $groups[$code]['blocked_account_id'] = $line['blocked_account_id'] ?? ($groups[$code]['blocked_account_id'] ?? null);
            }

            if (isset($line['source_line_id'])) {
                $groups[$code]['source_line_ids'][] = $line['source_line_id'];
            }
        }

        return array_values($groups);
    }

    /**
     * Persist one versioned determination row per source document.
     *
     * @return array{0: string, 1: int} correlation id + result version
     */
    private function persistResult(
        string $transactionType,
        int $sourceId,
        string $jurisdiction,
        string $regime,
        array $taxLines,
        string $transactionDate,
    ): array {
        $determinationId = (string) Str::uuid();
        $resultVersion = 1;

        $existing = TaxDeterminationResult::where('source_record_type', $transactionType)
            ->where('source_record_id', $sourceId)
            ->current()
            ->orderByDesc('result_version')
            ->first();

        if ($existing) {
            $determinationId = $existing->correlation_id ?: $determinationId;
            $resultVersion = (int) $existing->result_version + 1;
        }

        TaxDeterminationResult::create([
            'tenant_id' => tenant('id'),
            'entity_id' => null,
            'result_version' => $resultVersion,
            'jurisdiction' => $jurisdiction,
            'transaction_type' => $transactionType,
            'transaction_date' => $transactionDate,
            'tax_rule_refs' => collect($taxLines)->pluck('tax_code')->unique()->values()->all(),
            'tax_lines' => $taxLines,
            'rounding_method' => 'half_up',
            'calculation_basis' => 'per_line',
            'result_status' => 'DETERMINED',
            'determined_at' => now(),
            'source_record_type' => $transactionType,
            'source_record_id' => $sourceId,
            'correlation_id' => $determinationId,
        ]);

        return [$determinationId, $resultVersion];
    }

    /**
     * Assemble the PostingEngine-facing result. 'tax_lines' are aggregated per
     * code (PostingEngine::resolveLineDetermination matches GL tax lines by
     * tax_code); totals come from the per-line rows.
     */
    private function buildResult(
        string $id,
        int $version,
        string $jurisdiction,
        string $regime,
        array $perLine,
        array $aggregated,
    ): array {
        $totalTaxable = round(array_sum(array_map(fn ($l) => (float) $l['taxable_base'], $perLine)), 2);
        $totalTax = round(array_sum(array_map(fn ($l) => (float) $l['tax_amount'], $perLine)), 2);

        return [
            'id' => $id,
            'result_version' => $version,
            'jurisdiction' => $jurisdiction,
            'tax_regime' => $regime,
            'result_status' => 'DETERMINED',
            'tax_rate' => count($aggregated) === 1
                ? (float) $aggregated[0]['tax_rate']
                : 0,
            'taxable_base' => $totalTaxable,
            'tax_amount' => $totalTax,
            'treatment' => count($aggregated) === 1
                ? $aggregated[0]['treatment']
                : 'standard_rated',
            'tax_lines' => $aggregated,
        ];
    }

    /**
     * Resolve the jurisdiction from the invoice's customer billing address.
     */
    private function resolveJurisdiction(Invoice $invoice): string
    {
        return $this->jurisdictionForCustomer($invoice->customer_id)
            ?? $this->engine->tenantJurisdiction();
    }

    private function resolveVendorJurisdiction(\App\Models\Bill $bill): string
    {
        return $this->jurisdictionForVendor($bill->vendor_id)
            ?? $this->engine->tenantJurisdiction();
    }

    private function jurisdictionForCustomer(?int $customerId): ?string
    {
        if (! $customerId) {
            return null;
        }

        $billing = CustomerAddress::where('customer_id', $customerId)
            ->where('type', 'billing')
            ->where('is_default', true)
            ->first();

        if ($billing && ! empty($billing->country)) {
            $code = strtoupper(trim($billing->country));

            return self::COUNTRY_JURISDICTION[$code] ?? $code;
        }

        return null;
    }

    private function jurisdictionForVendor(?int $vendorId): ?string
    {
        if (! $vendorId) {
            return null;
        }

        $address = \App\Models\VendorAddress::where('vendor_id', $vendorId)
            ->where('is_default', true)
            ->first()
            ?? \App\Models\VendorAddress::where('vendor_id', $vendorId)->first();

        $code = strtoupper(trim((string) ($address?->country ?? '')));

        if ($code === '') {
            return null;
        }

        return self::COUNTRY_JURISDICTION[$code] ?? $code;
    }

    /**
     * Resolve the tax code for a line, preferring the product's tax_code,
     * then falling back to a synthetic jurisdiction-rate code.
     */
    private function resolveTaxCode(
        ?object $product,
        string $jurisdiction,
        string $regime,
        float $taxRate,
    ): string {
        // Product explicitly sets a tax code
        if ($product && ! empty($product->tax_code)) {
            return $product->tax_code;
        }

        // Synthetic: SG-GST-9, MY-SST-6, etc. (percentage, not basis points)
        $rateInt = rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.');

        return "{$jurisdiction}-{$regime}-{$rateInt}";
    }
}
