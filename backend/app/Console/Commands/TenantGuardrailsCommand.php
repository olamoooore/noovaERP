<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\SuperAdmin\Application\EntitlementService;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TenantGuardrailsCommand extends Command
{
    protected $signature = 'ops:tenant-guardrails
                            {--fix : Apply fixes for detected issues}
                            {--tenant= : Run guardrails for one tenant id only}
                            {--mark-test= : Mark a tenant as a test tenant (per-tenant guardrails are skipped)}
                            {--unmark-test= : Remove the test-tenant marker from a tenant}';

    protected $description = 'Audit/fix tenant defaults, module entitlement overrides, and gateway baseline consistency';

    /** Tables every provisioned tenant schema must have. */
    private const CORE_TABLES = [
        'users',
        'products',
        'warehouses',
        'customers',
        'invoices',
        'journal_entries',
        'sales_orders',
        'company_settings',
    ];

    public function __construct(
        private readonly EntitlementService $entitlementService,
        private readonly FeatureGateService $featureGateService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $applyFixes = (bool) $this->option('fix');

        DB::statement('SET search_path TO public');

        if ($markTest = $this->option('mark-test')) {
            return $this->setTestMarker((string) $markTest, true);
        }

        if ($unmarkTest = $this->option('unmark-test')) {
            return $this->setTestMarker((string) $unmarkTest, false);
        }

        $this->line('Running tenant guardrails...');
        $this->line($applyFixes ? 'Mode: FIX' : 'Mode: AUDIT');

        $tenants = DB::table('public.tenants')->get(['id', 'name', 'data']);
        $testTenantIds = $tenants
            ->filter(fn ($tenant) => $this->isTestTenant($tenant->data))
            ->pluck('id')
            ->values()
            ->all();

        // Inspect each non-test tenant schema once: used both for the core-data
        // report and to decide which tenants are "empty" (no business data) and
        // therefore exempt from the baseline checks.
        $inspection = [];
        foreach ($tenants as $tenant) {
            if (in_array($tenant->id, $testTenantIds, true)) {
                continue;
            }
            $inspection[$tenant->id] = $this->inspectTenant((string) $tenant->id);
        }

        $skipTenantIds = $testTenantIds;
        foreach ($inspection as $tenantId => $data) {
            if (! $data['missing'] && ! $data['has_business_data']) {
                $skipTenantIds[] = (string) $tenantId;
            }
        }

        $issues = 0;
        $fixed = 0;

        [$issuesDefault, $fixedDefault] = $this->auditDefaultTenantAccess($applyFixes);
        $issues += $issuesDefault;
        $fixed += $fixedDefault;

        [$issuesMultiTenantUsers, $fixedMultiTenantUsers] = $this->auditMultiTenantUsers($applyFixes);
        $issues += $issuesMultiTenantUsers;
        $fixed += $fixedMultiTenantUsers;

        [$issuesEntitlements, $fixedEntitlements] = $this->syncTenantEntitlements($applyFixes, $skipTenantIds);
        $issues += $issuesEntitlements;
        $fixed += $fixedEntitlements;

        [$issuesGateways, $fixedGateways] = $this->syncPaymentGatewayBaseline($applyFixes);
        $issues += $issuesGateways;
        $fixed += $fixedGateways;

        [$issuesCoreData, $fixedCoreData] = $this->auditCoreData($tenants, $testTenantIds, $inspection);
        $issues += $issuesCoreData;
        $fixed += $fixedCoreData;

        DB::statement('SET search_path TO public');

        $this->newLine();
        $this->info('Guardrail Summary');
        $this->line("Issues detected: {$issues}");
        $this->line("Fixes applied: {$fixed}");

        if ($issues > 0 && ! $applyFixes) {
            $this->warn('Guardrails found issues. Re-run with --fix to auto-remediate.');
            $this->line('Test/sandbox tenants can be exempted: php artisan ops:tenant-guardrails --mark-test=<tenant-id>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Core data integrity report. Test tenants are skipped, empty schemas are
     * informational, and a missing core table set is always an issue.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $tenants
     * @param  array<int, string>  $testTenantIds
     * @param  array<string, array>  $inspection
     */
    private function auditCoreData($tenants, array $testTenantIds, array $inspection): array
    {
        $issues = 0;
        $fixed = 0;

        $this->newLine();
        $this->info('Tenant Core Data Integrity Check');

        foreach ($tenants as $tenant) {
            $tenantId = (string) $tenant->id;
            $label = $tenant->name ? "Tenant {$tenantId} ({$tenant->name})" : "Tenant {$tenantId}";

            if (in_array($tenantId, $testTenantIds, true)) {
                $this->line("{$label}: skipped (test tenant)");

                continue;
            }

            $data = $inspection[$tenantId] ?? $this->inspectTenant($tenantId);

            if ($data['missing']) {
                $issues++;
                $this->warn("{$label}: missing core tables: ".implode(', ', $data['missing']));

                continue;
            }

            $counts = $data['counts'];
            $this->line(sprintf(
                '%s: Products=%d, Warehouses=%d, Company=%s <%s>',
                $label,
                $counts['products'],
                $counts['warehouses'],
                $data['company_name'],
                $data['company_email'],
            ));

            if (! $data['has_business_data']) {
                $this->line('  skipped (empty tenant — no business data)');

                continue;
            }

            if ($counts['products'] === 0) {
                $this->warn('  [!] No products found');
                $issues++;
            }
            if ($counts['warehouses'] === 0) {
                $this->warn('  [!] No warehouses found');
                $issues++;
            }
            if ($data['company_name'] === '[none]') {
                $this->warn('  [!] Company name missing');
                $issues++;
            }
        }

        return [$issues, $fixed];
    }

    /**
     * Inspect a tenant schema: core table presence, baseline counts, and whether
     * any business data exists at all.
     */
    private function inspectTenant(string $tenantId): array
    {
        $missing = [];
        foreach (self::CORE_TABLES as $table) {
            $exists = DB::table('information_schema.tables')
                ->where('table_schema', $tenantId)
                ->where('table_name', $table)
                ->exists();

            if (! $exists) {
                $missing[] = $table;
            }
        }

        $counts = [
            'products' => 0,
            'warehouses' => 0,
            'customers' => 0,
            'invoices' => 0,
            'journal_entries' => 0,
            'sales_orders' => 0,
            'company_settings' => 0,
        ];
        $companyName = '[missing schema]';
        $companyEmail = '[missing schema]';

        if ($missing === []) {
            DB::statement("SET search_path TO '{$tenantId}', public");

            foreach (array_keys($counts) as $table) {
                $counts[$table] = DB::table($table)->count();
            }

            $company = DB::table('company_settings')->first();
            $companyName = $company->company_name ?? '[none]';
            $companyEmail = $company->email ?? '[none]';

            DB::statement('SET search_path TO public');
        }

        $hasBusinessData = (
            $counts['products'] + $counts['warehouses'] + $counts['customers']
            + $counts['invoices'] + $counts['journal_entries'] + $counts['sales_orders']
            + $counts['company_settings']
        ) > 0;

        return [
            'missing' => $missing,
            'counts' => $counts,
            'company_name' => $companyName,
            'company_email' => $companyEmail,
            'has_business_data' => $hasBusinessData,
        ];
    }

    /**
     * Persist or remove the `data.is_test` marker on a tenant.
     */
    private function setTestMarker(string $tenantId, bool $isTest): int
    {
        $tenant = DB::table('public.tenants')->where('id', $tenantId)->first(['id', 'name', 'data']);

        if (! $tenant) {
            $this->error("tenant not found: {$tenantId}");

            return self::FAILURE;
        }

        // Issue #213: atomic single-statement JSON merge so a concurrent
        // suspend()/reinstate() writing a different key cannot be clobbered.
        if ($isTest) {
            \App\Tenancy\TenantSuspensionGuard::mergeTenantData($tenantId, ['is_test' => true]);
        } else {
            \App\Tenancy\TenantSuspensionGuard::mergeTenantData($tenantId, [], ['is_test']);
        }

        $this->info(sprintf(
            'tenant %s (%s) %s as test tenant',
            $tenantId,
            $tenant->name,
            $isTest ? 'marked' : 'unmarked',
        ));

        return self::SUCCESS;
    }

    private function isTestTenant(?string $data): bool
    {
        $decoded = json_decode((string) $data, true);

        return is_array($decoded) && ($decoded['is_test'] ?? false) === true;
    }

    /**
     * Ensure each user has exactly one default tenant in user_company_access.
     */
    private function auditDefaultTenantAccess(bool $applyFixes): array
    {
        $issues = 0;
        $fixed = 0;

        $rows = DB::table('public.user_company_access')
            ->select(
                'user_id',
                DB::raw('count(*) as total_rows'),
                DB::raw('sum(case when is_default = true then 1 else 0 end) as default_rows')
            )
            ->groupBy('user_id')
            ->get();

        foreach ($rows as $row) {
            $defaultRows = (int) $row->default_rows;
            if ($defaultRows === 1) {
                continue;
            }

            $issues++;
            $this->warn("user_id={$row->user_id} has default_rows={$defaultRows}");

            if (! $applyFixes) {
                continue;
            }

            $userAccess = DB::table('public.user_company_access')
                ->where('user_id', $row->user_id)
                ->orderByDesc('is_default')
                ->orderByDesc('updated_at')
                ->orderBy('id')
                ->get();

            $keep = $userAccess->first();
            if (! $keep) {
                continue;
            }

            DB::table('public.user_company_access')
                ->where('user_id', $row->user_id)
                ->update([
                    'is_default' => false,
                    'updated_at' => now(),
                ]);

            DB::table('public.user_company_access')
                ->where('id', $keep->id)
                ->update([
                    'is_default' => true,
                    'updated_at' => now(),
                ]);

            $fixed++;
            $this->info("  fixed user_id={$row->user_id}, kept tenant_id={$keep->tenant_id} as default");
        }

        return [$issues, $fixed];
    }

    /**
     * Flag central users linked to multiple tenants because signup reuse can silently
     * switch their default tenant to an empty schema.
     */
    private function auditMultiTenantUsers(bool $applyFixes): array
    {
        $issues = 0;
        $fixed = 0;

        $rows = DB::table('public.user_company_access as uca')
            ->join('public.users as u', 'u.id', '=', 'uca.user_id')
            ->select(
                'uca.user_id',
                'u.email',
                DB::raw('count(*) as tenant_rows'),
                DB::raw('sum(case when uca.is_default = true then 1 else 0 end) as default_rows')
            )
            ->groupBy('uca.user_id', 'u.email')
            ->havingRaw('count(*) > 1')
            ->orderBy('u.email')
            ->get();

        foreach ($rows as $row) {
            $issues++;

            $tenantRows = DB::table('public.user_company_access as uca')
                ->join('public.tenants as t', 't.id', '=', 'uca.tenant_id')
                ->where('uca.user_id', $row->user_id)
                ->orderByDesc('uca.is_default')
                ->orderBy('t.name')
                ->get([
                    'uca.tenant_id',
                    'uca.is_default',
                    'uca.role',
                    't.name as tenant_name',
                ]);

            $tenantSummary = $tenantRows
                ->map(fn ($tenantRow) => sprintf(
                    '%s%s (%s)',
                    $tenantRow->tenant_name,
                    $tenantRow->is_default ? ' [default]' : '',
                    $tenantRow->tenant_id
                ))
                ->implode(', ');

            $this->warn("user_id={$row->user_id} email={$row->email} is linked to {$row->tenant_rows} tenants: {$tenantSummary}");

            if (! $applyFixes) {
                continue;
            }

            $this->line('  no automatic fix applied; review and prune unintended tenant links manually.');
        }

        return [$issues, $fixed];
    }

    /**
     * Ensure tenant entitlements are present and configured module overrides are enforced.
     *
     * @param  array<int, string>  $skipTenantIds  Test/empty tenants exempt from the zero-entitlement check.
     */
    private function syncTenantEntitlements(bool $applyFixes, array $skipTenantIds = []): array
    {
        $issues = 0;
        $fixed = 0;

        $tenantIdOption = $this->option('tenant');
        $tenantIds = $tenantIdOption
            ? collect([(string) $tenantIdOption])
            : DB::table('public.tenants')->pluck('id');

        $activeCatalogByKey = DB::table('public.catalog_items')
            ->where('is_active', true)
            ->pluck('id', 'key');

        $overrides = config('tenant_guardrails.tenant_module_overrides', []);

        foreach ($tenantIds as $tenantId) {
            $entitledCount = DB::table('public.tenant_entitlements as te')
                ->join('public.catalog_items as ci', 'ci.id', '=', 'te.catalog_item_id')
                ->where('te.tenant_id', $tenantId)
                ->where('te.is_entitled', true)
                ->where('ci.is_active', true)
                ->count();

            if ($entitledCount === 0) {
                if (in_array((string) $tenantId, $skipTenantIds, true)) {
                    $this->line("tenant_id={$tenantId} has zero active entitlements — skipped (test/empty tenant)");
                } else {
                    $issues++;
                    $this->warn("tenant_id={$tenantId} has zero active entitlements");

                    if ($applyFixes) {
                        $this->entitlementService->rebuild(
                            tenantId: (string) $tenantId,
                            adminId: null,
                            reason: 'guardrail_rebuild_empty_entitlements'
                        );
                        $this->featureGateService->invalidate((string) $tenantId);
                        $fixed++;
                        $this->info("  rebuilt entitlements for tenant_id={$tenantId}");
                    }
                }
            }

            $tenantOverride = $overrides[(string) $tenantId] ?? null;
            if (! $tenantOverride) {
                continue;
            }

            $includeKeys = collect($tenantOverride['include'] ?? [])->map(fn ($k) => (string) $k);
            $excludeKeys = collect($tenantOverride['exclude'] ?? [])->map(fn ($k) => (string) $k);

            foreach ($includeKeys as $key) {
                if (! $activeCatalogByKey->has($key)) {
                    $issues++;
                    $this->warn("tenant_id={$tenantId} include key missing in catalog: {$key}");

                    continue;
                }

                $catalogItemId = (int) $activeCatalogByKey->get($key);

                $existing = DB::table('public.tenant_entitlements')
                    ->where('tenant_id', $tenantId)
                    ->where('catalog_item_id', $catalogItemId)
                    ->first();

                $needsUpdate = ! $existing || ! $existing->is_entitled || $existing->source !== 'override_include';
                if (! $needsUpdate) {
                    continue;
                }

                $issues++;
                $this->warn("tenant_id={$tenantId} include drift on key={$key}");

                if ($applyFixes) {
                    DB::table('public.tenant_entitlements')->updateOrInsert(
                        [
                            'tenant_id' => $tenantId,
                            'catalog_item_id' => $catalogItemId,
                        ],
                        [
                            'is_entitled' => true,
                            'source' => 'override_include',
                            'resolved_at' => now(),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );

                    DB::table('public.tenant_selected_items')->updateOrInsert(
                        [
                            'tenant_id' => $tenantId,
                            'catalog_item_id' => $catalogItemId,
                        ],
                        [
                            'selection_type' => 'override_include',
                            'notes' => 'tenant_guardrails include override',
                            'selected_by_admin_id' => null,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );

                    $fixed++;
                    $this->info("  fixed include key={$key} for tenant_id={$tenantId}");
                }
            }

            foreach ($excludeKeys as $key) {
                if (! $activeCatalogByKey->has($key)) {
                    $issues++;
                    $this->warn("tenant_id={$tenantId} exclude key missing in catalog: {$key}");

                    continue;
                }

                $catalogItemId = (int) $activeCatalogByKey->get($key);

                $existing = DB::table('public.tenant_entitlements')
                    ->where('tenant_id', $tenantId)
                    ->where('catalog_item_id', $catalogItemId)
                    ->first();

                $needsUpdate = ! $existing || $existing->is_entitled || $existing->source !== 'override_exclude';
                if (! $needsUpdate) {
                    continue;
                }

                $issues++;
                $this->warn("tenant_id={$tenantId} exclude drift on key={$key}");

                if ($applyFixes) {
                    DB::table('public.tenant_entitlements')->updateOrInsert(
                        [
                            'tenant_id' => $tenantId,
                            'catalog_item_id' => $catalogItemId,
                        ],
                        [
                            'is_entitled' => false,
                            'source' => 'override_exclude',
                            'resolved_at' => now(),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );

                    DB::table('public.tenant_selected_items')->updateOrInsert(
                        [
                            'tenant_id' => $tenantId,
                            'catalog_item_id' => $catalogItemId,
                        ],
                        [
                            'selection_type' => 'override_exclude',
                            'notes' => 'tenant_guardrails exclude override',
                            'selected_by_admin_id' => null,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );

                    $fixed++;
                    $this->info("  fixed exclude key={$key} for tenant_id={$tenantId}");
                }
            }

            if ($applyFixes) {
                $this->featureGateService->invalidate((string) $tenantId);
            }
        }

        return [$issues, $fixed];
    }

    /**
     * Ensure required payment gateways exist in public.payment_gateways.
     */
    private function syncPaymentGatewayBaseline(bool $applyFixes): array
    {
        $issues = 0;
        $fixed = 0;

        // payment_gateways is a tenant-scoped table; the central schema only
        // has it on deployments that keep a global baseline copy. Skip when it
        // is absent instead of crashing the pre-push audit.
        if (! \Illuminate\Support\Facades\Schema::hasTable('payment_gateways')) {
            return [$issues, $fixed];
        }

        $requiredGateways = config('tenant_guardrails.payment_gateways', []);
        $systemUserId = DB::table('public.users')->min('id') ?? 1;

        foreach ($requiredGateways as $gateway) {
            $name = (string) ($gateway['gateway_name'] ?? '');
            if ($name === '') {
                continue;
            }

            $expectedActive = (bool) ($gateway['is_active'] ?? true);
            $expectedTestMode = (bool) ($gateway['is_test_mode'] ?? false);
            $expectedType = (string) ($gateway['gateway_type'] ?? 'offline');

            $existing = DB::table('public.payment_gateways')
                ->whereNull('deleted_at')
                ->where('gateway_name', $name)
                ->first();

            if (! $existing) {
                $issues++;
                $this->warn("gateway missing: {$name}");

                if ($applyFixes) {
                    DB::table('public.payment_gateways')->insert([
                        'gateway_name' => $name,
                        'publishable_key' => null,
                        'api_key' => null,
                        'api_secret' => null,
                        'webhook_secret' => null,
                        'is_active' => $expectedActive,
                        'is_test_mode' => $expectedTestMode,
                        'config' => null,
                        'created_by' => $systemUserId,
                        'updated_by' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'deleted_at' => null,
                        'gateway_type' => $expectedType,
                        'offline_config' => null,
                    ]);

                    $fixed++;
                    $this->info("  inserted gateway={$name}");
                }

                continue;
            }

            $drift = ((bool) $existing->is_active !== $expectedActive)
                || ((bool) $existing->is_test_mode !== $expectedTestMode)
                || ((string) ($existing->gateway_type ?? '') !== $expectedType);

            if (! $drift) {
                continue;
            }

            $issues++;
            $this->warn("gateway drift: {$name}");

            if ($applyFixes) {
                DB::table('public.payment_gateways')
                    ->where('id', $existing->id)
                    ->update([
                        'is_active' => $expectedActive,
                        'is_test_mode' => $expectedTestMode,
                        'gateway_type' => $expectedType,
                        'updated_at' => now(),
                    ]);

                $fixed++;
                $this->info("  updated gateway={$name}");
            }
        }

        return [$issues, $fixed];
    }
}
