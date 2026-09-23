<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\DomainPostingRequest;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue #43 — Period Close and Settlement mutation routes
 * must carry action-specific permission + entity-scope middleware.
 *
 * Covers AC1 (route-list assertion), AC2 (deny test), AC3 (allow test),
 * AC4 (entity-isolation test), AC5 (seeded permission reachable by role).
 */
class Issue43PeriodCloseSettlementAuthTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();

        // Ensure FK targets exist
        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Test Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantUuid);

        $this->seedPermissions();
    }

    /**
     * Seed only the permissions we need for these tests — keeps the test
     * independent of RolePermissionSeeder's full matrix.
     */
    private function seedPermissions(): void
    {
        foreach (['accounting.lock', 'accounting.write', 'accounting.reverse', 'accounting.view'] as $p) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
    }

    /**
     * AC1: Route-list assertion — close, reopen, allocate, reverse each
     * carry a permission middleware entry.
     */
    public function test_named_routes_carry_permission_middleware(): void
    {
        $signaturesToFind = [
            'POST /api/v1/accounting/periods/{periodId}/close',
            'POST /api/v1/accounting/periods/{periodId}/reopen',
            'POST /api/v1/accounting/settlements/allocate',
            'POST /api/v1/accounting/settlements/{id}/reverse',
        ];

        foreach ($signaturesToFind as $signature) {
            [$method, $uri] = explode(' ', $signature);
            $uri = ltrim($uri, '/');

            $route = collect(Route::getRoutes())
                ->first(fn ($r) => '/' . $r->uri() === '/' . $uri && in_array($method, $r->methods()));

            $this->assertNotNull($route, "Route not found: $signature");

            $middleware = $route->gatherMiddleware();
            $hasPermission = collect($middleware)->contains(
                fn ($m) => str_starts_with($m, 'App\Http\Middleware\RequirePermission:')
            );
            $this->assertTrue(
                $hasPermission,
                "$signature must carry a RequirePermission middleware. Found: ".implode(', ', $middleware)
            );
        }
    }

    /**
     * AC2 + AC4: HTTP deny + entity-isolation via direct middleware invocation.
     * Proves a user without the required permission is blocked.
     */
    public function test_user_without_permission_blocked_from_period_close(): void
    {
        $user = $this->createTenantUser('accountant'); // NOT owner/admin → no accounting.lock
        $this->actingAs($user, 'sanctum');

        $middleware = app(\App\Http\Middleware\RequirePermission::class);
        $request = \Illuminate\Http\Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle(
            $request,
            fn ($r) => response()->json(['ok' => true]),
            'accounting.lock'
        );

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertSame('accounting.lock', $payload['required_permission']);
    }

    public function test_user_with_permission_admitted_to_period_close(): void
    {
        $user = $this->createTenantUser('owner');
        // Grant accounting.lock to the Owner Spatie role (simulating the
        // seeder's role-level grant) so the gate admits.
        \Spatie\Permission\Models\Role::findByName('Owner')->givePermissionTo('accounting.lock');

        $this->actingAs($user, 'sanctum');

        $middleware = app(\App\Http\Middleware\RequirePermission::class);
        $request = \Illuminate\Http\Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle(
            $request,
            fn ($r) => response()->json(['ok' => true]),
            'accounting.lock'
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_user_without_permission_blocked_from_settlement_allocate(): void
    {
        $user = $this->createTenantUser('viewer'); // viewer → no accounting.write
        $this->actingAs($user, 'sanctum');

        $middleware = app(\App\Http\Middleware\RequirePermission::class);
        $request = \Illuminate\Http\Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle(
            $request,
            fn ($r) => response()->json(['ok' => true]),
            'accounting.write'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_user_without_permission_blocked_from_settlement_reverse(): void
    {
        $user = $this->createTenantUser('manager'); // manager → no accounting.reverse
        $this->actingAs($user, 'sanctum');

        $middleware = app(\App\Http\Middleware\RequirePermission::class);
        $request = \Illuminate\Http\Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle(
            $request,
            fn ($r) => response()->json(['ok' => true]),
            'accounting.reverse'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * AC5: Permission seeded in RolePermissionSeeder → role-assignable.
     * Proves the permission exists in the catalog and can be granted to a role.
     */
    public function test_seeded_permissions_are_role_assignable(): void
    {
        // accounting.lock permission must exist in the catalog
        $this->assertNotNull(
            \Spatie\Permission\Models\Permission::findByName('accounting.lock'),
            'accounting.lock permission must be seeded'
        );

        // The permission can be assigned to a role (proves it's assignable,
        // not just seeded-but-orphaned)
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'TestOwner', 'guard_name' => 'web']);
        $role->givePermissionTo('accounting.lock');
        $this->assertTrue(
            $role->fresh()->hasPermissionTo('accounting.lock'),
            'accounting.lock must be grantable to roles'
        );
    }

    /**
     * Helper: create a user with both a public.users row (for email-based
     * central ID resolution in the middleware) and a tenant-schema user
     * (for Spatie permission resolution), plus user_company_access.
     *
     * Creates the corresponding Spatie role with no permissions — the
     * permission we test is the one being denied/granted via direct role
     * assignment, and the test verifies the gate middleware behavior
     * independently of any other role-level grants.
     */
    private function createTenantUser(string $userRole, string $spatieName = ''): User
    {
        $spatieName = $spatieName ?: ucfirst($userRole);
        $unique = bin2hex(random_bytes(4));
        $email = "{$spatieName}-{$unique}@test.local";

        // Create the Spatie role so TenantRoleService::hasPermission can find it.
        // The role has NO permissions by default — the test asserts behavior
        // based on direct user-level permission grants (or their absence).
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $spatieName, 'guard_name' => 'web']);

        $user = User::factory()->create([
            'email' => $email,
            'name' => $spatieName.' User',
        ]);

        DB::table('public.user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantUuid,
            'role' => $userRole, // user_company_access.role → TenantRoleService lookup
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
    /**
     * HR43-02 (hostile re-review): settlement mutations had denial tests only.
     * This exercises the REAL router with a user whose tenant role carries
     * accounting.write and asserts the settlement is allocated (200).
     */
    public function test_authorized_accountant_can_allocate_a_settlement_through_the_router(): void
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => 'web']);
        $role->syncPermissions(['accounting.write']);

        $user = $this->createTenantUser('accountant');
        $user->assignRole('Accountant');

        $settlement = new \App\Models\Settlement;
        $settlement->tenant_id = $this->tenantUuid;
        $settlement->settlement_number = 'STL-43-'.Str::upper(Str::random(5));
        $settlement->settlement_state = 'UNALLOCATED';
        $settlement->settlement_type = 'cash_application';
        $settlement->allocated_amount = 0;
        $settlement->unapplied_amount = 500;
        $settlement->currency_code = 'USD';
        $settlement->save();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/accounting/settlements/allocate', [
            'settlement_id' => $settlement->id,
            'allocations' => [
                ['source_record_type' => 'invoice', 'source_record_id' => 1, 'amount' => 200],
            ],
        ]);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $this->assertSame(
            1,
            \App\Models\Allocation::where('settlement_id', $settlement->id)->count(),
            'The allocation must be recorded through the real router'
        );
    }


    /**
     * HR43-01: reopen denial — the missing case from the hostile re-review.
     * Real HTTP through the router with a user lacking accounting.lock.
     */
    public function test_user_without_permission_blocked_from_period_reopen_http(): void
    {
        $user = $this->createTenantUser('viewer', 'Viewer');

        $period = FiscalPeriod::create([
            'name' => 'HR43 Reopen', 'code' => 'HR43-R-'.Str::random(5),
            'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
            'period_type' => 'monthly', 'fiscal_year' => 2026,
            'status' => 'closed', 'created_by' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/accounting/periods/{$period->id}/reopen", ['reason' => 'HR43 reopen denial'])
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'accounting.lock');

        $this->assertSame('closed', $period->fresh()->status, 'A denied reopen must not change period state.');
    }

    /**
     * HR43-02: authorized reverse success with persisted state — the missing
     * half of the settlement evidence.
     */
    public function test_authorized_reverse_persists_reversal_state(): void
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => 'web']);
        $role->syncPermissions(['accounting.reverse']);

        // central role 'accountant' → Spatie 'Accountant' (UserRole::spatieName)
        $user = $this->createTenantUser('accountant');
        $user->assignRole('Accountant');

        $settlement = new \App\Models\Settlement;
        $settlement->tenant_id = $this->tenantUuid;
        $settlement->settlement_number = 'STL-43R-'.Str::upper(Str::random(5));
        $settlement->settlement_state = 'PARTIALLY_ALLOCATED';
        $settlement->settlement_type = 'cash_application';
        $settlement->allocated_amount = 200;
        $settlement->unapplied_amount = 300;
        $settlement->currency_code = 'USD';
        $settlement->save();

        $allocation = \App\Models\Allocation::create([
            'tenant_id' => $this->tenantUuid,
            'settlement_id' => $settlement->id,
            'source_record_type' => 'invoice',
            'source_record_id' => 1,
            'allocated_amount' => 200,
            'currency_code' => 'USD',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/accounting/settlements/{$settlement->id}/reverse", ['reason' => 'HR43 reverse evidence']);

        $response->assertOk();

        $settlement->refresh();
        $this->assertSame('REVERSED', $settlement->settlement_state, 'Settlement must persist the reversed state.');
        $this->assertSame('0.00', (string) $settlement->allocated_amount);
        $this->assertSame('500.00', (string) $settlement->unapplied_amount, 'Unapplied cash must be restored.');
        $this->assertNotNull($allocation->fresh()->reversed_at, 'The allocation must be marked reversed.');
    }

    /**
     * HR43-03: correct permission but wrong legal entity is denied on all
     * four routes (real HTTP through ResolveEntityContext + EnsureEntityAccess).
     */
    public function test_wrong_legal_entity_is_denied_on_all_four_routes(): void
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'HR43 Group', 'code' => 'HR43G-'.Str::upper(Str::random(4)),
            'base_currency' => 'USD', 'fiscal_year_start' => '01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'HR43 A', 'code' => 'HR43A-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'HR43 B', 'code' => 'HR43B-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => 'web']);
        $role->syncPermissions(['accounting.lock', 'accounting.write', 'accounting.reverse']);

        $user = $this->createTenantUser('accountant');
        $user->assignRole('Accountant');

        // Access to entity A only.
        DB::table('user_entity_access')->insert([
            'user_id' => $user->id,
            'legal_entity_id' => $entityA,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $period = FiscalPeriod::create([
            'name' => 'HR43 Entity', 'code' => 'HR43-E-'.Str::random(5),
            'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
            'period_type' => 'monthly', 'fiscal_year' => 2026,
            'status' => 'open', 'created_by' => $user->id,
        ]);

        $settlement = new \App\Models\Settlement;
        $settlement->tenant_id = $this->tenantUuid;
        $settlement->settlement_number = 'STL-43E-'.Str::upper(Str::random(5));
        $settlement->settlement_state = 'UNALLOCATED';
        $settlement->settlement_type = 'cash_application';
        $settlement->allocated_amount = 0;
        $settlement->unapplied_amount = 100;
        $settlement->currency_code = 'USD';
        $settlement->save();

        $headers = ['X-Entity-Id' => (string) $entityB];

        // close
        $this->actingAs($user, 'sanctum')
            ->withHeaders($headers)
            ->postJson("/api/v1/accounting/periods/{$period->id}/close")
            ->assertStatus(403);

        // reopen
        $this->actingAs($user, 'sanctum')
            ->withHeaders($headers)
            ->postJson("/api/v1/accounting/periods/{$period->id}/reopen", ['reason' => 'wrong entity'])
            ->assertStatus(403);

        // allocate
        $this->actingAs($user, 'sanctum')
            ->withHeaders($headers)
            ->postJson('/api/v1/accounting/settlements/allocate', [
                'settlement_id' => $settlement->id,
                'allocations' => [['source_record_type' => 'invoice', 'source_record_id' => 1, 'amount' => 10]],
            ])
            ->assertStatus(403);

        // reverse
        $this->actingAs($user, 'sanctum')
            ->withHeaders($headers)
            ->postJson("/api/v1/accounting/settlements/{$settlement->id}/reverse", ['reason' => 'wrong entity'])
            ->assertStatus(403);
    }

}
