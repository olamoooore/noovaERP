<?php

namespace App\Services;

use App\Models\CustomerAddress;
use App\Models\Invoice;
use App\Models\TaxDeterminationResult;
use Illuminate\Support\Facades\DB;
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

        // #277 F5: manual overrides are guarded + recorded (engine vs applied).
        $engineJurisdiction = $this->resolveJurisdiction($invoice);

        if ($overrides !== null && $overrides !== []) {
            app(\App\Services\TaxOverrideService::class)->guardAndRecordDocumentOverride(
                'invoice',
                (int) $invoice->id,
                [
                    'jurisdiction' => $engineJurisdiction,
                    'tax_regime' => self::COUNTRY_REGIME[$engineJurisdiction] ?? 'GST',
                ],
                $overrides,
                round((float) $invoice->tax_amount, 2),
                round((float) $invoice->tax_amount, 2),
            );
        }

        $jurisdiction = $overrides['jurisdiction']
            ?? $engineJurisdiction;
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
            // G-27 (#274): only adopt the resolved rule's identity when its
            // rate matches the booked rate — a booked-rate line must never
            // borrow the code/treatment/tax_id of a rule with another rate.
            $rateMatches = $resolved && abs((float) $resolved['rate'] - $taxRate) < 0.005;
            $taxId = null;
            if ($resolved && ($line->tax_rate === null || $usesLocalizedDefault) && $rateMatches) {
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
                'tax_type' => $rateMatches ? ($resolved['tax_type'] ?? null) : null,
                'creditable' => $rateMatches ? ($resolved['creditable'] ?? true) : true,
                'blocked_account_id' => $rateMatches ? ($resolved['blocked_account_id'] ?? null) : null,
                'source_line_id' => $line->id,
            ];
        }

        $taxLines = $this->scaleRowsToBooked($taxLines, round((float) $invoice->tax_amount, 2));
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
        $billEngineJurisdiction = $this->resolveVendorJurisdiction($bill);

        if ($overrides !== null && $overrides !== []) {
            app(\App\Services\TaxOverrideService::class)->guardAndRecordDocumentOverride(
                'bill',
                (int) $bill->id,
                [
                    'jurisdiction' => $billEngineJurisdiction,
                    'tax_regime' => self::COUNTRY_REGIME[$billEngineJurisdiction] ?? 'GST',
                ],
                $overrides,
                round((float) $bill->tax_amount, 2),
                round((float) $bill->tax_amount, 2),
            );
        }

        $jurisdiction = $overrides['jurisdiction'] ?? $billEngineJurisdiction;
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
            // G-26 (#274): honour the resolved rule's inclusive mode — an
            // inclusive bill extracts tax FROM the entered amount (taxable
            // base is the de-grossed net) instead of always adding it on top.
            $isInclusive = (bool) ($resolved['is_inclusive'] ?? false);
            $calc = $this->engine::calculate($base, $rate, $isInclusive);
            $tax = $calc['tax'];
            $taxableBase = $isInclusive ? $calc['net'] : $base;

            // Booked-first: a historical document override stays authoritative.
            if ($line->tax_rate !== null && (float) $line->tax_amount > 0) {
                $tax = (float) $line->tax_amount;
                // Inclusive documents store the gross in line_total.
                $taxableBase = $isInclusive ? $base - $tax : $base;
            }

            // G-27 (#274): mismatched resolved rates must not lend their
            // identity (code/treatment/tax_id) to the booked line.
            $rateMatches = $resolved && abs((float) $resolved['rate'] - $rate) < 0.005;
            $taxLines[] = [
                'tax_code' => $rateMatches ? $resolved['tax_code'] : $this->resolveTaxCode($line->product, $jurisdiction, $regime, $rate),
                'taxable_base' => round($taxableBase, 2),
                'tax_rate' => $rate,
                'tax_amount' => $tax,
                'treatment' => $rateMatches ? $resolved['treatment'] : ($rate > 0 ? 'standard_rated' : 'zero_rated'),
                'tax_id' => $rateMatches ? ($resolved['tax_id'] ?? null) : null,
                'tax_type' => $rateMatches ? ($resolved['tax_type'] ?? null) : null,
                'creditable' => $rateMatches ? ($resolved['creditable'] ?? true) : true,
                'blocked_account_id' => $rateMatches ? ($resolved['blocked_account_id'] ?? null) : null,
                'source_line_id' => $line->id,
            ];
        }

        $taxLines = $this->scaleRowsToBooked($taxLines, round((float) $bill->tax_amount, 2));
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
                // #270 R4.6: a vendor-profile withholding-rate OVERRIDE keeps
                // the withholding rule's identity (code/tax_id) even though the
                // effective rate intentionally differs from the rule.
                $withholdingOverride = $resolved && ($resolved['tax_type'] ?? null) === 'withholding';

                if ($rateMatchesEngine || $engineIsZero || $withholdingOverride) {
                    $taxCode = $resolved['tax_code'];
                    $treatment = $resolved['treatment'];
                    $taxId = ($rateMatchesEngine || $withholdingOverride) ? ($resolved['tax_id'] ?? null) : null;
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
                'tax_type' => $resolved['tax_type'] ?? null,
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
                'tax_type' => $row['tax_type'] ?? null,
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
        // G-02: central-schema table — tenant-scoped or nothing.
        $result = TaxDeterminationResult::where('tenant_id', tenant('id'))
            ->where('source_record_type', $sourceType)
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
            // G-27 (#274): group by rate as well — two lines sharing a tax
            // code at different rates must not collapse into one row that
            // silently keeps the first rate.
            $groupKey = $code.'|'.number_format((float) $line['tax_rate'], 4, '.', '');

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'tax_code' => $code,
                    'taxable_base' => 0.0,
                    'tax_amount' => 0.0,
                    'tax_rate' => (float) $line['tax_rate'],
                    'treatment' => $line['treatment'],
                    'tax_id' => $line['tax_id'] ?? null,
                    'tax_type' => $line['tax_type'] ?? null,
                    'creditable' => $line['creditable'] ?? true,
                    'blocked_account_id' => $line['blocked_account_id'] ?? null,
                    'source_line_ids' => [],
                ];
            }

            $groups[$groupKey]['taxable_base'] = round($groups[$groupKey]['taxable_base'] + (float) $line['taxable_base'], 2);
            $groups[$groupKey]['tax_amount'] = round($groups[$groupKey]['tax_amount'] + (float) $line['tax_amount'], 2);

            if (empty($groups[$groupKey]['tax_id']) && ! empty($line['tax_id'])) {
                $groups[$groupKey]['tax_id'] = $line['tax_id'];
            }

            if ((float) $groups[$groupKey]['tax_rate'] <= 0 && (float) $line['tax_rate'] > 0) {
                $groups[$groupKey]['tax_rate'] = (float) $line['tax_rate'];
                $groups[$groupKey]['treatment'] = $line['treatment'];
            }

            if (($groups[$groupKey]['creditable'] ?? true) && (isset($line['creditable']) && $line['creditable'] === false)) {
                $groups[$groupKey]['creditable'] = false;
                $groups[$groupKey]['blocked_account_id'] = $line['blocked_account_id'] ?? ($groups[$groupKey]['blocked_account_id'] ?? null);
            }

            if (isset($line['source_line_id'])) {
                $groups[$groupKey]['source_line_ids'][] = $line['source_line_id'];
            }
        }

        return array_values($groups);
    }

    /**
     * G-36 (R02): when a document's BOOKED tax total differs from the sum of
     * per-line tax (header discount proration), scale every row so
     * taxable_base x rate == tax_amount stays consistent on the trail. The
     * last row absorbs rounding drift so the scaled sum equals the booked
     * document tax exactly.
     *
     * @param  array<int, array<string, mixed>>  $taxLines
     * @return array<int, array<string, mixed>>
     */
    private function scaleRowsToBooked(array $taxLines, float $bookedDocTax): array
    {
        $lineSum = round(array_sum(array_map(fn ($l) => (float) $l['tax_amount'], $taxLines)), 2);

        // G-36: scaling only applies when the document carries its OWN booked
        // tax total (InvoiceController/BillService totals). A doc with an
        // un-maintained total (0) alongside booked line tax is an authoring
        // bug surfaced by TAX_VARIANCE — never scale evidence to zero for it.
        if ($lineSum <= 0 || $bookedDocTax <= 0 || abs($bookedDocTax - $lineSum) <= 0.005) {
            return $taxLines;
        }

        $factor = $bookedDocTax / $lineSum;
        $last = count($taxLines) - 1;
        $running = 0.0;

        foreach ($taxLines as $i => &$row) {
            if ($i === $last) {
                $newTax = round($bookedDocTax - $running, 2);
            } else {
                $newTax = round((float) $row['tax_amount'] * $factor, 2);
            }

            $running = round($running + $newTax, 2);
            $rate = (float) $row['tax_rate'];

            $row['tax_amount'] = $newTax;
            $row['taxable_base'] = $rate > 0
                ? round($newTax / ($rate / 100), 2)
                : round((float) $row['taxable_base'] * $factor, 2);
        }
        unset($row);

        return $taxLines;
    }

    /**
     * Persist one versioned determination row per source document, returning
     * [correlation id, result version].
     *
     * G-02: tax_determination_results lives in the central schema, so every
     * lookup is tenant-scoped. G-04: the read-lock-bump-write sequence runs in
     * one transaction; a unique key guards (tenant, type, id, version).
     * G-05: identical inputs (input_hash) return the existing version —
     * idempotent re-posting never burns snapshots.
     * G-07: a version bump SUPERSEDES the previous row in the same transaction
     * (status + superseded_by), so `->current()` is a real filter and stale
     * snapshots can never be posted as authority.
     * G-09: entity scope (tenant legal entity as a deterministic uuid, same
     * convention as AuthorityEvaluationSnapshot actor mapping).
     */
    private function persistResult(
        string $transactionType,
        int $sourceId,
        string $jurisdiction,
        string $regime,
        array $taxLines,
        string $transactionDate,
    ): array {
        $inputHash = hash('sha256', json_encode([$jurisdiction, $regime, $transactionDate, $taxLines]));
        $entityUuid = $this->resolveEntityUuid();
        $determinedBy = $this->resolveActorUuid();
        $ruleRefs = $this->buildRuleRefs($taxLines);

        return DB::transaction(function () use ($transactionType, $sourceId, $jurisdiction, $taxLines, $transactionDate, $inputHash, $entityUuid, $determinedBy, $ruleRefs) {
            $determinationId = (string) Str::uuid();
            $resultVersion = 1;

            $existing = TaxDeterminationResult::where('tenant_id', tenant('id'))
                ->where('source_record_type', $transactionType)
                ->where('source_record_id', $sourceId)
                ->current()
                ->orderByDesc('result_version')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $determinationId = $existing->correlation_id ?: $determinationId;

                if (hash_equals((string) ($existing->input_hash ?? ''), $inputHash)) {
                    return [$determinationId, (int) $existing->result_version];
                }

                $resultVersion = (int) $existing->result_version + 1;
            }

            $created = TaxDeterminationResult::create([
                'tenant_id' => tenant('id'),
                'entity_id' => $entityUuid,
                'result_version' => $resultVersion,
                'jurisdiction' => $jurisdiction,
                'transaction_type' => $transactionType,
                'transaction_date' => $transactionDate,
                'tax_rule_refs' => $ruleRefs,
                'tax_lines' => $taxLines,
                'input_hash' => $inputHash,
                'rounding_method' => 'half_up',
                'calculation_basis' => 'per_line',
                'result_status' => 'DETERMINED',
                'determined_at' => now(),
                'determined_by' => $determinedBy,
                'source_record_type' => $transactionType,
                'source_record_id' => $sourceId,
                'correlation_id' => $determinationId,
            ]);

            if ($existing) {
                $existing->update([
                    'result_status' => 'SUPERSEDED',
                    'superseded_by' => $created->id,
                ]);
            }

            // G-12 (#274): ACC-009 lifecycle events on the shared outbox.
            $publisher = app(\App\Services\TaxOutboxPublisher::class);
            $publisher->publish('TaxDetermined', $determinationId, $resultVersion, [
                'correlation_id' => $determinationId,
                'source_record_type' => $transactionType,
                'source_record_id' => $sourceId,
                'result_version' => $resultVersion,
                'jurisdiction' => $jurisdiction,
                'tax_total' => round(array_sum(array_map(fn ($l) => (float) ($l['tax_amount'] ?? 0), $taxLines)), 2),
                'rule_ref_count' => count($ruleRefs),
            ]);

            if ($existing) {
                $publisher->publish('TaxDeterminationCorrected', $determinationId, $resultVersion, [
                    'correlation_id' => $determinationId,
                    'source_record_type' => $transactionType,
                    'source_record_id' => $sourceId,
                    'superseded_version' => (int) $existing->result_version,
                    'result_version' => $resultVersion,
                ]);
            }

            return [$determinationId, $resultVersion];
        });
    }

    /**
     * G-09: the requesting legal entity (request attribute set by
     * ResolveEntityContext) mapped to a deterministic uuid — the central table
     * column is uuid typed and tenant legal entities carry integer ids.
     */
    private function resolveEntityUuid(): ?string
    {
        $legalEntityId = request()->attributes->get('legal_entity_id');

        if (! $legalEntityId) {
            return null;
        }

        return \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'legal_entity:'.$legalEntityId)->toString();
    }

    /**
     * G-13: actor lineage for the determination (deterministic uuid mapping,
     * same convention as AuthorityEvaluationSnapshot actor ids).
     */
    private function resolveActorUuid(): ?string
    {
        $actorId = auth()->id();

        if (! $actorId) {
            return null;
        }

        return \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'actor:'.$actorId)->toString();
    }

    /**
     * G-08: rule references carry the rule identity AND its version, so the
     * posting gate can bind exact policy versions — not just tax codes.
     *
     * @param  array<int, array<string, mixed>>  $taxLines
     * @return array<int, array{tax_code: string|null, tax_rule_id: int|null, rule_version: int|null}>
     */
    private function buildRuleRefs(array $taxLines): array
    {
        $ids = collect($taxLines)->pluck('tax_id')->filter()->unique()->values()->all();
        $versions = collect();

        if ($ids !== [] && \Illuminate\Support\Facades\Schema::hasColumn('taxes', 'version')) {
            $versions = DB::table('taxes')->whereIn('id', $ids)->pluck('version', 'id');
        }

        return collect($taxLines)
            ->map(fn ($line) => [
                'tax_code' => $line['tax_code'] ?? null,
                'tax_rule_id' => $line['tax_id'] ?? null,
                'rule_version' => ! empty($line['tax_id']) ? (int) ($versions[$line['tax_id']] ?? 1) : null,
            ])
            ->unique(fn ($ref) => ($ref['tax_code'] ?? 'null').'|'.($ref['tax_rule_id'] ?? 'null'))
            ->values()
            ->all();
    }

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
