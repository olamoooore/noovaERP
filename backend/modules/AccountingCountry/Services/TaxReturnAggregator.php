<?php

namespace Modules\AccountingCountry\Services;

use Illuminate\Support\Facades\DB;

/**
 * Issue #93: shared aggregation of the tax audit data written by
 * PostingEngine::writeTaxTransactionDetails() into the direction and supply
 * category the statutory return generators need.
 *
 * Direction: sales-side documents produce output tax, purchase-side documents
 * produce input tax. Credit notes carry negative amounts (they reverse tax).
 * Category: zero/exempt come from the persisted tax treatment; a positive rate
 * is standard-rated, otherwise out of scope.
 */
class TaxReturnAggregator
{
    private const SALES_DOCUMENTS = [
        'invoice', 'sales_invoice', 'credit_note', 'pos_order', 'web_order', 'storefront_order',
    ];

    private const PURCHASE_DOCUMENTS = [
        'bill', 'purchase_invoice', 'vendor_credit_note', 'vendor_debit_note', 'goods_receipt', 'expense',
    ];

    private const NEGATIVE_DOCUMENTS = ['credit_note', 'vendor_credit_note', 'vendor_debit_note'];

    /**
     * @param  string|array<int, string>  $period  One reporting period (YYYY-MM)
     *                                             or several (quarterly returns).
     * @param  int|null  $legalEntityId  Issue #93: scope the return to one
     *                                   legal entity so a multi-entity tenant
     *                                   does not file one combined return.
     * @return array{output: array<int, array<string, mixed>>, input: array<int, array<string, mixed>>}
     */
    public function aggregate(string|array $period, ?int $legalEntityId = null): array
    {
        $periods = is_array($period) ? array_values($period) : [$period];

        // Expand quarter periods (YYYY-Qn) into their months so a quarterly
        // return aggregates exactly those three reporting periods.
        $periods = array_merge(...array_map(
            fn (string $p) => self::periodMonths($p) ?? [$p],
            $periods
        ));

        // Hostile re-review (#93): a reversed journal entry must leave the
        // return. The reversal engine posts a separate reversal entry with
        // reversed_entry_id = original and marks it is_active_version=false,
        // while the original keeps its tax rows — so tax rows are only counted
        // when their journal entry is live: posted, not voided/superseded, and
        // not the target of a posted reversal.
        $rows = DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->where('je.status', 'posted')
            ->where(function ($query) {
                $query->whereNull('je.lifecycle_status')
                    ->orWhereNotIn('je.lifecycle_status', ['reversed', 'voided', 'superseded']);
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('journal_entries as rev')
                    ->whereColumn('rev.reversed_entry_id', 'ttd.journal_entry_id')
                    ->where('rev.status', 'posted');
            })
            ->whereIn('ttd.reporting_period', $periods)
            ->when(
                $legalEntityId !== null,
                fn ($query) => $query->where('je.legal_entity_id', $legalEntityId)
            )
            ->orderBy('ttd.id')
            ->get(['ttd.*']);

        $result = ['output' => [], 'input' => []];

        // MRD R4.5: withholding trail rows belong to WHT reporting, never
        // to GST/SST supply or claim buckets.
        $withholdingRuleIds = DB::table('taxes')->where('type', 'withholding')->pluck('id')->flip();

        foreach ($rows as $row) {
            if ($row->tax_rule_id !== null && isset($withholdingRuleIds[(int) $row->tax_rule_id])) {
                continue;
            }

            // MRD R4.3: an explicit row direction (reverse-charge pairs) wins
            // over the document-type default.
            $direction = $row->direction
                ? (in_array($row->direction, ['output', 'input'], true) ? $row->direction : null)
                : $this->direction((string) $row->source_document_type);

            if ($direction === null) {
                continue;
            }

            $sign = in_array((string) $row->source_document_type, self::NEGATIVE_DOCUMENTS, true) ? -1 : 1;

            $result[$direction][] = [
                'reporting_period' => $row->reporting_period,
                'source_document_type' => $row->source_document_type,
                'source_document_id' => $row->source_document_id,
                'tax_code' => $row->tax_code,
                'tax_rule_id' => $row->tax_rule_id !== null ? (int) $row->tax_rule_id : null,
                'tax_rate' => $row->tax_rate !== null ? (float) $row->tax_rate : null,
                'tax_treatment' => $row->tax_treatment,
                'tax_regime' => $row->tax_regime,
                'taxable_amount' => round($sign * (float) $row->taxable_amount, 2),
                'tax_amount' => round($sign * (float) $row->tax_amount, 2),
                'category' => $this->category($row),
            ];
        }

        return $result;
    }

    /**
     * Resolve a period string into the YYYY-MM months it covers.
     *
     * @return array<int, string>|null null when the period is malformed.
     */
    public static function periodMonths(string $period): ?array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1) {
            return [$period];
        }

        if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $m) === 1) {
            $year = (int) $m[1];
            $startMonth = ((int) $m[2] - 1) * 3 + 1;

            return array_map(
                fn (int $offset) => sprintf('%04d-%02d', $year, $startMonth + $offset),
                [0, 1, 2]
            );
        }

        return null;
    }

    /**
     * Periods that actually contain live tax activity, newest first. Returns
     * reporting periods from tax_transaction_details joined to the same live
     * journal-entry filter used by aggregate(), with signed output/input tax
     * totals per period so the UI can show filing-relevant data instead of
     * empty calendars.
     *
     * @return array<int, array{period: string, rows: int, output_tax: float, input_tax: float}>
     */
    public function availablePeriods(?int $legalEntityId = null): array
    {
        $rows = DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->where('je.status', 'posted')
            ->where(function ($query) {
                $query->whereNull('je.lifecycle_status')
                    ->orWhereNotIn('je.lifecycle_status', ['reversed', 'voided', 'superseded']);
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('journal_entries as rev')
                    ->whereColumn('rev.reversed_entry_id', 'ttd.journal_entry_id')
                    ->where('rev.status', 'posted');
            })
            ->when(
                $legalEntityId !== null,
                fn ($query) => $query->where('je.legal_entity_id', $legalEntityId)
            )
            ->groupBy('ttd.reporting_period', 'ttd.source_document_type')
            ->get([
                'ttd.reporting_period as period',
                'ttd.source_document_type',
                DB::raw('count(*) as rows'),
                DB::raw('sum(ttd.tax_amount) as tax_total'),
            ]);

        $periods = [];

        foreach ($rows as $row) {
            $period = (string) $row->period;
            $periods[$period] ??= ['period' => $period, 'rows' => 0, 'output_tax' => 0.0, 'input_tax' => 0.0];

            $direction = $this->direction((string) $row->source_document_type);
            if ($direction === null) {
                $periods[$period]['rows'] += (int) $row->rows;

                continue;
            }

            $sign = in_array((string) $row->source_document_type, self::NEGATIVE_DOCUMENTS, true) ? -1 : 1;

            $periods[$period]['rows'] += (int) $row->rows;
            $periods[$period][$direction.'_tax'] = round(
                $periods[$period][$direction.'_tax'] + $sign * (float) $row->tax_total,
                2,
            );
        }

        usort($periods, fn ($a, $b) => strcmp($b['period'], $a['period']));

        return array_values($periods);
    }

    private function direction(string $documentType): ?string
    {
        if (in_array($documentType, self::SALES_DOCUMENTS, true)) {
            return 'output';
        }

        if (in_array($documentType, self::PURCHASE_DOCUMENTS, true)) {
            return 'input';
        }

        return null;
    }

    private function category(object $row): string
    {
        $treatment = strtolower((string) ($row->tax_treatment ?? ''));

        if (str_contains($treatment, 'zero')) {
            return 'zero_rated';
        }

        if (str_contains($treatment, 'exempt')) {
            return 'exempt';
        }

        if ((float) ($row->tax_rate ?? 0) > 0) {
            return 'standard_rated';
        }

        return 'out_of_scope';
    }
}
