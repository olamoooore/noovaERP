<?php

namespace Tests\Feature;

use App\Models\BonusScheme;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issues #161 / #186 (HIGH) — Medical Case, Bonus Scheme and Customer Bonus
 * Scheme Assignment routes carried no RequirePermission middleware and no
 * in-controller authorization: any authenticated tenant user could create,
 * invoice, complete or cancel medical cases and activate/deactivate bonus
 * schemes regardless of role.
 *
 * Real HTTP through the router (not source-string assertions).
 */
class HR161MedicalBonusPermissionEnforcementTest extends TenantTestCase
{
    protected User $viewer;

    protected User $salesUser;

    protected User $manager;

    protected Customer $customer;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Viewer', 'Sales', 'Manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Medical RBAC Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('Viewer');

        // Sales holds medical_cases.write but NOT bonus_schemes.write.
        $this->salesUser = User::factory()->create();
        $this->salesUser->assignRole('Sales');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('Manager');

        foreach ([
            ['user' => $this->viewer, 'role' => 'viewer'],
            ['user' => $this->salesUser, 'role' => 'sales'],
            ['user' => $this->manager, 'role' => 'manager'],
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

        $this->customer = Customer::factory()->create();
    }

    private function medicalCasePayload(): array
    {
        return [
            'customer_id' => $this->customer->id,
            'patient_name_encrypted' => 'RBAC Patient',
            'case_date' => now()->toDateString(),
        ];
    }

    private function draftScheme(): BonusScheme
    {
        return BonusScheme::create([
            'name' => 'RBAC Scheme '.Str::random(4),
            'description' => 'RBAC test',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'draft',
            'created_by' => $this->manager->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Medical cases
    // ─────────────────────────────────────────────────────────────────────

    public function test_viewer_cannot_create_medical_case(): void
    {
        $response = $this->actingAs($this->viewer)->postJson('/api/v1/medical-cases', $this->medicalCasePayload());

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'medical_cases.write');
    }

    public function test_viewer_cannot_run_medical_case_lifecycle_actions(): void
    {
        foreach (['allocate', 'deliver', 'invoice', 'complete', 'cancel'] as $action) {
            $this->actingAs($this->viewer)
                ->postJson("/api/v1/medical-cases/1/{$action}")
                ->assertStatus(403, "Viewer must be denied medical-cases/{$action}");
        }
    }

    public function test_viewer_cannot_update_or_delete_medical_case(): void
    {
        $this->actingAs($this->viewer)->putJson('/api/v1/medical-cases/1', $this->medicalCasePayload())->assertStatus(403);
        $this->actingAs($this->viewer)->deleteJson('/api/v1/medical-cases/1')->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bonus schemes + assignments
    // ─────────────────────────────────────────────────────────────────────

    public function test_viewer_cannot_create_or_activate_bonus_scheme(): void
    {
        $store = $this->actingAs($this->viewer)->postJson('/api/v1/bonus-schemes', [
            'name' => 'Viewer Scheme',
            'start_date' => now()->toDateString(),
        ]);
        $store->assertStatus(403);
        $store->assertJsonPath('required_permission', 'bonus_schemes.write');

        $scheme = $this->draftScheme();

        $this->actingAs($this->viewer)->postJson("/api/v1/bonus-schemes/{$scheme->id}/activate")->assertStatus(403);
        $this->actingAs($this->viewer)->postJson("/api/v1/bonus-schemes/{$scheme->id}/deactivate")->assertStatus(403);
    }

    public function test_viewer_cannot_suspend_or_reactivate_assignments(): void
    {
        $this->actingAs($this->viewer)->postJson('/api/v1/customer-bonus-schemes/1/suspend')->assertStatus(403);
        $this->actingAs($this->viewer)->postJson('/api/v1/customer-bonus-schemes/1/reactivate')->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Legitimate roles preserved
    // ─────────────────────────────────────────────────────────────────────

    public function test_sales_can_create_medical_case_but_not_activate_bonus_scheme(): void
    {
        $created = $this->actingAs($this->salesUser)->postJson('/api/v1/medical-cases', $this->medicalCasePayload());
        $created->assertStatus(201, $created->getContent());

        $scheme = $this->draftScheme();
        $this->actingAs($this->salesUser)
            ->postJson("/api/v1/bonus-schemes/{$scheme->id}/activate")
            ->assertStatus(403, 'Sales must not hold bonus_schemes.write');
    }

    public function test_manager_passes_the_bonus_scheme_write_gate(): void
    {
        $scheme = $this->draftScheme();

        $response = $this->actingAs($this->manager)->postJson("/api/v1/bonus-schemes/{$scheme->id}/activate");

        $this->assertNotSame(403, $response->getStatusCode(), 'Manager must pass bonus_schemes.write: '.$response->getContent());
        $this->assertSame('active', $scheme->fresh()->status);
    }

    public function test_viewer_can_read_medical_cases_and_bonus_schemes(): void
    {
        $this->actingAs($this->viewer)->getJson('/api/v1/medical-cases')->assertStatus(200);
        $this->actingAs($this->viewer)->getJson('/api/v1/bonus-schemes')->assertStatus(200);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/medical-cases', $this->medicalCasePayload())->assertStatus(401);
    }
}
