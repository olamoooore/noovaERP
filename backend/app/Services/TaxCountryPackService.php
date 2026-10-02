<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Tax;
use Illuminate\Support\Facades\DB;

/**
 * MRD TAX-REQ-05/06 + R2.4: country packs as tenant-activated, effective-dated
 * RULE DATA, and registration-threshold monitoring.
 *
 * Activation is idempotent by rule code and never overwrites a rule the tenant
 * has touched (existing code wins), so activating a pack can silently change
 * live taxation for nobody. Rate history (SG 7/8/9, MY service 6→8) is rows
 * with effective ranges — the DoD property "documents dated either side of a
 * rate change derive different correct rates from the same code path" holds
 * because resolution is date-parameterized, not per-release (the fiction
 * 2026 GST-model rows were removed per #734 T1).
 *
 * Pack CONTENT is a compliance deliverable pending verification against IRAS /
 * LHDN-RMCD notices (see config/tax_packs.php notes); this service makes that
 * verification a data-edit, not a deploy.
 */
class TaxCountryPackService
{
    /**
     * Registration threshold monitoring (MRD R5.3 / R6.2 + #737 T4): the
     * authority's registration thresholds compared against sales-revenue
     * turnover (posted invoices less posted credit notes, excluding exempt
     * and out-of-scope supplies) over the last 12 COMPLETE months — forward
     * (forecast input) checked separately. Advisory signal only; registration
     * status is a human/legal determination. Threshold DEFINITIONS move to
     * the pack with T10 (#743).
     */
    private const THRESHOLDS = [
        'SG' => [
            ['key' => 'expected_taxable_turnover', 'label' => 'IRAS GST mandatory registration', 'amount' => 1000000.00, 'currency' => 'SGD', 'note' => 'Real-time trailing-twelve-month taxable supplies'],
        ],
        'MY' => [
            ['key' => 'taxable_services', 'label' => 'RMCD service tax registration', 'amount' => 500000.00, 'currency' => 'MYR', 'note' => 'Threshold raised to RM1.975m for catering & accommodation (2024 revision) — verify per sector'],
            ['key' => 'taxable_goods', 'label' => 'RMCD sales tax registration (manufacturers)', 'amount' => 500000.00, 'currency' => 'MYR', 'note' => 'Value of taxable goods manufactured in 12 months'],
        ],
    ];

    /**
     * #738 T5: map a product's line category to the pack's tax rule type.
     * The pack declares the classification; jurisdictions without a map use
     * no type filter (SG has one GST type, not a MY-style service/sales split).
     */
    public function lineTaxType(string $jurisdiction, ?string $productType): ?string
    {
        if ($productType === null) {
            return null;
        }

        $map = config('tax_packs.'.strtoupper($jurisdiction).'.line_type_map', []);

        return is_array($map) ? ($map[$productType] ?? null) : null;
    }

    /**
     * @return array{jurisdiction:string, label:string, activated:bool, created:array<int,string>, existing:array<int,string>, total_pack_rules:int}
     */
    public function activate(string $jurisdiction): array
    {
        $jurisdiction = strtoupper($jurisdiction);
        $pack = config("tax_packs.$jurisdiction");

        if (! is_array($pack)) {
            throw new \InvalidArgumentException("No tax country pack is defined for {$jurisdiction}.");
        }

        $created = [];
        $existing = [];

        DB::transaction(function () use ($pack, &$created, &$existing) {
            foreach ($pack['rules'] as $rule) {
                if (Tax::withTrashed()->where('code', $rule['code'])->exists()) {
                    $existing[] = $rule['code'];

                    continue;
                }

                Tax::create($rule + ['is_active' => true]);
                $created[] = $rule['code'];
            }
        });

        $taxSetting = Setting::getValue('tax', []);
        if (empty($taxSetting['jurisdiction'])) {
            $taxSetting['jurisdiction'] = $jurisdiction;
            Setting::setValue('tax', $taxSetting);
        }

        return [
            'jurisdiction' => $jurisdiction,
            'label' => $pack['label'],
            'activated' => true,
            'created' => $created,
            'existing' => $existing,
            'total_pack_rules' => count($pack['rules']),
        ];
    }

    /**
     * @return array<string, array{label:string, currency:string, activated:bool, present:array<int,string>, missing:array<int,string>, total_pack_rules:int}>
     */
    public function status(): array
    {
        $out = [];
        // Taxes without a code (legacy rows) cannot match a pack rule, and a
        // NULL entry makes array_flip() raise (turned into a 500 by Laravel's
        // error handler). Only string/int codes participate.
        $codes = array_flip(array_filter(
            Tax::query()->whereNotNull('code')->pluck('code')->all(),
            fn ($code) => is_string($code) || is_int($code),
        ));

        foreach (config('tax_packs', []) as $jurisdiction => $pack) {
            $present = [];
            $missing = [];

            foreach ($pack['rules'] as $rule) {
                if (array_key_exists($rule['code'], $codes)) {
                    $present[] = $rule['code'];
                } else {
                    $missing[] = $rule['code'];
                }
            }

            $out[(string) $jurisdiction] = [
                'label' => $pack['label'],
                'currency' => $pack['currency'] ?? null,
                'activated' => $missing === [],
                'partial' => $present !== [] && $missing !== [],
                'present' => $present,
                'missing' => $missing,
                'total_pack_rules' => count($pack['rules']),
            ];
        }

        return $out;
    }

    /**
     * #737 T4: taxable value is SALES REVENUE per legal entity — posted
     * invoices less posted credit notes, excluding exempt and out-of-scope
     * supplies, whether or not tax was charged — over the last 12 COMPLETE
     * months (the current partial month never counts). Backward
     * (`monitoring_flag`) and forward (`forward_flag`, forecast input) are
     * separate checks; the trailing value never adopts the forecast.
     *
     * @return array<string, mixed>
     */
    public function thresholds(?string $jurisdiction = null, ?int $legalEntityId = null, ?float $forecastNext12m = null): array
    {
        $months = [];
        $cursor = now()->startOfMonth()->subMonthNoOverflow();

        for ($i = 0; $i < 12; $i++) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->subMonthNoOverflow();
        }

        $window = [min($months), max($months)];
        $start = $window[0].'-01';
        $end = date('Y-m-t', strtotime($window[1].'-01'));

        $out = [];

        foreach (self::THRESHOLDS as $code => $definitions) {
            if ($jurisdiction !== null && strtoupper($jurisdiction) !== $code) {
                continue;
            }

            $trailing = $this->taxableTurnover($code, $start, $end, $legalEntityId);

            $out[$code] = [
                'trailing_12m_taxable_value' => $trailing,
                'months_window' => $window,
                'forecast_next_12m' => $forecastNext12m,
                'thresholds' => array_map(fn (array $t) => $t + [
                    'trailing_value' => $trailing,
                    'percent_of_threshold' => $t['amount'] > 0 ? round($trailing / $t['amount'] * 100, 1) : null,
                    'monitoring_flag' => $trailing >= $t['amount'],
                    'forward_flag' => $forecastNext12m === null ? null : $forecastNext12m >= $t['amount'],
                ], $definitions),
            ];
        }

        return $out;
    }

    /**
     * Posted sales revenue for a jurisdiction in [start, end]: invoices less
     * posted credit notes. Untaxed invoices count (jurisdiction falls back to
     * the tenant home jurisdiction); exempt/out-of-scope supplies and
     * reversed/voided/superseded entries do not.
     */
    private function taxableTurnover(string $code, string $start, string $end, ?int $legalEntityId): float
    {
        $home = app(TaxEngineService::class)->tenantJurisdiction();

        $scopeJurisdiction = function ($q) use ($code, $home) {
            $q->where('inv.tax_jurisdiction', $code);

            if (strtoupper($home) === $code) {
                $q->orWhereNull('inv.tax_jurisdiction');
            }
        };

        $scopeTreatment = function ($q) {
            $q->whereNull('inv.tax_treatment')
                ->orWhereNotIn('inv.tax_treatment', ['exempt', 'out_of_scope']);
        };

        $scopeEntry = function ($q) {
            $q->where('je.status', 'posted')
                ->where(function ($q) {
                    $q->whereNull('je.lifecycle_status')
                        ->orWhereNotIn('je.lifecycle_status', ['reversed', 'voided', 'superseded']);
                });
        };

        $sales = DB::table('invoices as inv')
            ->join('journal_entries as je', 'je.id', '=', 'inv.journal_entry_id')
            ->whereBetween('inv.invoice_date', [$start, $end])
            ->where($scopeJurisdiction)
            ->where($scopeTreatment)
            ->when($legalEntityId !== null, fn ($q) => $q->where('je.legal_entity_id', $legalEntityId))
            ->where($scopeEntry)
            ->sum('inv.subtotal');

        $credits = DB::table('customer_credit_notes as cn')
            ->join('invoices as inv', 'inv.id', '=', 'cn.invoice_id')
            ->join('journal_entries as je', 'je.id', '=', 'cn.journal_entry_id')
            ->whereNotNull('cn.posted_at')
            ->whereBetween('cn.credit_note_date', [$start, $end])
            ->where($scopeJurisdiction)
            ->where($scopeTreatment)
            ->when($legalEntityId !== null, fn ($q) => $q->where('je.legal_entity_id', $legalEntityId))
            ->where($scopeEntry)
            ->sum('cn.subtotal');

        return round((float) $sales - (float) $credits, 2);
    }
}
