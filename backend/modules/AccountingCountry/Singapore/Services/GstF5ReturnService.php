<?php

namespace Modules\AccountingCountry\Singapore\Services;

use App\Services\TaxPackRegistry;
use Illuminate\Support\Facades\DB;
use Modules\AccountingCountry\Services\TaxReportingService;
use Modules\AccountingCountry\Services\TaxReturnAggregator;

/**
 * Issue #93 + #745 T12: Singapore GST F5 return generator.
 *
 * Produces the FULL IRAS F5 box set (1-15), driven by the box definitions in
 * the SG country pack: computed boxes are derived from posted tax/revenue data,
 * manual boxes take operator input (recorded and audited at filing) and box 15
 * is a yes/no declaration. A form revision is then a pack data change, not a
 * deploy. Box numbering/wording is marked verified:false in the pack pending
 * confirmation against the current IRAS F5 form.
 */
class GstF5ReturnService
{
    public function __construct(
        private readonly TaxReturnAggregator $aggregator,
        private readonly TaxPackRegistry $registry,
    ) {}

    /**
     * @param  string  $period  YYYY-MM or YYYY-Qn (F5 is normally quarterly).
     * @param  int|null  $legalEntityId  Scope the return to one legal entity.
     * @param  array<string, mixed>  $boxInputs  Operator inputs for manual/declaration boxes.
     */
    public function generate(string $period, ?int $legalEntityId = null, array $boxInputs = []): array
    {
        $taxes = $this->aggregator->aggregate($period, $legalEntityId, 'SG');

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

        // #745 T12: computed box values keyed by pack box key.
        $computed = [
            'box_1_standard_rated_supplies' => $standardRatedSupplies,
            'box_2_zero_rated_supplies' => $zeroRatedSupplies,
            'box_3_exempt_supplies' => $exemptSupplies,
            'box_4_total_supplies' => $totalSupplies,
            'box_5_taxable_purchases' => $taxablePurchases,
            'box_6_output_tax_due' => $outputTax,
            'box_7_input_tax_and_refunds' => $inputTax,
            'box_8_net_gst_payable' => round($outputTax - $inputTax, 2),
            'box_13_revenue' => $this->revenueForPeriod($period, $legalEntityId),
        ];

        $definitions = $this->registry->formBoxes('SG');
        $boxes = [];
        $manualInputsNeeded = [];

        foreach ($definitions as $definition) {
            $key = (string) ($definition['key'] ?? '');
            $kind = (string) ($definition['kind'] ?? 'computed');

            if ($key === '') {
                continue;
            }

            if ($kind === 'manual') {
                $provided = array_key_exists($key, $boxInputs);
                $boxes[$key] = $provided ? round((float) $boxInputs[$key], 2) : 0.0;

                if (! $provided) {
                    $manualInputsNeeded[] = $key;
                }

                continue;
            }

            if ($kind === 'declaration') {
                $provided = array_key_exists($key, $boxInputs);
                $boxes[$key] = $provided ? (bool) $boxInputs[$key] : null;

                if (! $provided) {
                    $manualInputsNeeded[] = $key;
                }

                continue;
            }

            $boxes[$key] = $computed[$key] ?? 0.0;
        }

        // Defensive: a pack missing the box definitions must never silently
        // drop the core F5 boxes — fall back to the computed core set.
        if ($boxes === []) {
            $boxes = array_filter($computed, fn ($key) => ! str_starts_with($key, 'box_13'), ARRAY_FILTER_USE_KEY);
        }

        return [
            'form' => 'GST F5',
            'jurisdiction' => 'SG',
            'authority' => 'IRAS',
            'period' => $period,
            'currency' => 'SGD',
            'unassigned' => $taxes['unassigned'] ?? ['rows' => 0],
            'boxes' => $boxes,
            'box_definitions' => $definitions,
            'requires_manual_input' => array_values($manualInputsNeeded),
            'total_supplies' => $totalSupplies,
        ];
    }

    /**
     * #745 T12 AC2: Box 13 — revenue for the accounting period from POSTED
     * revenue accounts (net credit), through the SAME lifecycle scope the tax
     * aggregator and every other tax report share (#860 RP6 AC1): originals
     * kept — including `reversed`, because the reversal engine writes signed
     * offsetting rows — and only voided/superseded entries excluded. Out-of-
     * scope supplies are still revenue.
     */
    private function revenueForPeriod(string $period, ?int $legalEntityId): float
    {
        $months = TaxReportingService::expandFilingPeriod($period, 'SG');

        if ($months === []) {
            return 0.0;
        }

        $start = min($months).'-01';
        $end = date('Y-m-t', strtotime(max($months).'-01'));

        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->whereIn('coa.type', ['revenue', 'income'])
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$start, $end])
            ->when($legalEntityId !== null, fn ($query) => $query->where('je.legal_entity_id', $legalEntityId));

        TaxReturnAggregator::applyLiveLifecycle($query);

        return round((float) $query
            ->selectRaw('COALESCE(SUM(jel.credit - jel.debit), 0) as revenue')
            ->value('revenue'), 2);
    }
}
