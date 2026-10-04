<?php

namespace Modules\AccountingCountry\Singapore\Services;

use Illuminate\Support\Facades\DB;
use Modules\AccountingCountry\Services\TaxAuditExportService;
use Modules\AccountingCountry\Services\TaxReportingService;
use Modules\AccountingCountry\Services\TaxReturnAggregator;

/**
 * #861 (RP7): SG IRAS audit file (IAF) data export.
 *
 * IRAS may request a GST-registered business's transaction data as an Audit
 * File: company information plus supply, purchase and GL listings. The
 * official IRAS XML schema is not bundled in this repository, so this service
 * emits a data-driven audit listing built from the posted tax trail
 * (`tax_transaction_details`), the ledger and the source-document references —
 * see TaxAuditExportService::IAF_SPEC_DISCLOSURE.
 *
 * Both the trail listings and the GL listing are routed through the shared
 * #860 lifecycle scope (TaxReturnAggregator::applyLiveLifecycle) so the audit
 * file can never disagree with the tax return or the reconciliation.
 */
class SgIafExportService
{
    public function __construct(
        private readonly TaxReturnAggregator $aggregator,
        private readonly TaxAuditExportService $support,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $period, ?int $legalEntityId): array
    {
        $months = TaxReportingService::expandFilingPeriod($period, 'SG') ?: [$period];

        $rows = $this->aggregator->drilldown($period, $legalEntityId, null, null, null, 'SG');
        $meta = $this->support->entryMeta(array_column($rows, 'journal_entry_id'));

        $supply = [];
        $purchase = [];

        foreach ($rows as $row) {
            $entry = $meta[$row['journal_entry_id']] ?? [];

            $listing = [
                'reporting_period' => $row['reporting_period'],
                'entry_date' => $entry['entry_date'] ?? null,
                'entry_number' => $entry['entry_number'] ?? null,
                'source_document_type' => $row['source_document_type'],
                'source_document_id' => $row['source_document_id'],
                'journal_entry_id' => $row['journal_entry_id'],
                'tax_determination_id' => $row['tax_determination_id'],
                'tax_code' => $row['tax_code'],
                'tax_rate' => $row['tax_rate'],
                'tax_treatment' => $row['tax_treatment'],
                'tax_regime' => $row['tax_regime'],
                'category' => $row['category'],
                'taxable_amount' => $row['taxable_amount'],
                'tax_amount' => $row['tax_amount'],
                'currency_code' => $row['currency_code'],
                'exchange_rate' => $row['exchange_rate'],
            ];

            if ($row['direction'] === 'output') {
                $supply[] = $listing;
            } elseif ($row['direction'] === 'input') {
                $purchase[] = $listing;
            }
        }

        $gl = $this->glListing($months, $legalEntityId);

        return [
            'form' => 'SG IRAS audit file (IAF) listings',
            'jurisdiction' => 'SG',
            'authority' => 'IRAS',
            'period' => $period,
            'reporting_period' => [
                'period' => $period,
                'months' => $months,
                'legal_entity_id' => $legalEntityId,
            ],
            'company' => $this->support->company($legalEntityId),
            'supply_listing' => $supply,
            'purchase_listing' => $purchase,
            'gl_listing' => $gl,
            'totals' => [
                'supply_taxable' => round(array_sum(array_column($supply, 'taxable_amount')), 2),
                'supply_tax' => round(array_sum(array_column($supply, 'tax_amount')), 2),
                'purchase_taxable' => round(array_sum(array_column($purchase, 'taxable_amount')), 2),
                'purchase_tax' => round(array_sum(array_column($purchase, 'tax_amount')), 2),
                'gl_debit' => round(array_sum(array_column($gl, 'debit')), 2),
                'gl_credit' => round(array_sum(array_column($gl, 'credit')), 2),
            ],
            'lifecycle_scope' => TaxAuditExportService::LIFECYCLE_SCOPE,
            'spec_disclosure' => TaxAuditExportService::IAF_SPEC_DISCLOSURE,
        ];
    }

    /**
     * The entity's general ledger lines for the period — the same posted and
     * #860 lifecycle rules as the tax reports.
     *
     * @param  array<int, string>  $months
     * @return array<int, array<string, mixed>>
     */
    private function glListing(array $months, ?int $legalEntityId): array
    {
        if ($months === []) {
            return [];
        }

        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->tap(fn ($query) => TaxReturnAggregator::applyLiveLifecycle($query))
            ->whereRaw("to_char(je.entry_date, 'YYYY-MM') IN (".implode(',', array_fill(0, count($months), '?')).')', $months)
            ->when($legalEntityId !== null, fn ($query) => $query->where('je.legal_entity_id', $legalEntityId))
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->orderBy('jel.id')
            ->get([
                'je.id as journal_entry_id',
                'je.entry_number',
                'je.entry_date',
                'je.description as entry_description',
                'coa.code as account_code',
                'coa.name as account_name',
                'jel.description as line_description',
                'jel.debit',
                'jel.credit',
                'jel.tax_code',
            ]);

        return array_map(fn ($row) => [
            'entry_date' => $row->entry_date !== null ? (string) $row->entry_date : null,
            'entry_number' => $row->entry_number !== null ? (string) $row->entry_number : null,
            'journal_entry_id' => (int) $row->journal_entry_id,
            'account_code' => (string) $row->account_code,
            'account_name' => (string) $row->account_name,
            'description' => $row->line_description ?? $row->entry_description,
            'debit' => round((float) $row->debit, 2),
            'credit' => round((float) $row->credit, 2),
            'tax_code' => $row->tax_code,
        ], $rows->all());
    }

    /**
     * CSV convenience export: the supply, purchase and GL listings in one
     * table with a `section` discriminator. Company metadata and the written
     * disclosures ride in the JSON export.
     */
    public function toCsv(array $data): string
    {
        $headers = [
            'section', 'entry_date', 'entry_number', 'reporting_period',
            'source_document_type', 'source_document_id', 'journal_entry_id',
            'account_code', 'account_name', 'description',
            'tax_code', 'tax_rate', 'tax_treatment', 'tax_regime', 'category',
            'taxable_amount', 'tax_amount', 'debit', 'credit',
            'currency_code', 'exchange_rate', 'tax_determination_id',
        ];

        $rows = [];

        foreach (['SUPPLY' => 'supply_listing', 'PURCHASE' => 'purchase_listing'] as $section => $key) {
            foreach ($data[$key] as $row) {
                $rows[] = ['section' => $section] + $row;
            }
        }

        foreach ($data['gl_listing'] as $row) {
            $rows[] = ['section' => 'GL'] + $row;
        }

        return $this->support->csv($headers, $rows);
    }
}
