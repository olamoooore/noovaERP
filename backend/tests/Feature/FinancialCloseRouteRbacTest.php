<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FinancialClose\Models\CloseCycle;
use Tests\TenantTestCase;

/**
 * ACC-RM-014 (ADR-005 §41, gate G22) — close/lock/reopen/sign-off routes
 * must enforce explicit seeded permissions through the REAL router +
 * middleware stack, for non-super roles.
 *
 * Closes DAILY_REPORT_2026-09-01 §H risk 5 for the RBAC item:
 * Issue38FinancialClosePermissionTest only inspects the route table
 * (gatherMiddleware) and AccountingModuleEndToEndTest calls these routes
 * solely as `admin` — a super-role that bypasses fine-grained checks via
 * UserRole::superRoles(). No prior test ever proved a non-super user is
 * actually rejected (or allowed) at the HTTP layer.
 *
 * Enforcement chain under test:
 *   auth:sanctum → SetTenantFromUserPreference (reads user_company_access)
 *   → EnsureTenantSearchPath → SyncTenantUserFromCentralAuth
 *   → RequirePermission:using('<perm>') → TenantRoleService::hasPermission()
 *   → user_company_access.role → UserRole enum → superRoles() bypass OR
 *     Spatie role from RolePermissionSeeder::PERMISSION_MATRIX.
 */
class FinancialCloseRouteRbacTest extends TenantTestCase
{
    private string $tenantId;

    private User $viewerUser;

    private User $accountantUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        // Real tenants row (tenant binding + SetTenantFromUserPreference FKs)
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Close RBAC Test Tenant',
            'email' => 'close-rbac@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantId);

        // Seeds Spatie roles + the canonical PERMISSION_MATRIX (including
        // accounting.close.read/manage/signoff/admin grants per role).
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        // Non-super users distinguished ONLY by their central tenant role.
        // The `role` column is not mass-assignable since Work Item 1; the
        // authoritative source is user_company_access.role.
        $this->viewerUser = User::factory()->create();
        $this->accountantUser = User::factory()->create();

        foreach ([
            [$this->viewerUser, 'viewer'],
            [$this->accountantUser, 'accountant'],
        ] as [$user, $role]) {
            DB::table('user_company_access')->insert([
                'user_id' => $user->id,
                'tenant_id' => $this->tenantId,
                'role' => $role,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function makeCycle(string $status = 'opened'): CloseCycle
    {
        return CloseCycle::create([
            'tenant_id' => $this->tenantId,
            'entity_id' => 'entity-rbac',
            'period_id' => '2026-04-'.Str::random(6),
            'name' => 'RBAC probe cycle',
            'status' => $status,
            'created_by' => 'preparer',
        ]);
    }

    // ── Authentication gate ─────────────────────────────────────────────────

    public function test_close_routes_reject_unauthenticated_requests(): void
    {
        $response = $this->getJson('/api/v1/close/cycles?entity_id=entity-rbac');

        $response->assertUnauthorized();
    }

    // ── Liveness: permissioned reads/writes are NOT blanket-denied ─────────

    public function test_viewer_can_read_cycle_list_via_real_router(): void
    {
        // matrix: accounting.close.read includes VIEWER
        $response = $this->actingAs($this->viewerUser)
            ->getJson('/api/v1/close/cycles?entity_id=entity-rbac');

        $response->assertOk();
    }

    public function test_accountant_can_create_cycle_via_real_router(): void
    {
        // matrix: accounting.close.manage includes ACCOUNTANT (non-super)
        $response = $this->actingAs($this->accountantUser)->postJson('/api/v1/close/cycles', [
            'entity_id' => 'entity-rbac',
            'period_id' => '2026-04-RBAC',
            'name' => 'Accountant-created cycle',
        ]);

        $this->assertContains($response->status(), [200, 201], 'Expected cycle creation to pass the RBAC gate: '.json_encode($response->json()));
        $this->assertDatabaseHas('fco_close_cycles', [
            'tenant_id' => $this->tenantId,
            'period_id' => '2026-04-RBAC',
        ]);
    }

    // ── Negatives: every unauthorised (role, permission) pair is 403 ───────

    public function test_viewer_cannot_create_cycle(): void
    {
        $response = $this->actingAs($this->viewerUser)->postJson('/api/v1/close/cycles', [
            'entity_id' => 'entity-rbac',
            'period_id' => '2026-04-VIEWER',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('fco_close_cycles', ['period_id' => '2026-04-VIEWER']);
    }

    public function test_viewer_cannot_sign_off_cycle(): void
    {
        // matrix: accounting.close.signoff = OWNER, ADMIN, ACCOUNTANT only
        $cycle = $this->makeCycle();

        $response = $this->actingAs($this->viewerUser)
            ->postJson("/api/v1/close/cycles/{$cycle->id}/signoff", ['notes' => 'viewer try']);

        $response->assertForbidden();
        $meta = $cycle->fresh()->metadata ?? [];
        $this->assertEmpty($meta['cycle_signoffs'] ?? []);
    }

    public function test_accountant_cannot_perform_final_close(): void
    {
        // matrix: accounting.close.admin = OWNER, ADMIN only → ACCOUNTANT denied
        $cycle = $this->makeCycle('ready_to_close');

        $response = $this->actingAs($this->accountantUser)
            ->postJson("/api/v1/close/cycles/{$cycle->id}/close");

        $response->assertForbidden();
        $this->assertSame('ready_to_close', $cycle->fresh()->status);
    }

    public function test_accountant_cannot_reopen_period(): void
    {
        $response = $this->actingAs($this->accountantUser)
            ->postJson('/api/v1/close/cycles/reopen-period', [
                'entity_id' => 'entity-rbac',
                'period_id' => '2026-04-RBAC',
                'reason' => 'rbac probe',
            ]);

        $response->assertForbidden();
    }
}
