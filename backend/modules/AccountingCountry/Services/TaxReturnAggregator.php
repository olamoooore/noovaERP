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
     * @return array{output: array<int, array<string, mixed>>, input: array<int, array<string, mixed>>}
     */
    public function aggregate(string $period): array
    {
        $rows = DB::table('tax_transaction_details')
            ->where('reporting_period', $period)
            ->orderBy('id')
            ->get();

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
