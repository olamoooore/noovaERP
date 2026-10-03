<?php

namespace App\Console\Commands;

use App\Accounting\Services\ComplianceService;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\CountryConfiguration;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\AccountingCountry\Malaysia\Services\MyInvoiceMapper;

/**
 * #815 (EP4) AC2 — build and submit the monthly consolidated B2C e-invoice
 * from the receipts the AC1 gate held as `queued_for_consolidation`, then link
 * every receipt to the consolidated document (whose submission carries the
 * LHDN UUID).
 *
 * Run after month end. LHDN's submission deadline is not encoded here — no
 * official source is in the repository — so the command submits as early as
 * possible after the month closes rather than inventing a date.
 */
class ConsolidateB2CCommand extends Command
{
    protected $signature = 'compliance:consolidate-b2c
        {--month= : Month to consolidate (YYYY-MM; default: the previous month)}';

    protected $description = 'Build and submit the monthly consolidated B2C e-invoice from held receipts (#815 AC2).';

    public function handle(): int
    {
        $month = (string) ($this->option('month') ?: now()->subMonthNoOverflow()->format('Y-m'));
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $this->error("Invalid --month '{$month}' — expected YYYY-MM.");

            return self::FAILURE;
        }

        try {
            $monthStart = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        } catch (\Throwable) {
            $this->error("Invalid --month '{$month}' — expected YYYY-MM.");

            return self::FAILURE;
        }
        $monthEnd = $monthStart->copy()->endOfMonth();

        $linkedTotal = 0;

        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $configs = CountryConfiguration::query()
                    ->where('is_enabled', true)
                    ->get()
                    ->filter(fn (CountryConfiguration $config) => ($config->settings['b2c_consolidation'] ?? false) === true);

                foreach ($configs as $config) {
                    $countryCode = strtoupper((string) $config->country_code);

                    // Only receipts held for consolidation in this month and
                    // country; once linked they leave queued_for_consolidation
                    // so a later run cannot double-report them.
                    $held = ComplianceDocument::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('country_code', $countryCode)
                        ->where('document_type', 'invoice')
                        ->where('compliance_status', 'queued_for_consolidation')
                        ->whereBetween('issued_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                        ->orderBy('id')
                        ->get();

                    if ($held->isEmpty()) {
                        continue;
                    }

                    $linked = $this->consolidate($config, $countryCode, $month, $monthEnd, $held);
                    $linkedTotal += $linked;
                }
            } catch (\Throwable $e) {
                Log::warning('B2C consolidation failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'month' => $month,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Tenant {$tenant->id}: {$e->getMessage()}");
            } finally {
                tenancy()->end();
            }
        }

        $this->info("B2C consolidation for {$month} complete: {$linkedTotal} receipt(s) consolidated.");

        return self::SUCCESS;
    }

    /**
     * Build one consolidated document for the held receipts, submit it, and
     * link the receipts to it on success.
     *
     * @param  Collection<int, ComplianceDocument>  $held
     */
    private function consolidate(
        CountryConfiguration $config,
        string $countryCode,
        string $month,
        Carbon $monthEnd,
        Collection $held,
    ): int {
        $sequence = ComplianceDocument::query()
            ->where('tenant_id', $config->tenant_id)
            ->where('document_number', 'like', 'CONS-'.str_replace('-', '', $month).'-%')
            ->count() + 1;
        $documentNumber = sprintf('CONS-%s-%06d', str_replace('-', '', $month), $sequence);

        [$synthetic, $totals] = $this->buildConsolidatedInvoice($held, $monthEnd, $documentNumber);

        $document = ComplianceDocument::create([
            'tenant_id' => $config->tenant_id,
            'country_code' => $countryCode,
            'document_type' => 'invoice',
            // Consolidated: no single source document; receipts link back via
            // consolidated_document_id.
            'document_id' => 0,
            'document_number' => $documentNumber,
            'issued_date' => $monthEnd->toDateString(),
            'total_amount' => $totals['gross'],
            'currency_code' => $totals['currency'],
            'compliance_status' => 'pending',
        ]);

        $result = app(ComplianceService::class)->submitComplianceDocument($document, $countryCode, $synthetic);

        if (! ($result['success'] ?? false)) {
            $document->update([
                'compliance_status' => 'failed',
                'compliance_notes' => 'B2C consolidation submission failed: '.($result['message'] ?? 'unknown error'),
            ]);
            $this->warn("Tenant {$config->tenant_id} {$countryCode} {$month}: {$result['message']}");

            return 0;
        }

        foreach ($held as $receipt) {
            $receipt->update([
                'compliance_status' => 'consolidated',
                'consolidated_document_id' => $document->id,
            ]);
        }

        $this->info(sprintf(
            'Tenant %s %s %s: consolidated %d receipt(s) into %s (total %s).',
            $config->tenant_id,
            $countryCode,
            $month,
            $held->count(),
            $documentNumber,
            $totals['gross']
        ));

        return $held->count();
    }

    /**
     * The consolidated invoice is a synthetic (unsaved) model: the mapper reads
     * its `lines` relation and the general-public customer, and the tenant
     * supplier identity is overlaid by the pack from the provider context.
     *
     * @param  Collection<int, ComplianceDocument>  $held
     * @return array{0: Invoice, 1: array{gross: float, currency: string}}
     */
    private function buildConsolidatedInvoice(Collection $held, Carbon $monthEnd, string $documentNumber): array
    {
        $lines = collect();
        $subtotal = 0.0;
        $tax = 0.0;
        $gross = 0.0;
        $currency = 'MYR';

        foreach ($held as $index => $document) {
            $receipt = $document->document_id ? Invoice::find($document->document_id) : null;

            $net = round((float) ($receipt?->subtotal ?? $document->total_amount), 2);
            $taxAmount = round((float) ($receipt?->tax_amount ?? 0), 2);
            $total = round((float) ($receipt?->total ?? $document->total_amount), 2);
            $taxRate = $net > 0 ? round(($taxAmount / $net) * 100, 2) : 0.0;

            $line = new InvoiceLine;
            $line->setRawAttributes([
                'id' => $document->document_id ?: $index + 1,
                'description' => (string) $document->document_number,
                'quantity' => 1,
                'unit_price' => $net,
                'discount_amount' => 0,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
            ], true);
            // No product to resolve; the pack's default classification applies.
            $line->setRelation('product', null);
            $lines->push($line);

            $subtotal += $net;
            $tax += $taxAmount;
            $gross += $total;
            $currency = $document->currency_code ?: $currency;
        }

        $invoice = new Invoice;
        $invoice->setRawAttributes([
            // id 0: no real invoice row; keeps payload validation happy (isset)
            // and isolates the mapper tax-determination lookup from real ids.
            'id' => 0,
            'invoice_number' => $documentNumber,
            'invoice_date' => $monthEnd->toDateString(),
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($tax, 2),
            'total' => round($gross, 2),
            'discount_amount' => 0,
            'paid_amount' => 0,
            'currency_code' => $currency,
        ], true);
        $invoice->setRelation('lines', $lines);

        // The general-public TIN is exactly what makes this a consolidated
        // invoice (and keeps it out of the AC1 queue gate).
        $invoice->setRelation('customer', (object) [
            'name' => 'General Public',
            'type' => 'individual',
            'tax_number' => MyInvoiceMapper::LHDN_GENERAL_PUBLIC_TIN,
        ]);

        return [$invoice, ['gross' => round($gross, 2), 'currency' => $currency]];
    }
}
