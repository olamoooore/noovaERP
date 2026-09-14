<?php

/**
 * HR38-02 / HR38-03 — Financial Close route-authority HTTP acceptance matrix.
 *
 * Issue #38 re-review required, for the five task_write operations
 * (checklist, evidence, link-document, unlink-document, readiness refresh):
 *
 *  - HR38-02: a READ-ONLY actor (holds accounting.close.read, lacks
 *    accounting.close.task_write) is denied through the REAL router/middleware
 *    stack, with denial attributed to the task_write boundary (not auth/tenant/
 *    entity/resource defects), and no protected mutation occurs. A paired
 *    control adds ONLY accounting.close.task_write to the same fixture and the
 *    same path then proceeds.
 *  - HR38-03: an AUTHORIZED actor (accounting.close.task_write) succeeds and the
 *    real downstream state is persisted/queryable.
 *  - Outcome A (HR38-01): both externally reachable surfaces — the authoritative
 *    /api/v1/close/* family and the backward-compatible unprefixed /api/v1/*
 *    family — carry equivalent authoritative authorization.
 *
 * Enforcement chain exercised (never a direct middleware/controller call):
 *   HTTP -> router -> auth:sanctum -> SetTenantFromUserPreference
 *   -> EnsureTenantSearchPath -> SyncTenantUserFromCentralAuth
 *   -> RequirePermission:accounting.close.task_write
 *   -> TenantRoleService::hasPermission (user_company_access.role -> Spatie role
 *      -> RolePermissionSeeder::PERMISSION_MATRIX) -> controller -> service ->
 *   persistence.
 */

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Models\CloseReadinessSnapshot;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Models\SupportingEvidence;
use Modules\FinancialClose\Models\TaskEvidenceLink;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

class HR38FinancialCloseRouteAuthorityTest extends TenantTestCase
{
    private string $tenantId;

    private User $viewer;     // accounting.close.read YES, accounting.close.task_write NO

    private User $accountant; // accounting.close.read YES, accounting.close.task_write YES

    private CloseCycle $cycle;

    private CloseTask $task;

    private string $entityId = 'entity-hr38';

    private string $periodId = '2026-01-hr38';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'HR38 Route Authority Tenant',
            'email' => 'hr38-route@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->viewer = User::factory()->create();
        $this->accountant = User::factory()->create();

        foreach ([[$this->viewer, 'viewer'], [$this->accountant, 'accountant']] as [$user, $role]) {
            DB::table('user_company_access')->insert([
                'user_id' => $user->id,
                'tenant_id' => $this->tenantId,
                'role' => $role,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->cycle = CloseCycle::create([
            'tenant_id' => $this->tenantId,
            'entity_id' => $this->entityId,
            'period_id' => $this->periodId,
            'name' => 'HR38 probe cycle',
            'status' => 'opened',
            'created_by' => 'preparer',
        ]);

        $this->task = $this->makeTask();
    }

    private function makeTask(string $status = 'in_progress', array $metadata = []): CloseTask
    {
        return CloseTask::create([
            'tenant_id' => $this->tenantId,
            'cycle_id' => $this->cycle->id,
            'title' => 'HR38 probe task',
            'status' => $status,
            'priority' => 'normal',
            'metadata' => $metadata,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HR38-02 — DENIAL ATTRIBUTION (prerequisite controls succeed; only the
    // task_write boundary differs)
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function denial_is_attributed_to_task_write_boundary_not_an_unrelated_defect(): void
    {
        $roles = app(TenantRoleService::class);

        // Authentication / tenant membership / access succeed for the viewer:
        // it holds accounting.close.read (proven by a real 200 on a read route).
        $readResponse = $this->actingAs($this->viewer)
            ->getJson("/api/v1/close/tasks/{$this->task->id}");
        $this->assertNotEquals(403, $readResponse->getStatusCode(),
            'Viewer must pass auth/tenant/read controls; a 403 here would mean denial is NOT the task_write boundary.');

        // The viewer holds read but NOT task_write (the exact permission difference).
        $this->assertTrue($roles->hasPermission($this->viewer->id, $this->tenantId, 'accounting.close.read'));
        $this->assertFalse($roles->hasPermission($this->viewer->id, $this->tenantId, 'accounting.close.task_write'));
        $this->assertFalse(in_array('viewer', \App\Enums\UserRole::superRoles(), true),
            'Viewer must not be a super role (which would bypass the fine-grained gate).');

        // The accountant (control) DOES hold task_write.
        $this->assertTrue($roles->hasPermission($this->accountant->id, $this->tenantId, 'accounting.close.task_write'));

        // The target resource belongs to the valid test context.
        $this->assertSame($this->tenantId, $this->task->tenant_id);
    }

    /** @test */
    public function viewer_is_denied_on_all_five_task_write_operations_close_surface(): void
    {
        // 1. checklist mutation
        $r1 = $this->actingAs($this->viewer)
            ->postJson("/api/v1/close/tasks/{$this->task->id}/checklist", ['completed_items' => ['item-a']]);
        $r1->assertForbidden();

        // 2. evidence mutation
        Storage::fake('public');
        $r2 = $this->actingAs($this->viewer)->postJson(
            "/api/v1/close/tasks/{$this->task->id}/evidence",
            ['file' => UploadedFile::fake()->create('viewer-evidence.pdf', 50, 'application/pdf')],
        );
        $r2->assertForbidden();

        // 3. document link
        $r3 = $this->actingAs($this->viewer)
            ->postJson("/api/v1/close/tasks/{$this->task->id}/link-document",
                ['document_type' => 'invoice', 'document_id' => 42, 'title' => 'Viewer link']);
        $r3->assertForbidden();

        // 4. document unlink
        $r4 = $this->actingAs($this->viewer)
            ->deleteJson("/api/v1/close/tasks/{$this->task->id}/link-document",
                ['document_type' => 'invoice', 'document_id' => 42]);
        $r4->assertForbidden();

        // 5. readiness refresh
        $r5 = $this->actingAs($this->viewer)
            ->postJson("/api/v1/close/readiness/{$this->entityId}/{$this->periodId}/refresh");
        $r5->assertForbidden();

        // No protected mutation occurred.
        $this->assertNull($this->task->fresh()->metadata['checklist_completed'] ?? null);
        $this->assertEmpty($this->task->fresh()->metadata['document_links'] ?? []);
        $this->assertSame(0, SupportingEvidence::count());
        $this->assertSame(0, TaskEvidenceLink::count());
        $this->assertSame(0, CloseReadinessSnapshot::where('entity_id', $this->entityId)
            ->where('period_id', $this->periodId)->count());
    }

    /** @test */
    public function paired_control_adding_only_task_write_to_same_fixture_allows_the_same_path(): void
    {
        // Same viewer user, same tenant/entity/resource. Add ONLY the missing
        // accounting.close.task_write authority to the Viewer Spatie role.
        Role::findByName('Viewer', 'web')->givePermissionTo('accounting.close.task_write');

        $response = $this->actingAs($this->viewer)
            ->postJson("/api/v1/close/tasks/{$this->task->id}/checklist", ['completed_items' => ['item-a', 'item-b']]);

        $this->assertContains($response->status(), [200, 201],
            'With only task_write added, the identical path must proceed: '.json_encode($response->json()));
        $this->assertSame(['item-a', 'item-b'], $this->task->fresh()->metadata['checklist_completed']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HR38-03 — AUTHORIZED SUCCESS + PERSISTED STATE (accountant, /close surface)
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function authorized_checklist_mutation_persists_state(): void
    {
        $response = $this->actingAs($this->accountant)
            ->postJson("/api/v1/close/tasks/{$this->task->id}/checklist", ['completed_items' => ['step-1', 'step-2']]);

        $this->assertContains($response->status(), [200, 201], json_encode($response->json()));
        $this->assertSame(['step-1', 'step-2'], $this->task->fresh()->metadata['checklist_completed']);
    }

    /** @test */
    public function authorized_evidence_mutation_persists_state(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->accountant)->postJson(
            "/api/v1/close/tasks/{$this->task->id}/evidence",
            ['file' => UploadedFile::fake()->create('close-evidence.pdf', 80, 'application/pdf')],
        );

        $this->assertSame(201, $response->status(), json_encode($response->json()));
        $this->assertSame(1, SupportingEvidence::count());
        $this->assertSame(1, TaskEvidenceLink::where('task_id', $this->task->id)->count());

        $evidence = SupportingEvidence::first();
        $this->assertSame('attached', $evidence->status);
        $this->assertSame('close-evidence.pdf', $evidence->file_name);
        $this->assertTrue(Storage::disk('public')->exists($evidence->file_path));
    }

    /** @test */
    public function authorized_link_document_persists_relationship(): void
    {
        $response = $this->actingAs($this->accountant)
            ->postJson("/api/v1/close/tasks/{$this->task->id}/link-document",
                ['document_type' => 'invoice', 'document_id' => 77, 'title' => 'Linked invoice']);

        $this->assertSame(201, $response->status(), json_encode($response->json()));
        $links = $this->task->fresh()->metadata['document_links'];
        $this->assertCount(1, $links);
        $this->assertSame('invoice', $links[0]['type']);
        $this->assertSame(77, $links[0]['id']);
    }

    /** @test */
    public function authorized_unlink_document_removes_relationship_without_unrelated_mutation(): void
    {
        // Seed an existing link directly on the task metadata.
        $this->task->update(['metadata' => ['document_links' => [
            ['type' => 'invoice', 'id' => 77, 'title' => 'Linked invoice'],
            ['type' => 'bill', 'id' => 88, 'title' => 'Linked bill'],
        ]]]);

        $response = $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/close/tasks/{$this->task->id}/link-document",
                ['document_type' => 'invoice', 'document_id' => 77]);

        $this->assertContains($response->status(), [200, 201], json_encode($response->json()));
        $links = $this->task->fresh()->metadata['document_links'];
        // The targeted link is retired; the unrelated bill link is preserved.
        $this->assertCount(1, $links);
        $this->assertSame('bill', $links[0]['type']);
        $this->assertSame(88, $links[0]['id']);
    }

    /** @test */
    public function authorized_readiness_refresh_persists_queryable_snapshot(): void
    {
        $response = $this->actingAs($this->accountant)
            ->postJson("/api/v1/close/readiness/{$this->entityId}/{$this->periodId}/refresh",
                ['cycle_id' => $this->cycle->id]);

        $this->assertSame(201, $response->status(), json_encode($response->json()));

        $snapshot = CloseReadinessSnapshot::where('tenant_id', $this->tenantId)
            ->where('entity_id', $this->entityId)
            ->where('period_id', $this->periodId)
            ->latest('id')->first();

        $this->assertNotNull($snapshot, 'Readiness refresh must persist a queryable snapshot.');
        $this->assertNotNull($snapshot->readiness_score);
        $this->assertNotNull($snapshot->generated_at);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HR38-01 Outcome A — both reachable surfaces carry equivalent authority
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function unprefixed_compatibility_variant_enforces_equivalent_authorization(): void
    {
        // Denied variant (viewer) on the backward-compatible unprefixed surface.
        $denied = $this->actingAs($this->viewer)
            ->postJson("/api/v1/tasks/{$this->task->id}/checklist", ['completed_items' => ['x']]);
        $denied->assertForbidden();
        $this->assertNull($this->task->fresh()->metadata['checklist_completed'] ?? null);

        // Allowed variant (accountant) on the same unprefixed surface.
        $allowed = $this->actingAs($this->accountant)
            ->postJson("/api/v1/tasks/{$this->task->id}/checklist", ['completed_items' => ['y']]);
        $this->assertContains($allowed->status(), [200, 201], json_encode($allowed->json()));
        $this->assertSame(['y'], $this->task->fresh()->metadata['checklist_completed']);
    }
}
