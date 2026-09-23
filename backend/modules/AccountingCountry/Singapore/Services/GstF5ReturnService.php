<?php

namespace Modules\AccountingCountry\Singapore\Services;

use Modules\AccountingCountry\Services\TaxReturnAggregator;

/**
 * Issue #93: Singapore GST F5 return generator.
 *
 * Aggregates the tax audit data written by PostingEngine into the IRAS F5 box
 * structure (standard-rated / zero-rated / exempt supplies, taxable purchases,
 * output tax, input tax and the net GST position).
 */
class GstF5ReturnService
{
    public function __construct(
        private readonly TaxReturnAggregator $aggregator,
    ) {}

    /**
     * @param  string  $period  YYYY-MM or YYYY-Qn (F5 is normally quarterly).
     * @param  int|null  $legalEntityId  Scope the return to one legal entity.
     */
    public function generate(string $period, ?int $legalEntityId = null): array
    {
        $taxes = $this->aggregator->aggregate($period, $legalEntityId);

        $sumBy = function (array $rows, string $field, ?string $category = null): float {
            return round(array_sum(array_map(
                fn (array $row) => $category === null || $row['category'] === $category ? (float) $row[$field] : 0.0,
                $rows,
            )), 2);
        };

        $standardRatedSupplies = $sumBy($taxes['output'], 'taxable_amount', 'standard_rated');
        $zeroRatedSupplies = $sumBy($taxes['output'], 'taxable_amount', 'zero_rated');
        $exemptSupplies = $sumBy($taxes['output'], 'taxable_amount', 'exempt');
        // IRAS F5 Box 4 is "Total supplies (1)+(2)+(3)" — not out-of-scope.
        // Hostile re-review C-4.
        $totalSupplies = round($standardRatedSupplies + $zeroRatedSupplies + $exemptSupplies, 2);

        $taxablePurchases = round(array_sum(array_map(
            fn (array $row) => in_array($row['category'], ['standard_rated', 'zero_rated'], true)
                ? (float) $row['taxable_amount']
                : 0.0,
            $taxes['input'],
        )), 2);

        $outputTax = $sumBy($taxes['output'], 'tax_amount');
        $inputTax = $sumBy($taxes['input'], 'tax_amount');

        return [
            'form' => 'GST F5',
            'jurisdiction' => 'SG',
            'authority' => 'IRAS',
            'period' => $period,
            'currency' => 'SGD',
            'boxes' => [
                'box_1_standard_rated_supplies' => $standardRatedSupplies,
                'box_2_zero_rated_supplies' => $zeroRatedSupplies,
                'box_3_exempt_supplies' => $exemptSupplies,
                'box_4_total_supplies' => $totalSupplies,
                'box_5_taxable_purchases' => $taxablePurchases,
                'box_6_output_tax_due' => $outputTax,
                'box_7_input_tax_and_refunds' => $inputTax,
                'box_8_net_gst_payable' => round($outputTax - $inputTax, 2),
            ],
            'total_supplies' => $totalSupplies,
        ];
    }
}
