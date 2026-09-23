<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #100 (HIGH) — the e-invoicing/compliance routes only carried
 * tenant-resolution middleware: any authenticated tenant user could submit,
 * retry or CANCEL compliance submissions and mutate per-country compliance
 * configuration.
 *
 * These tests exercise the real router/middleware chain (postJson/getJson),
 * mirroring HR175DeliveryOrderPermissionEnforcementTest.
 */
class HR100ComplianceRoutePermissionTest extends TenantTestCase
{
    protected User $viewer;

    protected User $accountant;

    protected User $admin;

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
            'name' => 'Compliance RBAC Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('Viewer');

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('Accountant');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        foreach ([
            ['user' => $this->viewer, 'role' => 'viewer'],
            ['user' => $this->accountant, 'role' => 'accountant'],
            ['user' => $this->admin, 'role' => 'admin'],
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
    }

    private function configPayload(): array
    {
        return [
            'is_enabled' => false,
            'test_mode' => true,
            'provider_name' => 'hr100-test',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Submission lifecycle mutations require accounting.post
    // ─────────────────────────────────────────────────────────────────────

    public function test_viewer_cannot_submit_a_document(): void
    {
        $response = $this->actingAs($this->viewer)
            ->postJson('/api/v1/accounting/compliance/documents/1/submit', ['country_code' => 'MY']);

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'accounting.post');
    }

    public function test_viewer_cannot_retry_cancel_sync_or_sign(): void
    {
        foreach ([
            ['post', '/api/v1/accounting/compliance/submissions/1/retry'],
            ['post', '/api/v1/accounting/compliance/submissions/1/cancel'],
            ['post', '/api/v1/accounting/compliance/submissions/1/sync'],
            ['post', '/api/v1/accounting/compliance/documents/1/sign'],
            ['post', '/api/v1/accounting/compliance/sync-pending'],
        ] as [$verb, $uri]) {
            $this->actingAs($this->viewer)
                ->{$verb.'Json'}($uri)
                ->assertStatus(403, "Viewer must be denied {$verb} {$uri}");
        }
    }

    public function test_viewer_cannot_submit_source_documents(): void
    {
        foreach ([
            '/api/v1/accounting/compliance/credit-notes/1/submit',
            '/api/v1/accounting/compliance/bills/1/submit',
            '/api/v1/accounting/compliance/vendor-debit-notes/1/submit',
            '/api/v1/accounting/compliance/journal-entries/1/submit',
        ] as $uri) {
            $this->actingAs($this->viewer)->postJson($uri)->assertStatus(403);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Configuration mutation requires accounting.settings (Owner/Admin only)
    // ─────────────────────────────────────────────────────────────────────

    public function test_viewer_cannot_update_country_compliance_config(): void
    {
        $response = $this->actingAs($this->viewer)
            ->putJson('/api/v1/accounting/compliance/config/MY', $this->configPayload());

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'accounting.settings');
    }

    public function test_accountant_cannot_update_country_compliance_config(): void
    {
        // accounting.post is not enough for configuration changes.
        $this->actingAs($this->accountant)
            ->putJson('/api/v1/accounting/compliance/config/MY', $this->configPayload())
            ->assertStatus(403);
    }

    public function test_admin_passes_the_config_permission_gate(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson('/api/v1/accounting/compliance/config/MY', $this->configPayload());

        $this->assertNotSame(403, $response->getStatusCode(), 'Admin must pass the permission gate: '.$response->getContent());
    }

    public function test_country_plugin_settings_require_settings_permission(): void
    {
        $this->actingAs($this->viewer)
            ->putJson('/api/v1/accounting/tenant/country-plugin-settings', ['is_enabled' => true])
            ->assertStatus(403);

        $this->actingAs($this->accountant)
            ->putJson('/api/v1/accounting/tenant/country-plugin-settings', ['is_enabled' => true])
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reads + legitimate access preserved
    // ─────────────────────────────────────────────────────────────────────

    public function test_viewer_can_read_compliance_documents(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/accounting/compliance/documents')
            ->assertStatus(200);

        $this->actingAs($this->viewer)
            ->getJson('/api/v1/accounting/compliance/countries')
            ->assertStatus(200);
    }

    public function test_accountant_passes_the_submission_permission_gate(): void
    {
        // accounting.post is granted to Accountant; the controller may still
        // 404 on a missing document — what matters is it is NOT a 403.
        $response = $this->actingAs($this->accountant)
            ->postJson('/api/v1/accounting/compliance/sync-pending');

        $this->assertNotSame(403, $response->getStatusCode(), 'Accountant must pass accounting.post');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/accounting/compliance/sync-pending')->assertStatus(401);
    }
}
