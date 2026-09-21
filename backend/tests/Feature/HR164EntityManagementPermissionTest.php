<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #164 (HIGH) — Entity Management (groups/legal entities/branches/books
 * + per-entity module activation) was reachable by the routine 'accountant'
 * role via the blanket EnsureAccountingWriteAccess gate. It now additionally
 * requires entities.manage (Owner/Admin only).
 *
 * Real HTTP through the router.
 */
class HR164EntityManagementPermissionTest extends TenantTestCase
{
    protected User $accountant;

    protected User $admin;

    protected User $viewer;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Viewer', 'Accountant', 'Admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Entity Mgmt RBAC Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('Accountant');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('Viewer');

        foreach ([
            ['user' => $this->accountant, 'role' => 'accountant'],
            ['user' => $this->admin, 'role' => 'admin'],
            ['user' => $this->viewer, 'role' => 'viewer'],
        ] as $entry) {
            DB::table('user_company_access')->insert([
                'user_id' => $entry['user']->id,
                'tenant_id' => $this->tenantId,
                'role' => $entry['role'],
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Currency::query()->updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true]
        );
    }

    private function groupPayload(): array
    {
        return [
            'name' => 'Perm Test Group',
            'code' => 'PTG-'.Str::upper(Str::random(6)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Accountant (accounting.write, NOT entities.manage) must be denied
    // ─────────────────────────────────────────────────────────────────────

    public function test_accountant_cannot_create_group(): void
    {
        $response = $this->actingAs($this->accountant)
            ->postJson('/api/v1/accounting/groups', $this->groupPayload());

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'entities.manage');
    }

    public function test_accountant_cannot_create_legal_entity_branch_or_book(): void
    {
        foreach ([
            ['/api/v1/accounting/legal-entities', ['group_id' => 1, 'name' => 'X', 'code' => 'X-1', 'base_currency' => 'USD']],
            ['/api/v1/accounting/branches', ['legal_entity_id' => 1, 'name' => 'B', 'code' => 'B-1']],
            ['/api/v1/accounting/entity-books', ['legal_entity_id' => 1, 'name' => 'Book', 'code' => 'BK-1']],
        ] as [$uri, $payload]) {
            $this->actingAs($this->accountant)
                ->postJson($uri, $payload)
                ->assertStatus(403, "Accountant must be denied POST {$uri}");
        }
    }

    public function test_accountant_cannot_activate_or_deactivate_entity_modules(): void
    {
        $this->actingAs($this->accountant)
            ->postJson('/api/v1/accounting/legal-entities/1/modules/activate', ['module_code' => 'sales'])
            ->assertStatus(403);

        $this->actingAs($this->accountant)
            ->postJson('/api/v1/accounting/legal-entities/1/modules/deactivate', ['module_code' => 'sales'])
            ->assertStatus(403);

        $this->actingAs($this->accountant)
            ->postJson('/api/v1/accounting/legal-entities/1/modules/industry-pack', ['industry_code' => 'retail'])
            ->assertStatus(403);
    }

    public function test_accountant_cannot_update_or_delete_entities(): void
    {
        $this->actingAs($this->accountant)->putJson('/api/v1/accounting/groups/1', $this->groupPayload())->assertStatus(403);
        $this->actingAs($this->accountant)->deleteJson('/api/v1/accounting/groups/1')->assertStatus(403);
        $this->actingAs($this->accountant)->deleteJson('/api/v1/accounting/legal-entities/1')->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Owner/Admin retain the capability; reads are unaffected
    // ─────────────────────────────────────────────────────────────────────

    public function test_admin_passes_the_entities_manage_gate(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/groups', $this->groupPayload());

        $response->assertStatus(201, $response->getContent());
    }

    public function test_accountant_can_still_read_entity_structure(): void
    {
        $this->actingAs($this->accountant)->getJson('/api/v1/accounting/groups')->assertStatus(200);
        $this->actingAs($this->accountant)->getJson('/api/v1/accounting/legal-entities')->assertStatus(200);
    }

    public function test_viewer_is_denied_by_the_accounting_write_gate(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/accounting/groups', $this->groupPayload())
            ->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/accounting/groups', $this->groupPayload())->assertStatus(401);
    }
}
