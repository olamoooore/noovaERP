<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Services\ContactTaxonomyService;
use Tests\TenantTestCase;

/**
 * Issue #123 (hostile re-review 2026-09-23) — healthcare taxonomy gating and
 * tenant extensibility:
 *
 *   1. Gating is tenant state (`contacts.healthcare_taxonomy`), not a
 *      hard-coded UUID list in config.
 *   2. A real API exists to add tenant-owned codes, with validation.
 *   3. Non-healthcare tenants seeded before gating have their healthcare-only
 *      codes removed (unreferenced) or demoted to tenant-owned (referenced).
 */
class HR123ContactTaxonomyGatingTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = (string) $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->assertTenantSchemaReady([
            'settings', 'contact_taxonomy', 'contact_groups',
            'contact_group_members', 'contact_roles', 'core_contacts',
        ]);
    }

    private function asAdmin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR123 Tenant', 'email' => 'hr123-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'admin', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        return $user;
    }

    private function taxonomyCodes(): array
    {
        return collect(app(ContactTaxonomyService::class)->list($this->tenantId))
            ->pluck('code')
            ->all();
    }

    public function test_generic_vocabulary_when_setting_is_false(): void
    {
        Setting::setValue('contacts.healthcare_taxonomy', false);

        $codes = $this->taxonomyCodes();

        $this->assertContains('OTHER', $codes);
        $this->assertNotContains('DOCTOR', $codes, 'Non-healthcare tenants must not receive DOCTOR.');
        $this->assertNotContains('NURSE', $codes);
        $this->assertNotContains('CLINICAL', $codes);
    }

    public function test_healthcare_vocabulary_when_setting_is_true(): void
    {
        Setting::setValue('contacts.healthcare_taxonomy', true);

        $codes = $this->taxonomyCodes();

        $this->assertContains('DOCTOR', $codes);
        $this->assertContains('NURSE', $codes);
        $this->assertContains('CLINICAL', $codes);
        $this->assertContains('OT_CONTACT', $codes);
    }

    public function test_add_code_api_creates_a_tenant_code(): void
    {
        Setting::setValue('contacts.healthcare_taxonomy', false);
        $user = $this->asAdmin();

        $response = $this->actingAs($user)->postJson('/api/v1/contacts/taxonomy', [
            'taxonomy_type' => 'persistent_role',
            'code' => 'RETAIL_MANAGER',
            'display_label' => 'Retail Manager',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.code', 'RETAIL_MANAGER');
        $response->assertJsonPath('data.is_system', false);

        $this->assertContains('RETAIL_MANAGER', $this->taxonomyCodes());
    }

    public function test_add_code_api_rejects_duplicates_and_invalid_codes(): void
    {
        Setting::setValue('contacts.healthcare_taxonomy', false);
        $user = $this->asAdmin();

        $this->actingAs($user)->postJson('/api/v1/contacts/taxonomy', [
            'taxonomy_type' => 'persistent_role',
            'code' => 'RETAIL_MANAGER',
            'display_label' => 'Retail Manager',
        ])->assertStatus(201);

        // Duplicate.
        $this->actingAs($user)->postJson('/api/v1/contacts/taxonomy', [
            'taxonomy_type' => 'persistent_role',
            'code' => 'RETAIL_MANAGER',
            'display_label' => 'Duplicate',
        ])->assertStatus(422);

        // Collision with a reserved code.
        $this->actingAs($user)->postJson('/api/v1/contacts/taxonomy', [
            'taxonomy_type' => 'persistent_role',
            'code' => 'BILLING_CONTACT',
            'display_label' => 'Billing',
        ])->assertStatus(422);

        // Invalid shape.
        $this->actingAs($user)->postJson('/api/v1/contacts/taxonomy', [
            'taxonomy_type' => 'persistent_role',
            'code' => 'lower case!',
            'display_label' => 'Bad',
        ])->assertStatus(422);

        // Empty label.
        $this->actingAs($user)->postJson('/api/v1/contacts/taxonomy', [
            'taxonomy_type' => 'persistent_role',
            'code' => 'SITE_FOREMAN',
            'display_label' => '',
        ])->assertStatus(422);
    }

    public function test_existing_healthcare_codes_are_treated_on_non_healthcare_tenants(): void
    {
        // Simulate a tenant seeded before gating: DOCTOR referenced by a
        // member, NURSE unreferenced, CLINICAL_CONTACT referenced by a role.
        $contactId = (string) Str::uuid();
        DB::table('core_contacts')->insert([
            'id' => $contactId,
            'tenant_id' => $this->tenantId,
            'display_name' => 'Referenced Contact',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $doctorGroupId = (string) Str::uuid();
        $nurseGroupId = (string) Str::uuid();
        DB::table('contact_groups')->insert([
            [
                'id' => $doctorGroupId, 'tenant_id' => $this->tenantId,
                'group_name' => 'DOCTOR', 'group_type' => 'classification',
                'description' => 'Doctor', 'sort_order' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => $nurseGroupId, 'tenant_id' => $this->tenantId,
                'group_name' => 'NURSE', 'group_type' => 'classification',
                'description' => 'Nurse', 'sort_order' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        DB::table('contact_group_members')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId,
            'contact_id' => $contactId, 'group_id' => $doctorGroupId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([
            ['persistent_role', 'CLINICAL_CONTACT', 'Clinical Contact'],
            ['persistent_role', 'OT_CONTACT', 'OT Contact'],
            ['functional_category', 'CLINICAL', 'Clinical'],
        ] as $i => [$type, $code, $label]) {
            DB::table('contact_taxonomy')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId,
                'taxonomy_type' => $type, 'code' => $code, 'display_label' => $label,
                'is_system' => true, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('contact_roles')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId,
            'contact_id' => $contactId, 'role' => 'CLINICAL_CONTACT',
            'is_primary' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Run the treatment migration with this tenant bound.
        $migration = require base_path('database/migrations/tenant/2026_09_23_000007_gate_contact_taxonomy_by_setting.php');
        $migration->up();

        // Referenced classification: kept, demoted to tenant-owned.
        $doctor = DB::table('contact_groups')->where('id', $doctorGroupId)->first();
        $this->assertNotNull($doctor, 'A referenced healthcare code must survive.');
        $this->assertFalse((bool) $doctor->is_system, 'A surviving code becomes tenant-owned.');

        // Unreferenced classification: removed.
        $this->assertNull(DB::table('contact_groups')->where('id', $nurseGroupId)->first());

        // Referenced persistent role: demoted, not deleted.
        $clinicalContact = DB::table('contact_taxonomy')
            ->where('tenant_id', $this->tenantId)->where('code', 'CLINICAL_CONTACT')->first();
        $this->assertNotNull($clinicalContact);
        $this->assertFalse((bool) $clinicalContact->is_system);

        // Unreferenced persistent role + functional category: removed.
        $this->assertNull(DB::table('contact_taxonomy')
            ->where('tenant_id', $this->tenantId)->where('code', 'OT_CONTACT')->first());
        $this->assertNull(DB::table('contact_taxonomy')
            ->where('tenant_id', $this->tenantId)->where('code', 'CLINICAL')->first());

        // Setting stamped false for this (non-configured) tenant.
        $this->assertFalse((bool) Setting::getValue('contacts.healthcare_taxonomy'));
    }
}
