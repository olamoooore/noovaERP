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
 * live taxation for nobody. Rate history (SG 7/8/9, MY service 6→8, MY 2026
 * regime 8→10) is rows with effective ranges — the DoD property "a 2025-06
 * document and a 2026-07 document derive different correct rates from the same
 * code path" holds because resolution is date-parameterized, not per-release.
 *
 * Pack CONTENT is a compliance deliverable pending verification against IRAS /
 * LHDN-RMCD notices (see config/tax_packs.php notes); this service makes that
 * verification a data-edit, not a deploy.
 */
class TaxCountryPackService
{
    /**
     * Registration threshold monitoring (MRD R5.3 / R6.2): rolling 12-month
     * taxable supply value from the posted tax trail vs the authority's
     * registration thresholds. Advisory signal only — registration status is
     * a human/legal determination.
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
     * @return array<string, mixed>
     */
    public function thresholds(?string $jurisdiction = null, ?int $legalEntityId = null): array
    {
        $months = [];
        $cursor = now()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->subMonthNoOverflow();
        }

        $out = [];

        foreach (self::THRESHOLDS as $code => $definitions) {
            if ($jurisdiction !== null && strtoupper($jurisdiction) !== $code) {
                continue;
            }

            $rows = DB::table('tax_transaction_details as ttd')
                ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
                ->where('ttd.jurisdiction', $code)
                ->whereIn('ttd.reporting_period', $months)
                ->where('je.status', 'posted')
                ->where(function ($q) {
                    $q->whereNull('je.lifecycle_status')
                        ->orWhereNotIn('je.lifecycle_status', ['reversed', 'voided', 'superseded']);
                })
                ->when($legalEntityId !== null, fn ($q) => $q->where('je.legal_entity_id', $legalEntityId))
                ->selectRaw('coalesce(sum(ttd.taxable_amount), 0) as v')
                ->value('v');

            $trailing = round((float) ($v ?? 0), 2);

            $out[$code] = [
                'trailing_12m_taxable_value' => $trailing,
                'months_window' => [min($months), max($months)],
                'thresholds' => array_map(fn (array $t) => $t + [
                    'trailing_value' => $trailing,
                    'percent_of_threshold' => $t['amount'] > 0 ? round($trailing / $t['amount'] * 100, 1) : null,
                    'monitoring_flag' => $trailing >= $t['amount'],
                ], $definitions),
            ];
        }

        return $out;
    }
}
