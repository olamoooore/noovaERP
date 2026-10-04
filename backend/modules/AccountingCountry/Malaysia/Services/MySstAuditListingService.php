<?php

namespace Modules\AccountingCountry\Malaysia\Services;

use App\Models\Tax;
use Illuminate\Support\Facades\DB;
use Modules\AccountingCountry\Services\TaxAuditExportService;
use Modules\AccountingCountry\Services\TaxReportingService;
use Modules\AccountingCountry\Services\TaxReturnAggregator;

/**
 * #861 (RP7): MY SST audit listing — taxable supplies by tax code with the
 * source documents behind each figure, plus the exempt/zero-rated supplies
 * with the exemption references the platform records.
 *
 * MY SST is single-stage: the listing covers output-side (supply) rows, the
 * same rows the SST-02 return files. Classification metadata (rule type:
 * sales / service) is carried through from the resolved rule; the listing
 * never re-guesses the category from code substrings.
 *
 * The trail path is TaxReturnAggregator::drilldown(), so the listing shares
 * the #860 lifecycle scope with the return and reconciliation.
 */
class MySstAuditListingService
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
        $months = TaxReportingService::expandFilingPeriod($period, 'MY') ?: [$period];

        $rows = $this->aggregator->drilldown($period, $legalEntityId, 'output', null, null, 'MY');
        $meta = $this->support->entryMeta(array_column($rows, 'journal_entry_id'));
        $exemption = $this->exemptionMeta($rows);
        $ruleTypes = $this->ruleTypes($rows);

        $enriched = [];

        foreach ($rows as $row) {
            $entry = $meta[$row['journal_entry_id']] ?? [];
            $exempt = $row['source_document_type'] === 'invoice'
                ? ($exemption[(int) $row['source_document_id']] ?? [])
                : [];

            $enriched[] = [
                'reporting_period' => $row['reporting_period'],
                'entry_date' => $entry['entry_date'] ?? null,
                'entry_number' => $entry['entry_number'] ?? null,
                'source_document_type' => $row['source_document_type'],
                'source_document_id' => $row['source_document_id'],
                'document_number' => $exempt['document_number'] ?? null,
                'journal_entry_id' => $row['journal_entry_id'],
                'tax_determination_id' => $row['tax_determination_id'],
                'tax_code' => $row['tax_code'],
                'tax_rate' => $row['tax_rate'],
                'tax_treatment' => $row['tax_treatment'],
                'tax_regime' => $row['tax_regime'],
                'tax_rule_type' => $row['tax_rule_id'] !== null ? ($ruleTypes[$row['tax_rule_id']] ?? null) : null,
                'category' => $row['category'],
                'taxable_amount' => $row['taxable_amount'],
                'tax_amount' => $row['tax_amount'],
                'currency_code' => $row['currency_code'],
                'exchange_rate' => $row['exchange_rate'],
                'exemption_code' => $exempt['exemption_code'] ?? null,
                'exemption_reason' => $exempt['exemption_reason'] ?? null,
                'exemption_reference' => $exempt['exemption_reference'] ?? null,
                'exemption_expiry' => $exempt['exemption_expiry'] ?? null,
            ];
        }

        $byCode = [];

        foreach ($enriched as $row) {
            $code = (string) ($row['tax_code'] ?? '');
            $key = $code === '' ? '(uncoded)' : $code;

            $byCode[$key] ??= [
                'tax_code' => $code === '' ? null : $code,
                'tax_rate' => $row['tax_rate'],
                'tax_treatment' => $row['tax_treatment'],
                'tax_regime' => $row['tax_regime'],
                'tax_rule_type' => $row['tax_rule_type'],
                'rows' => 0,
                'taxable_amount' => 0.0,
                'tax_amount' => 0.0,
                'documents' => [],
            ];

            $byCode[$key]['rows']++;
            $byCode[$key]['taxable_amount'] = round($byCode[$key]['taxable_amount'] + (float) $row['taxable_amount'], 2);
            $byCode[$key]['tax_amount'] = round($byCode[$key]['tax_amount'] + (float) $row['tax_amount'], 2);
            $byCode[$key]['documents'][] = [
                'source_document_type' => $row['source_document_type'],
                'source_document_id' => $row['source_document_id'],
                'document_number' => $row['document_number'],
                'journal_entry_id' => $row['journal_entry_id'],
                'reporting_period' => $row['reporting_period'],
            ];
        }

        $exemptions = array_values(array_filter(
            $enriched,
            fn (array $row) => in_array($row['category'], ['exempt', 'zero_rated'], true),
        ));

        return [
            'form' => 'MY SST transaction listing',
            'jurisdiction' => 'MY',
            'authority' => 'RMCD',
            'period' => $period,
            'reporting_period' => [
                'period' => $period,
                'months' => $months,
                'legal_entity_id' => $legalEntityId,
            ],
            'company' => $this->support->company($legalEntityId),
            'rows' => $enriched,
            'by_tax_code' => array_values($byCode),
            'exemptions' => $exemptions,
            'totals' => [
                'taxable_amount' => round(array_sum(array_column($enriched, 'taxable_amount')), 2),
                'tax_amount' => round(array_sum(array_column($enriched, 'tax_amount')), 2),
                'exempt_taxable_amount' => round(array_sum(array_column($exemptions, 'taxable_amount')), 2),
            ],
            'lifecycle_scope' => TaxAuditExportService::LIFECYCLE_SCOPE,
            'spec_disclosure' => TaxAuditExportService::SST_SPEC_DISCLOSURE,
        ];
    }

    /**
     * Resolved rule types (sales / service / ...) keyed by tax rule id — the
     * same taxonomy Sst02ReturnService files by.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function ruleTypes(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (array $row) => $row['tax_rule_id'] ?? null,
            $rows,
        ))));

        if ($ids === []) {
            return [];
        }

        return Tax::query()->whereIn('id', $ids)->pluck('type', 'id')
            ->mapWithKeys(fn ($type, $id) => [(int) $id => (string) $type])
            ->all();
    }

    /**
     * Exemption evidence per invoice id: the invoice's own exemption code /
     * reason, falling back to the customer's contact tax profile (reason,
     * tax id, expiry). The repo has no certificate-number column — the tax id
     * is surfaced as the reference the platform holds.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function exemptionMeta(array $rows): array
    {
        $invoiceIds = [];

        foreach ($rows as $row) {
            if ($row['source_document_type'] === 'invoice' && (int) $row['source_document_id'] > 0) {
                $invoiceIds[] = (int) $row['source_document_id'];
            }
        }

        $invoiceIds = array_values(array_unique($invoiceIds));

        if ($invoiceIds === []) {
            return [];
        }

        $invoices = DB::table('invoices')
            ->whereIn('id', $invoiceIds)
            ->get(['id', 'invoice_number', 'tax_exemption_code', 'tax_exemption_reason', 'customer_id'])
            ->keyBy('id');

        $profiles = [];

        $customerIds = array_values(array_unique(array_filter(
            $invoices->pluck('customer_id')->map(fn ($id) => (int) $id)->all(),
            fn (int $id) => $id > 0,
        )));

        if ($customerIds !== []) {
            $contactIds = DB::table('customers')
                ->whereIn('id', $customerIds)
                ->whereNotNull('core_contact_uuid')
                ->pluck('core_contact_uuid', 'id');

            $uuids = array_values(array_filter($contactIds->all()));

            if ($uuids !== []) {
                $byContact = [];

                $profileRows = DB::table('contact_tax_profiles')
                    ->whereIn('contact_id', $uuids)
                    ->orderBy('id')
                    ->get(['contact_id', 'tax_id', 'exemption_reason', 'exemption_expiry']);

                foreach ($profileRows as $profile) {
                    $byContact[(string) $profile->contact_id] = $profile;
                }

                foreach ($contactIds as $customerId => $uuid) {
                    if (isset($byContact[(string) $uuid])) {
                        $profiles[(int) $customerId] = $byContact[(string) $uuid];
                    }
                }
            }
        }

        $meta = [];

        foreach ($invoices as $id => $invoice) {
            $profile = $profiles[(int) ($invoice->customer_id ?? 0)] ?? null;

            $meta[(int) $id] = [
                'document_number' => $invoice->invoice_number,
                'exemption_code' => $invoice->tax_exemption_code,
                'exemption_reason' => filled($invoice->tax_exemption_reason)
                    ? (string) $invoice->tax_exemption_reason
                    : ($profile->exemption_reason ?? null),
                'exemption_reference' => $profile->tax_id ?? null,
                'exemption_expiry' => isset($profile) && $profile->exemption_expiry !== null
                    ? (string) $profile->exemption_expiry
                    : null,
            ];
        }

        return $meta;
    }

    /**
     * CSV convenience export: every listing row, with the exempt/zero-rated
     * rows additionally flagged as EXEMPTION rows.
     */
    public function toCsv(array $data): string
    {
        $headers = [
            'section', 'reporting_period', 'entry_date', 'entry_number',
            'source_document_type', 'source_document_id', 'document_number',
            'journal_entry_id', 'tax_code', 'tax_rate', 'tax_regime',
            'tax_treatment', 'tax_rule_type', 'category',
            'taxable_amount', 'tax_amount', 'currency_code', 'exchange_rate',
            'exemption_code', 'exemption_reason', 'exemption_reference',
            'exemption_expiry', 'tax_determination_id',
        ];

        $rows = [];

        foreach ($data['rows'] as $row) {
            $section = in_array($row['category'], ['exempt', 'zero_rated'], true) ? 'EXEMPTION' : 'SUPPLY';
            $rows[] = ['section' => $section] + $row;
        }

        return $this->support->csv($headers, $rows);
    }
}
