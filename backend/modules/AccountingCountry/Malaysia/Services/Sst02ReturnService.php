<?php

namespace Modules\AccountingCountry\Malaysia\Services;

use Modules\AccountingCountry\Services\TaxReturnAggregator;

/**
 * Issue #93: Malaysia SST-02 return generator.
 *
 * Aggregates the tax audit data written by PostingEngine into the RMCD SST-02
 * structure: sales tax and service tax payable (taxable value + tax by rate),
 * and the total tax payable.
 */
class Sst02ReturnService
{
    public function __construct(
        private readonly TaxReturnAggregator $aggregator,
    ) {}

    /**
     * @param  string  $period  YYYY-MM, YYYY-Qn or YYYY-Bn.
     * @param  int|null  $legalEntityId  Scope the return to one legal entity.
     */
    public function generate(string $period, ?int $legalEntityId = null): array
    {
        $taxes = $this->aggregator->aggregate($period, $legalEntityId, 'MY');

        // MY SST is a single-stage tax: output-side rows only (no input credit).
        //
        // MRD D9 / R5.2: buckets are classified by the RESOLVED RULE's type
        // (`taxes.type === 'service'|'sales'`, carried via tax_rule_id), NOT
        // by a substring guess on the code. Rows with no rule reference fall
        // back to the legacy heuristic and are surfaced so an accountant can
        // remap them to a coded rule.
        $ruleTypes = \App\Models\Tax::query()
            ->whereIn('id', array_values(array_unique(array_filter(array_map(
                fn (array $r) => $r['tax_rule_id'] ?? null,
                $taxes['output'],
            )))))
            ->pluck('type', 'id');

        $salesTaxRows = [];
        $serviceTaxRows = [];
        // #741 T8: EXPLICIT classification — sales -> sales tax, service ->
        // service tax, anything else (gst, tax, withholding, ...) is
        // unclassified and blocks filing; it is never guessed into a bucket.
        // Rows with no rule reference keep the legacy heuristic (#308 owns
        // the matched-rule gap) and are counted separately.
        $unclassifiedRows = 0;
        $unclassifiedLegacyRows = 0;

        foreach ($taxes['output'] as $row) {
            $ruleType = $row['tax_rule_id'] !== null ? strtolower((string) ($ruleTypes[$row['tax_rule_id']] ?? '')) : '';

            if ($ruleType !== '') {
                match ($ruleType) {
                    'service' => $serviceTaxRows[] = $row,
                    'sales' => $salesTaxRows[] = $row,
                    default => $unclassifiedRows++,
                };

                continue;
            }

            // Legacy fallback: no rule reference on the trail row.
            $unclassifiedLegacyRows++;
            $regime = strtolower((string) ($row['tax_regime'] ?? '').' '.(string) ($row['tax_code'] ?? ''));

            if (str_contains($regime, 'service') || str_contains($regime, 'svc')) {
                $serviceTaxRows[] = $row;
            } else {
                $salesTaxRows[] = $row;
            }
        }

        $byRate = function (array $rows): array {
            $buckets = [];
            foreach ($rows as $row) {
                $rate = $row['tax_rate'] !== null ? rtrim(rtrim(number_format((float) $row['tax_rate'], 2, '.', ''), '0'), '.') : 'other';
                $buckets[$rate] ??= ['rate' => $rate, 'taxable_value' => 0.0, 'tax_amount' => 0.0];
                $buckets[$rate]['taxable_value'] += (float) $row['taxable_amount'];
                $buckets[$rate]['tax_amount'] += (float) $row['tax_amount'];
            }

            return array_values(array_map(function (array $bucket) {
                $bucket['taxable_value'] = round($bucket['taxable_value'], 2);
                $bucket['tax_amount'] = round($bucket['tax_amount'], 2);

                return $bucket;
            }, $buckets));
        };

        $salesTaxByRate = $byRate($salesTaxRows);
        $serviceTaxByRate = $byRate($serviceTaxRows);

        $salesTaxDue = round(array_sum(array_column($salesTaxByRate, 'tax_amount')), 2);
        $serviceTaxDue = round(array_sum(array_column($serviceTaxByRate, 'tax_amount')), 2);

        return [
            'form' => 'SST-02',
            'jurisdiction' => 'MY',
            'authority' => 'RMCD',
            'period' => $period,
            'currency' => 'MYR',
            'unassigned' => $taxes['unassigned'] ?? ['rows' => 0],
            'sales_tax' => [
                'taxable_value' => round(array_sum(array_column($salesTaxByRate, 'taxable_value')), 2),
                'by_rate' => $salesTaxByRate,
                'tax_due' => $salesTaxDue,
            ],
            'service_tax' => [
                'taxable_value' => round(array_sum(array_column($serviceTaxByRate, 'taxable_value')), 2),
                'by_rate' => $serviceTaxByRate,
                'tax_due' => $serviceTaxDue,
            ],
            'total_tax_payable' => round($salesTaxDue + $serviceTaxDue, 2),
            'classification' => [
                'method' => 'rule_taxonomy',
                'unclassified_rows' => $unclassifiedRows,
                'unclassified_legacy_rows' => $unclassifiedLegacyRows,
            ],
        ];
    }
}
