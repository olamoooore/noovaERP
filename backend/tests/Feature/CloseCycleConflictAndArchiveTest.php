<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Services\FinancialCloseCycleService;
use Tests\TenantTestCase;

/**
 * Close-cycle lifecycle: one active cycle per (tenant, entity, period), an
 * informative conflict, and archive() to retire a stuck cycle and free the
 * period. (User report: 409 "failed to create circle" with no way out.)
 */
class CloseCycleConflictAndArchiveTest extends TenantTestCase
{
    private string $tenantId;
    private FinancialCloseCycleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'T', 'email' => $this->tenantId.'@e.test',
            'plan' => 'trial', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);
        $this->service = app(FinancialCloseCycleService::class);
    }

    public function test_duplicate_active_cycle_is_blocked_and_archiving_frees_the_period(): void
    {
        $created = $this->service->createCycle($this->tenantId, 'ENT-001', '2025-01', 'u1');
        $this->assertNotNull($created);
        $this->assertSame('draft', $created->status);

        // Second create for same scope -> null (conflict), and the active one is discoverable.
        $this->assertNull($this->service->createCycle($this->tenantId, 'ENT-001', '2025-01', 'u1'));
        $active = $this->service->findActiveCycle($this->tenantId, 'ENT-001', '2025-01');
        $this->assertNotNull($active);
        $this->assertSame($created->id, $active->id);

        // Archive the stuck draft...
        $res = $this->service->archiveCycle($this->tenantId, $created->id, 'u2', 'stale draft');
        $this->assertTrue($res['ok']);
        $this->assertSame('archived', CloseCycle::find($created->id)->status);

        // ...which frees the period for a fresh cycle.
        $recreated = $this->service->createCycle($this->tenantId, 'ENT-001', '2025-01', 'u1');
        $this->assertNotNull($recreated, 'After archiving, a new cycle for the same period must be creatable');
        $this->assertNotEquals($created->id, $recreated->id);
    }

    public function test_archiving_a_closed_cycle_is_rejected(): void
    {
        $created = $this->service->createCycle($this->tenantId, 'ENT-001', '2025-02', 'u1');
        $created->update(['status' => 'closed']);

        $res = $this->service->archiveCycle($this->tenantId, $created->id, 'u2');
        $this->assertFalse($res['ok']);
        $this->assertSame('terminal', $res['code']);
        $this->assertSame('closed', CloseCycle::find($created->id)->status);
    }

    public function test_archiving_is_scoped_to_the_tenant(): void
    {
        $created = $this->service->createCycle($this->tenantId, 'ENT-001', '2025-03', 'u1');
        $res = $this->service->archiveCycle((string) Str::uuid(), $created->id, 'u2');
        $this->assertFalse($res['ok']);
        $this->assertSame('not_found', $res['code']);
        $this->assertSame('draft', CloseCycle::find($created->id)->status);
    }
}
