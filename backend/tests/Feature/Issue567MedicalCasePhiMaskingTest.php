<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\MedicalCase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #567 (MC5) — the patient name (PHI) was returned in full by every
 * medical-case read to anyone holding medical_cases.view.
 *
 * Decision on #919 (2 Oct 2026, ADR-007 v0.3 §16A): sensitive fields are
 * masked on the model by the viewer's access (medical_cases.phi.view); a
 * user who can't read a field can't write it; a masked value sent back is
 * never saved; unmasked reads are audited as one summary per request.
 *
 * The seeder grants medical_cases.phi.view to every role that can view
 * cases, so the "no PHI access" users here are roles a tenant has narrowed
 * (the permission revoked from Viewer and Sales in this tenant).
 *
 * Real HTTP through the router.
 */
class Issue567MedicalCasePhiMaskingTest extends TenantTestCase
{
    private const PATIENT = 'Jane Doe';

    private const MASKED = 'J*** D***';

    private User $viewer;

    private User $salesUser;

    private User $phiUser;

    private Customer $customer;

    private MedicalCase $case;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Viewer', 'Sales', 'Manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // This tenant has narrowed PHI access to managers.
        Role::findByName('Viewer', 'web')->revokePermissionTo('medical_cases.phi.view');
        Role::findByName('Sales', 'web')->revokePermissionTo('medical_cases.phi.view');

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Medical PHI Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('Viewer');

        $this->salesUser = User::factory()->create();
        $this->salesUser->assignRole('Sales');

        $this->phiUser = User::factory()->create();
        $this->phiUser->assignRole('Manager');

        foreach ([
            ['user' => $this->viewer, 'role' => 'viewer'],
            ['user' => $this->salesUser, 'role' => 'sales'],
            ['user' => $this->phiUser, 'role' => 'manager'],
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

        $this->case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'patient_name_encrypted' => self::PATIENT,
            'case_date' => now()->toDateString(),
            'created_by' => $this->phiUser->id,
        ]);
    }

    private function storedPatientName(): ?string
    {
        // The model cast decrypts; no request user here, so read the raw
        // attribute rather than the serialized form.
        return MedicalCase::find($this->case->id)?->getAttribute('patient_name_encrypted');
    }

    private function phiReadAudits()
    {
        return AuditLog::where('operation', 'sensitive_fields.read')->get();
    }

    public function test_viewer_listing_cases_gets_a_masked_patient_name(): void
    {
        $response = $this->actingAs($this->viewer)->getJson('/api/v1/medical-cases');

        $response->assertOk();
        $this->assertStringContainsString(self::MASKED, $response->getContent());
        $this->assertStringNotContainsString(self::PATIENT, $response->getContent());
    }

    public function test_sales_user_viewing_a_case_gets_a_masked_patient_name(): void
    {
        $response = $this->actingAs($this->salesUser)->getJson("/api/v1/medical-cases/{$this->case->id}");

        $response->assertOk();
        $this->assertStringContainsString(self::MASKED, $response->getContent());
        $this->assertStringNotContainsString(self::PATIENT, $response->getContent());

        $this->assertStringNotContainsString(
            self::PATIENT,
            $this->actingAs($this->salesUser)->getJson('/api/v1/medical-cases')->getContent(),
        );
    }

    public function test_phi_permission_holder_gets_the_full_patient_name_and_the_read_is_audited_once(): void
    {
        $list = $this->actingAs($this->phiUser)->getJson('/api/v1/medical-cases');
        $list->assertOk();
        $this->assertStringContainsString(self::PATIENT, $list->getContent());

        $show = $this->actingAs($this->phiUser)->getJson("/api/v1/medical-cases/{$this->case->id}");
        $show->assertOk();
        $this->assertStringContainsString(self::PATIENT, $show->getContent());

        // One summary per request (two requests → two entries), naming the
        // field and the case.
        $audits = $this->phiReadAudits();
        $this->assertCount(2, $audits);

        foreach ($audits as $audit) {
            $this->assertSame('medical_case', $audit->reference_type);
            $this->assertSame($this->phiUser->id, (int) $audit->actor_id);
            $this->assertSame(['patient_name_encrypted'], $audit->metadata['fields']);
            $this->assertContains($this->case->id, $audit->metadata['records']['medical_case']);
        }
    }

    public function test_masked_users_reads_are_not_audited_as_reveals(): void
    {
        $this->actingAs($this->salesUser)->getJson('/api/v1/medical-cases')->assertOk();
        $this->actingAs($this->salesUser)->getJson("/api/v1/medical-cases/{$this->case->id}")->assertOk();

        $this->assertCount(0, $this->phiReadAudits());
    }

    public function test_masked_value_put_back_does_not_overwrite_the_patient_name(): void
    {
        $shown = $this->actingAs($this->salesUser)->getJson("/api/v1/medical-cases/{$this->case->id}");
        $shown->assertOk();
        $this->assertStringContainsString(self::MASKED, $shown->getContent());

        $this->actingAs($this->salesUser)->putJson("/api/v1/medical-cases/{$this->case->id}", [
            'patient_name_encrypted' => self::MASKED,
            'notes' => 'edited by sales',
        ])->assertOk();

        $this->assertSame(self::PATIENT, $this->storedPatientName());
        $this->assertSame('edited by sales', MedicalCase::find($this->case->id)->notes);
    }

    public function test_user_without_phi_access_cannot_change_the_patient_name(): void
    {
        $this->actingAs($this->salesUser)->putJson("/api/v1/medical-cases/{$this->case->id}", [
            'patient_name_encrypted' => 'Someone Else',
        ])->assertForbidden();

        $this->assertSame(self::PATIENT, $this->storedPatientName());
    }

    public function test_user_without_phi_access_cannot_set_a_patient_name_on_create(): void
    {
        $before = MedicalCase::count();

        $this->actingAs($this->salesUser)->postJson('/api/v1/medical-cases', [
            'customer_id' => $this->customer->id,
            'patient_name_encrypted' => 'New Patient',
            'case_date' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertSame($before, MedicalCase::count());
    }

    public function test_phi_permission_holder_can_change_the_patient_name(): void
    {
        $this->actingAs($this->phiUser)->putJson("/api/v1/medical-cases/{$this->case->id}", [
            'patient_name_encrypted' => 'John Smith',
        ])->assertOk();

        $this->assertSame('John Smith', $this->storedPatientName());
    }
}
