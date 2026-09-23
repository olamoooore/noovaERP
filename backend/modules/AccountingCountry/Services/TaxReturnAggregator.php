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
        'invoice', 'sales_invoice', 'credit_note', 'pos_order', 'web_order',
    ];

    private const PURCHASE_DOCUMENTS = [
        'bill', 'purchase_invoice', 'vendor_credit_note', 'goods_receipt', 'expense',
    ];

    private const NEGATIVE_DOCUMENTS = ['credit_note', 'vendor_credit_note'];

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

        foreach ($rows as $row) {
            $direction = $this->direction((string) $row->source_document_type);

            if ($direction === null) {
                continue;
            }

            $sign = in_array((string) $row->source_document_type, self::NEGATIVE_DOCUMENTS, true) ? -1 : 1;

            $result[$direction][] = [
                'source_document_type' => $row->source_document_type,
                'source_document_id' => $row->source_document_id,
                'tax_code' => $row->tax_code,
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
     * @return array<int, string>|null  null when the period is malformed.
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
