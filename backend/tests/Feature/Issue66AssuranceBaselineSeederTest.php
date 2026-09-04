<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\Gates\AssuranceGate;
use App\Models\AssuranceBlockingPolicy;
use App\Models\User;
use Database\Seeders\AssuranceBlockingPolicySeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue noovaerp/NoovaERP#66 — AssuranceBlockingPolicy must have a
 * baseline seeder so AssuranceGate's blocking branch is live, not dead.
 */
class Issue66AssuranceBaselineSeederTest extends TenantTestCase
{
    private string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = (string) Str::uuid();
        $this->bindTenantContext($this->tenantUuid);

        $user = User::factory()->create();
        Auth::login($user);
    }

    public function test_seeder_provisions_baseline_rows_for_bound_tenant(): void
    {
        $this->assertSame(0, AssuranceBlockingPolicy::where('tenant_id', $this->tenantUuid)->count());

        $this->seed(AssuranceBlockingPolicySeeder::class);

        $rows = AssuranceBlockingPolicy::where('tenant_id', $this->tenantUuid)->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->contains(fn ($r) => $r->block_mode === 'BLOCK' && $r->severity_threshold === 'CRITICAL'));
        $this->assertTrue($rows->contains(fn ($r) => $r->block_mode === 'WARN' && $r->severity_threshold === 'HIGH'));
    }

    public function test_gate_blocks_critical_finding_after_seeding(): void
    {
        $this->seed(AssuranceBlockingPolicySeeder::class);

        $gate = app(AssuranceGate::class);
        $result = $gate->evaluate([
            'tenant_id' => $this->tenantUuid,
            'requested_action' => 'POST_JOURNAL',
            'blocking_assurance_findings' => [
                ['id' => 'F-1', 'finding_type' => 'control_weakness', 'assertion_level' => 'STANDARD', 'severity' => 'CRITICAL'],
            ],
        ]);

        $this->assertSame('BLOCK', $result->result->value);
        $this->assertSame('ASSURANCE_BLOCKED', $result->reasonCode);
    }

    public function test_gate_still_passes_below_critical_baseline(): void
    {
        $this->seed(AssuranceBlockingPolicySeeder::class);

        $gate = app(AssuranceGate::class);
        $result = $gate->evaluate([
            'tenant_id' => $this->tenantUuid,
            'requested_action' => 'POST_JOURNAL',
            'blocking_assurance_findings' => [
                ['id' => 'F-2', 'finding_type' => 'anomaly', 'assertion_level' => 'STANDARD', 'severity' => 'high'],
            ],
        ]);

        // HIGH matches the WARN row only; scopeBlocking() filters to BLOCK
        // rows, and the CRITICAL baseline must not fire for high.
        $this->assertSame('PASS', $result->result->value);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(AssuranceBlockingPolicySeeder::class);
        $this->seed(AssuranceBlockingPolicySeeder::class);

        $this->assertSame(2, AssuranceBlockingPolicy::where('tenant_id', $this->tenantUuid)->count());
    }
}
