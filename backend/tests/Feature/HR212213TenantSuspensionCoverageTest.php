<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\TenantSuspendedException;
use App\Models\Tenant;
use App\Tenancy\TenantSuspensionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Tenancy;
use Tests\TenantTestCase;

/**
 * Issues #212 / #213 (regressions from the #172 suspension fix):
 *  #212 — the suspension block only covered the authenticated middleware;
 *         public storefront / IoT / webhooks / jobs bypassed it.
 *  #213 — suspend()/reinstate() and the guardrails marker did an unlocked
 *         read-modify-write of the shared tenants.data column (lost update).
 */
class HR212213TenantSuspensionCoverageTest extends TenantTestCase
{
    private function makeTenant(): Tenant
    {
        $tenant = new Tenant([
            'id' => (string) Str::uuid(),
            'name' => 'HR212 Tenant',
        ]);

        DB::table('public.tenants')->insert([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'email' => 'hr212-'.Str::lower(Str::random(8)).'@test.local',
            'data' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenant;
    }

    private function initialize(Tenant $tenant): void
    {
        // Fire the real TenancyInitialized event with the tenant bound, exactly
        // as tenancy()->initialize() does — this exercises the registered
        // central listener without the DB bootstrapper side effects.
        $tenancy = app(Tenancy::class);
        $tenancy->tenant = $tenant;
        $tenancy->initialized = true;

        event(new TenancyInitialized($tenancy));
    }

    private function rawData(string $tenantId): array
    {
        $raw = DB::table('public.tenants')->where('id', $tenantId)->value('data');

        return is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
    }

    // ─────────────────────────────────────────────────────────────────────
    // #212 — central enforcement covers every initialization entry point
    // ─────────────────────────────────────────────────────────────────────

    public function test_initializing_a_suspended_tenant_throws_centrally(): void
    {
        $tenant = $this->makeTenant();
        TenantSuspensionGuard::mergeTenantData($tenant->id, ['suspended' => true]);

        $this->expectException(TenantSuspendedException::class);
        $this->initialize($tenant);
    }

    public function test_initializing_an_active_tenant_succeeds(): void
    {
        $tenant = $this->makeTenant();

        $this->initialize($tenant);

        $this->assertTrue(app(Tenancy::class)->initialized);
    }

    public function test_enforcement_can_be_bypassed_for_platform_maintenance(): void
    {
        $tenant = $this->makeTenant();
        TenantSuspensionGuard::mergeTenantData($tenant->id, ['suspended' => true]);

        TenantSuspensionGuard::withoutEnforcement(function () use ($tenant) {
            $this->initialize($tenant);
        });

        $this->assertTrue(app(Tenancy::class)->initialized);
    }

    public function test_reinstating_clears_the_flag_so_initialization_resumes(): void
    {
        $tenant = $this->makeTenant();
        TenantSuspensionGuard::mergeTenantData($tenant->id, ['suspended' => true]);

        app(\App\SuperAdmin\Application\TenantOperationService::class)->reinstate($tenant->id, 'restored');

        // No exception after reinstatement.
        $this->initialize($tenant);
        $this->assertFalse((bool) ($this->rawData($tenant->id)['suspended'] ?? false));
    }

    // ─────────────────────────────────────────────────────────────────────
    // #213 — atomic JSON merge (no lost updates)
    // ─────────────────────────────────────────────────────────────────────

    public function test_atomic_merge_preserves_keys_written_by_concurrent_operations(): void
    {
        $tenant = $this->makeTenant();

        // Simulate the race: both writers merge a different key against the
        // same row; each single-statement UPDATE must preserve the other key.
        TenantSuspensionGuard::mergeTenantData($tenant->id, ['is_test' => true]);
        TenantSuspensionGuard::mergeTenantData($tenant->id, ['suspended' => true]);

        $data = $this->rawData($tenant->id);
        $this->assertTrue((bool) ($data['is_test'] ?? false), 'The guardrails marker must survive suspend()');
        $this->assertTrue((bool) ($data['suspended'] ?? false), 'The suspended flag must survive the marker write');
    }

    public function test_atomic_merge_removes_a_key_without_touching_others(): void
    {
        $tenant = $this->makeTenant();

        TenantSuspensionGuard::mergeTenantData($tenant->id, ['is_test' => true, 'suspended' => true]);
        TenantSuspensionGuard::mergeTenantData($tenant->id, [], ['is_test']);

        $data = $this->rawData($tenant->id);
        $this->assertArrayNotHasKey('is_test', $data);
        $this->assertTrue((bool) ($data['suspended'] ?? false));
    }

    public function test_merge_preserves_existing_custom_columns(): void
    {
        $tenant = $this->makeTenant();
        TenantSuspensionGuard::mergeTenantData($tenant->id, ['name' => 'Custom Name', 'plan' => 'pro']);

        TenantSuspensionGuard::mergeTenantData($tenant->id, ['suspended' => true]);

        $data = $this->rawData($tenant->id);
        $this->assertSame('Custom Name', $data['name'] ?? null);
        $this->assertSame('pro', $data['plan'] ?? null);
        $this->assertTrue((bool) ($data['suspended'] ?? false));
    }
}
