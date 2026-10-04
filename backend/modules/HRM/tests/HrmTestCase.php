<?php

namespace Modules\HRM\Tests;

use App\Http\Middleware\SetTenantFromUserPreference;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * HRM-specific base test case.
 *
 * Extends the shared TenantTestCase so HRM tests run against the same
 * full-schema provisioning baseline as every other suite (central +
 * all module tenant migrations). This replaces the previous bespoke
 * "db:wipe + two hand-picked migration paths" approach, which drifted
 * out of sync with the real tenant schema (e.g. missing the
 * platform_system_logs table that the AuditTrailObserver writes to
 * whenever Employee/PayrollRun models change).
 *
 * A tenant context is bound for every test because platform observers
 * (audit trail) stamp tenant_id on log records, and the entitlement
 * gate is mocked to grant `hrm` so route middleware is exercised
 * without requiring seeded entitlement rows.
 */
abstract class HrmTestCase extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Bypass middleware that queries central preference tables which are
        // irrelevant here. Sanctum auth middleware continues to run normally.
        $this->withoutMiddleware(SetTenantFromUserPreference::class);

        $this->bindTenantContext((string) Str::uuid());

        $this->mockFeatureGate(true);

        // #787 (Y1): every HRM route now enforces RequirePermission. These
        // module suites exercise payroll/HRM behaviour, not RBAC — dedicated
        // router-level coverage lives in
        // tests/Feature/HrmPayrollPermissionEnforcementTest — so every acting
        // user here is treated as fully permitted (same pattern as the POS
        // suites).
        $roleMock = \Mockery::mock(\App\Services\TenantRoleService::class);
        $roleMock->shouldReceive('hasPermission')->andReturn(true);
        $roleMock->shouldReceive('hasAnyPermission')->andReturn(true);
        app()->instance(\App\Services\TenantRoleService::class, $roleMock);

        // #793: approval/mark-paid now post accrual/payment entries, whose
        // authority gate (PermissionGate → AccountingAccessService) would
        // otherwise block these non-RBAC suites. Accounting-gate behaviour
        // has dedicated coverage (Adr005PostingEngineWiringTest,
        // HR37PostingAuthorityFailClosedTest, Issue37PostingFailClosedTest).
        $accessMock = \Mockery::mock(\App\Services\AccountingAccessService::class);
        $accessMock->shouldReceive('userHasPermission')->andReturn(true);
        app()->instance(\App\Services\AccountingAccessService::class, $accessMock);
    }

    protected function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(function (string $tenantId, string $key) use ($isEntitled) {
                return [
                    'key' => $key,
                    'is_entitled' => $isEntitled,
                    'source' => $isEntitled ? 'tier_included' : null,
                    'name' => strtoupper($key),
                ];
            });

        $mock->shouldReceive('all')
            ->andReturn($isEntitled ? [['key' => 'hrm', 'source' => 'tier_included', 'name' => 'HRM']] : []);

        $mock->shouldReceive('invalidate')->andReturnNull();

        app()->instance(FeatureGateService::class, $mock);
    }
}
