<?php

namespace App\Services;

use App\Models\CustomerAddress;
use App\Models\Invoice;
use App\Models\TaxDeterminationResult;
use Illuminate\Support\Str;

/**
 * Builds a canonical TaxDeterminationResult from an Invoice's lines
 * and customer context, then persists it to tax_determination_results.
 *
 * The persisted result is passed to PostingEngine which writes
 * tax_transaction_details with the correct rate, treatment, and regime
 * per ADR-005 §26.2 and Issue #42.
 */
class TaxDeterminationService
{
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
     * Returns the array format expected by PostingEngine::post()
     * via its taxDeterminationResult parameter.
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

        // Build per-line tax determinations
        $taxLines = [];
        $totalTaxable = 0;
        $totalTax = 0;

        foreach ($invoice->lines as $line) {
            $taxRate = (float) ($line->tax_rate ?? 0);

            // line_total always includes tax (see InvoiceLine::calculateTotals),
            // so taxable base is line_total minus the already-computed tax_amount.
            $taxableBase = (float) $line->line_total - (float) $line->tax_amount;

            // Apply line-level discount to taxable base
            if ((float) ($line->discount_amount ?? 0) > 0) {
                $taxableBase = max($taxableBase - (float) $line->discount_amount, 0);
            }

            $taxAmount = round($taxableBase * $taxRate / 100, 2);
            $treatment = match (true) {
                $taxRate > 0 => 'standard_rated',
                $taxRate === 0.0 && ($overrides['treatment'] ?? null) !== 'exempt' => 'zero_rated',
                default => 'exempt',
            };

            // Resolve tax_code from product or build from jurisdiction+rate
            $taxCode = $this->resolveTaxCode(
                $line->product ?? null,
                $jurisdiction,
                $regime,
                $taxRate,
            );

            $taxLines[] = [
                'tax_code' => $taxCode,
                'taxable_base' => $taxableBase,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'treatment' => $treatment,
                'source_line_id' => $line->id,
            ];

            $totalTaxable += $taxableBase;
            $totalTax += $taxAmount;
        }

        $determinationId = (string) Str::uuid();
        $resultVersion = 1;

        // Check if an existing determination exists for this invoice — bump version
        $existing = TaxDeterminationResult::where('source_record_type', 'invoice')
            ->where('source_record_id', $invoice->id)
            ->current()
            ->first();
        if ($existing) {
            $determinationId = $existing->correlation_id ?? $determinationId;
            $resultVersion = $existing->result_version + 1;
        }

        // Persist the determination result
        TaxDeterminationResult::create([
            'tenant_id' => tenant('id'),
            'entity_id' => null,
            'result_version' => $resultVersion,
            'jurisdiction' => $jurisdiction,
            'transaction_type' => 'invoice',
            'transaction_date' => $transactionDate,
            'tax_rule_refs' => collect($taxLines)->pluck('tax_code')->values()->all(),
            'tax_lines' => $taxLines,
            'rounding_method' => 'half_up',
            'calculation_basis' => 'per_line',
            'result_status' => 'DETERMINED',
            'determined_at' => now(),
            'source_record_type' => 'invoice',
            'source_record_id' => $invoice->id,
            'correlation_id' => $determinationId,
        ]);

        return [
            'id' => $determinationId,
            'result_version' => $resultVersion,
            'jurisdiction' => $jurisdiction,
            'tax_regime' => $regime,
            'result_status' => 'DETERMINED',
            'tax_rate' => count($taxLines) === 1
                ? (float) $taxLines[0]['tax_rate']
                : 0,
            'taxable_base' => $totalTaxable,
            'tax_amount' => $totalTax,
            'treatment' => count($taxLines) === 1
                ? $taxLines[0]['treatment']
                : 'standard_rated',
            'tax_lines' => $taxLines,
        ];
    }

    /**
     * Resolve the jurisdiction from the invoice's customer billing address.
     */
    private function resolveJurisdiction(Invoice $invoice): string
    {
        $customerId = $invoice->customer_id;

        if ($customerId) {
            $billing = CustomerAddress::where('customer_id', $customerId)
                ->where('type', 'billing')
                ->where('is_default', true)
                ->first();

            if ($billing && ! empty($billing->country)) {
                $code = strtoupper(trim($billing->country));

                return self::COUNTRY_JURISDICTION[$code] ?? $code;
            }
        }

        // Fallback: tenant-level default or SG
        return config('app.tax_default_jurisdiction', 'SG');
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
        $rateInt = (int) round($taxRate);

        return "{$jurisdiction}-{$regime}-{$rateInt}";
    }
}
