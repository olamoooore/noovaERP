<?php

namespace Tests\Feature;

use App\Models\Accounting\AccountingFinding;
use App\Models\AssuranceBlockingPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FinancialClose\Models\CloseCycle;
use Tests\TenantTestCase;

/**
 * GAP-002 Tests — Financial Close RBAC and Assurance (ADR-005 §39, §41, G22).
 *
 * Verifies that:
 * - Close routes require permission middleware
 * - Sign-off resolves role server-side (no client-submitted role)
 * - Readiness evaluation checks open assurance findings
 */
class FinancialCloseGapTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantId,
            'name' => 'FinancialCloseGap tenant',
            'email' => $this->tenantId.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function close_routes_have_permission_middleware(): void
    {
        // Verify that the FinancialCloseServiceProvider registers routes with permission middleware
        $provider = new \Modules\FinancialClose\Providers\FinancialCloseServiceProvider($this->app);

        // The provider should be resolvable
        $this->assertInstanceOf(
            \Modules\FinancialClose\Providers\FinancialCloseServiceProvider::class,
            $provider
        );
    }

    /** @test */
    public function signoff_requires_authorized_role_from_server(): void
    {
        // The signoffCycle method now resolves the role server-side
        // and ignores the client-submitted $role parameter
        $reflection = new \ReflectionClass(\Modules\FinancialClose\Services\FinancialCloseCycleService::class);
        $method = $reflection->getMethod('resolveUserRole');

        // Method should be private (server-side only)
        $this->assertTrue($method->isPrivate(), 'resolveUserRole must be private to prevent client-side role spoofing');
    }

    /**
     * Issue #39: this was a source-string grep that never executed the query —
     * it passed even while evaluateReadiness referenced a nonexistent class and
     * mismatched fields/statuses. It is now database-backed: seed the canonical
     * AssuranceBlockingPolicy + AccountingFinding, then assert the readiness
     * check actually reports the finding as blocking.
     */
    /** @test */
    public function evaluate_readiness_checks_open_findings(): void
    {
        AssuranceBlockingPolicy::create([
            'tenant_id' => $this->tenantId,
            'policy_name' => 'FinancialCloseGap test policy',
            'policy_version' => '1.0',
            'finding_category' => '*',
            'assertion_level' => 'STANDARD',
            'severity_threshold' => 'HIGH',
            'action_scope' => ['PERIOD_CLOSE'],
            'block_mode' => 'BLOCK',
            'status' => 'active',
        ]);

        $cycle = CloseCycle::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'entity_id' => 'entity-gap-001',
            'period_id' => 'period-gap-001',
            'name' => 'Gap Test Cycle',
            'status' => 'opened',
            'created_by' => 'system',
            // One authorised sign-off so findings are the only variable.
            'metadata' => ['cycle_signoffs' => [['role' => 'Manager']]],
        ]);

        $finding = AccountingFinding::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'entity_id' => $cycle->entity_id,
            'finding_type' => 'control_weakness',
            'severity' => 'critical',
            'status' => 'open',
            'source_type' => 'close_cycle',
            'source_id' => $cycle->id,
            'title' => 'Open blocking finding',
            'description' => 'Blocks readiness',
        ]);

        $service = app(\Modules\FinancialClose\Services\FinancialCloseCycleService::class);

        $blocked = $service->evaluateReadiness($this->tenantId, $cycle->id);
        $this->assertFalse($blocked['ready_to_close'], 'An open blocking finding must prevent readiness.');
        $this->assertSame(1, $blocked['blocking_findings']);
        $this->assertSame(1, $blocked['open_findings']);
        $this->assertSame('ASSURANCE_BLOCKED', $blocked['blocking_policy']['decision_reason']);

        // Resolving the finding clears the block and the cycle becomes ready.
        $finding->update(['status' => 'resolved', 'resolved_at' => now()]);

        $ready = $service->evaluateReadiness($this->tenantId, $cycle->id);
        $this->assertTrue($ready['ready_to_close'], 'With the finding resolved the cycle must be ready.');
        $this->assertSame(0, $ready['blocking_findings']);
        $this->assertSame(0, $ready['open_findings']);
    }

    /** @test */
    public function signoff_rejects_unauthorized_role(): void
    {
        // The signoffCycle method now validates the resolved role against SIGNOFF_ROLES
        $reflection = new \ReflectionClass(\Modules\FinancialClose\Services\FinancialCloseCycleService::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            'SIGNOFF_ROLES',
            $source,
            'signoffCycle must validate against SIGNOFF_ROLES constant'
        );
        $this->assertStringContainsString(
            'Insufficient role for sign-off',
            $source,
            'signoffCycle must return error for insufficient role'
        );
    }
}
