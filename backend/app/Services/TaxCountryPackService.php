<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Tax;
use Illuminate\Http\Request;
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
        $version = (int) ($pack['version'] ?? 1);

        DB::transaction(function () use ($pack, &$created, &$existing, $version) {
            foreach ($pack['rules'] as $rule) {
                if (Tax::withTrashed()->where('code', $rule['code'])->exists()) {
                    $existing[] = $rule['code'];

                    continue;
                }

                // #739 T6: record which pack revision the row came from and the
                // exact field values it was given, so later drift is detectable
                // instead of being masked by "the code already exists".
                Tax::create($rule + ['is_active' => true, 'pack_version' => $version, 'pack_snapshot' => $rule]);
                $created[] = $rule['code'];
            }
        });

        // #740 T4 (AC): activation never changes the tenant's home
        // jurisdiction — registrations define it, not rule data.
        return [
            'jurisdiction' => $jurisdiction,
            'label' => $pack['label'],
            'activated' => true,
            'version' => $version,
            'installed_version' => $this->installedVersion($pack),
            'created' => $created,
            'existing' => $existing,
            'total_pack_rules' => count($pack['rules']),
        ];
    }

    /**
     * #739 T6: the highest pack version this tenant actually has rows for.
     * A pack whose upgrade was skipped stays below the pack's version, which
     * is exactly what status() needs to report.
     */
    private function installedVersion(array $pack): ?int
    {
        $version = Tax::query()
            ->whereNotNull('pack_version')
            ->whereIn('code', array_column($pack['rules'], 'code'))
            ->max('pack_version');

        return $version === null ? null : (int) $version;
    }

    /**
     * #739 T6 AC2: field-level diff between what the pack supplied and what
     * the tenant row now holds. Null-safe and decimal-aware (a rate of '9'
     * from JSON and 9.00 from the decimal column must not read as a change).
     *
     * @return array<string, array{from:mixed, to:mixed}>
     */
    private function diff(array $from, array $to, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            $before = $from[$field] ?? null;
            $after = $to[$field] ?? null;

            if ($this->comparable($field, $before) !== $this->comparable($field, $after)) {
                $changes[$field] = [
                    'from' => $field === 'rate' ? (float) $before : $this->present($before, $field),
                    'to' => $field === 'rate' ? (float) $after : $this->present($after, $field),
                ];
            }
        }

        return $changes;
    }

    /**
     * Comparable form of a field value. A pack snapshot holds PHP types while
     * the row is read back as raw column values, so `false` and `'0'` are the
     * same setting and must not read as a tenant edit.
     */
    private function comparable(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($field === 'rate') {
            return (float) $value;
        }

        if ($field === 'is_inclusive') {
            // Postgres hands booleans back as 't'/'f' in raw attributes, PHP as
            // true/false, the JSON snapshot as true/false. All one setting.
            return in_array(mb_strtolower((string) $value), ['1', 't', 'true'], true) ? '1' : '0';
        }

        if (in_array($field, ['effective_from', 'effective_to'], true)) {
            // Date columns round-trip with a time component; the pack writes a
            // plain date. Same day, same setting.
            return ($value === null || $value === '')
                ? null
                : \Carbon\Carbon::parse($value)->toDateString();
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return mb_strtolower(trim((string) $value));
    }

    private function present(mixed $value, string $field): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($field === 'is_inclusive') {
            return in_array(mb_strtolower((string) $value), ['1', 't', 'true'], true) ? 1 : 0;
        }

        if (in_array($field, ['effective_from', 'effective_to'], true)) {
            return \Carbon\Carbon::parse($value)->toDateString();
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return (string) $value;
    }

    /**
     * The fields a pack rule owns (and the snapshot/diff surface).
     *
     * @return array<int, string>
     */
    private function packFields(): array
    {
        return ['name', 'rate', 'jurisdiction', 'regime', 'scope', 'treatment', 'type', 'is_inclusive', 'effective_from', 'effective_to'];
    }

    /**
     * #739 T6 AC2: per-rule drift. Every pack rule is classified as
     * new / changed (field-level diff) / tenant-modified / removed-from-pack,
     * so `activated: true` can no longer hide a stale rate.
     *
     * @return array<string, array<string, mixed>>
     */
    public function status(): array
    {
        $out = [];
        // Taxes without a code (legacy rows) cannot match a pack rule, and a
        // NULL entry makes array_flip() raise (turned into a 500 by Laravel's
        // error handler). Only string/int codes participate.
        $rows = Tax::query()
            ->whereNotNull('code')
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'rate', 'jurisdiction', 'regime', 'scope', 'treatment', 'type', 'is_inclusive', 'effective_from', 'effective_to', 'pack_version', 'pack_snapshot']);

        $codes = array_flip($rows->pluck('code')->filter(fn ($code) => is_string($code) || is_int($code))->all());
        $byCode = $rows->groupBy('code');
        $fields = $this->packFields();

        // Pack-origin rows the pack no longer ships (reported per jurisdiction
        // via the removed_from_pack classification below).
        $packOriginCodes = Tax::query()
            ->whereNotNull('code')
            ->whereNotNull('pack_version')
            ->pluck('code')
            ->filter(fn ($code) => is_string($code) || is_int($code))
            ->unique()
            ->values()
            ->all();

        foreach (config('tax_packs', []) as $jurisdiction => $pack) {
            $packVersion = (int) ($pack['version'] ?? 1);
            $present = [];
            $missing = [];
            $rules = [];
            $newCodes = [];
            $changedCodes = [];
            $modifiedCodes = [];
            $removedCodes = [];

            foreach ($pack['rules'] as $rule) {
                if (array_key_exists($rule['code'], $codes)) {
                    $present[] = $rule['code'];
                } else {
                    $missing[] = $rule['code'];
                }

                $row = $byCode->get($rule['code'])?->first();
                $entry = ['code' => $rule['code'], 'state' => 'new', 'changes' => [], 'tenant_changes' => []];

                if ($row !== null) {
                    $snapshot = is_array($row->pack_snapshot) ? $row->pack_snapshot : null;
                    $current = $row->only(array_merge($fields, ['pack_version']));

                    if ($snapshot === null) {
                        // Row predates pack provenance (#739): compare against
                        // the pack directly and flag it as untracked so the
                        // tenant is never told a row is pristine when we
                        // cannot prove it.
                        $entry['state'] = 'changed';
                        $entry['changes'] = $this->diff($current, $rule, $fields);
                        $entry['untracked'] = true;
                        $changedCodes[] = $rule['code'];
                    } else {
                        $tenantChanges = $this->diff($snapshot, $current, $fields);
                        $packChanges = $this->diff($snapshot, $rule, $fields);

                        if ($tenantChanges !== []) {
                            $entry['state'] = 'tenant_modified';
                            $entry['tenant_changes'] = $tenantChanges;
                            $entry['changes'] = $packChanges;
                            $modifiedCodes[] = $rule['code'];

                            if ($packChanges !== []) {
                                $changedCodes[] = $rule['code'];
                            }
                        } elseif ($packChanges !== []) {
                            $entry['state'] = 'changed';
                            $entry['changes'] = $packChanges;
                            $changedCodes[] = $rule['code'];
                        } else {
                            $entry['state'] = 'ok';
                        }
                    }
                } else {
                    $newCodes[] = $rule['code'];
                }

                $rules[] = $entry;
            }

            $shippedCodes = array_column($pack['rules'], 'code');
            $removedCodes = array_values(array_diff($packOriginCodes, $shippedCodes));

            $installedVersion = $this->installedVersion($pack);

            $out[(string) $jurisdiction] = [
                'label' => $pack['label'],
                'currency' => $pack['currency'] ?? null,
                'activated' => $missing === [],
                'partial' => $present !== [] && $missing !== [],
                'present' => $present,
                'missing' => $missing,
                'total_pack_rules' => count($pack['rules']),
                // #739 T6
                'version' => $packVersion,
                'installed_version' => $installedVersion,
                'up_to_date' => $installedVersion === $packVersion
                    && $newCodes === [] && $changedCodes === [] && $removedCodes === [],
                'rules' => $rules,
                'new' => $newCodes,
                'changed' => array_values(array_unique($changedCodes)),
                'tenant_modified' => $modifiedCodes,
                'removed_from_pack' => $removedCodes,
            ];
        }

        return $out;
    }

    /**
     * #739 T6 AC3: apply pack changes the user picked. Each selected rule is
     * applied through the platform's own mutation path (G-03/G-13), so a rule
     * a posted document already used is end-dated and versioned rather than
     * rewritten, and a tenant-edited rule is skipped rather than overwritten.
     *
     * @param  array<int, string>  $codes
     * @return array<string, mixed>
     */
    public function upgrade(string $jurisdiction, array $codes, ?Request $request = null): array
    {
        $jurisdiction = strtoupper($jurisdiction);
        $pack = config("tax_packs.$jurisdiction");

        if (! is_array($pack)) {
            throw new \InvalidArgumentException("No tax country pack is defined for {$jurisdiction}.");
        }

        $packRules = collect($pack['rules'])->keyBy('code');
        $packVersion = (int) ($pack['version'] ?? 1);
        $fields = $this->packFields();
        $mutation = app(TaxRuleMutationService::class);

        $applied = [];
        $skipped = [];

        DB::transaction(function () use ($codes, $packRules, $packVersion, $fields, $mutation, $request, &$applied, &$skipped) {
            foreach ($codes as $code) {
                $rule = $packRules->get($code);

                if ($rule === null) {
                    throw new \InvalidArgumentException("Rule {$code} is not part of the {$packVersion} pack revision.");
                }

                $row = Tax::withTrashed()->where('code', $code)->where('is_active', true)->orderByDesc('id')->first();

                if ($row === null) {
                    // Missing rule the pack ships: create it with provenance.
                    $fresh = Tax::create($rule + ['is_active' => true, 'pack_version' => $packVersion, 'pack_snapshot' => $rule]);
                    $mutation->audit('tax_pack_upgrade_created', $fresh, null, $fresh->attributesToArray(), $request);
                    $applied[] = ['code' => $code, 'mode' => 'created', 'rule_id' => $fresh->id];

                    continue;
                }

                $snapshot = is_array($row->pack_snapshot) ? $row->pack_snapshot : null;

                if ($snapshot !== null && $this->diff($snapshot, $row->only($fields), $fields) !== []) {
                    $skipped[] = [
                        'code' => $code,
                        'reason' => 'tenant_modified',
                        'message' => 'This rule was edited by the tenant; a pack upgrade never overwrites it silently.',
                        'tenant_changes' => $this->diff($snapshot, $row->only($fields), $fields),
                    ];

                    continue;
                }

                $baseline = $snapshot ?? $row->only($fields);
                $changes = $this->diff($baseline, $rule, $fields);

                if ($changes === []) {
                    $skipped[] = ['code' => $code, 'reason' => 'unchanged'];

                    continue;
                }

                // Apply the pack's values (the diff is only for reporting).
                $target = array_intersect_key($rule, array_flip($fields));
                $before = $row->attributesToArray();
                $effective = $mutation->apply($row, $target, $request);
                $mode = $effective->id === $row->id
                    ? (array_keys($changes) === ['effective_to'] ? 'end_dated' : 'updated')
                    : 'versioned';

                // Provenance follows the row that now carries the pack values.
                $effective->update([
                    'pack_version' => $packVersion,
                    'pack_snapshot' => $rule,
                ]);
                $effective->refresh();

                AuditLog::create([
                    'operation' => 'tax_pack_upgrade',
                    'reference_type' => 'tax_rule',
                    'reference_id' => $effective->id,
                    'actor_id' => $request?->user()?->id,
                    'source_route' => $request?->path(),
                    'before_state' => $before,
                    'after_state' => $effective->attributesToArray(),
                    'metadata' => [
                        'jurisdiction' => $packRules->get($code)['jurisdiction'] ?? null,
                        'code' => $code,
                        'pack_version' => $packVersion,
                        'mode' => $mode,
                        'changes' => $changes,
                    ],
                ]);

                $applied[] = [
                    'code' => $code,
                    'mode' => $mode,
                    'rule_id' => $effective->id,
                    'versioned_from' => $effective->id === $row->id ? null : $row->id,
                    'changes' => $changes,
                ];
            }
        });

        return [
            'jurisdiction' => $jurisdiction,
            'version' => $packVersion,
            'installed_version' => $this->installedVersion($pack),
            'applied' => $applied,
            'skipped' => $skipped,
        ];
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
