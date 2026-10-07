<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Bug found by the ADR-003B linking report: CustomerContactSyncService::
 * matchPersonIdentity() builds its tier-1 condition as "email = x OR
 * mobile = y". When a contact has neither, the condition group is empty, so
 * the query has no condition and returns the FIRST person in the tenant —
 * the new contact is silently linked to an unrelated person. Tier 2 has the
 * same flaw inside one company when email, mobile and phone are all empty.
 *
 * Business rule (ADR-003A §9): only a shared identifier may auto-link a
 * person; with no identifier there is no automatic match and a new person
 * is created.
 *
 * Every test goes through the real HTTP routes.
 */
class ContactPersonMatchingWithoutIdentifiersTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantUuid,
            'name' => 'Person Matching Tenant',
            'email' => $tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $admin->id,
            'tenant_id' => $tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($admin, 'sanctum');
    }

    public function test_contact_without_email_or_mobile_is_not_linked_to_an_unrelated_person(): void
    {
        $hospital = $this->createCustomer('Acme Hospital');
        $clinic = $this->createCustomer('Bayu Clinic');

        $siti = $this->postJson("/api/v1/customers/{$hospital}/contacts", [
            'name' => 'Siti',
            'email' => 'siti@acme.test',
        ])->assertCreated()->json('core_contact_uuid');

        $ali = $this->postJson("/api/v1/customers/{$clinic}/contacts", [
            'name' => 'Ali',
        ])->assertCreated()->json('core_contact_uuid');

        $this->assertNotNull($ali);
        $this->assertNotSame($siti, $ali, 'A contact with no email or mobile must get its own person, not an unrelated one.');
    }

    public function test_two_people_without_identifiers_at_one_company_stay_two_people(): void
    {
        $hospital = $this->createCustomer('Acme Hospital');

        $first = $this->postJson("/api/v1/customers/{$hospital}/contacts", ['name' => 'Dr Tan'])
            ->assertCreated()->json('core_contact_uuid');
        $second = $this->postJson("/api/v1/customers/{$hospital}/contacts", ['name' => 'Nurse Lim'])
            ->assertCreated()->json('core_contact_uuid');

        $this->assertNotSame($first, $second, 'Two different people with no shared identifier must not be merged.');
    }

    public function test_a_shared_email_still_links_the_same_person(): void
    {
        $hospital = $this->createCustomer('Acme Hospital');
        $clinic = $this->createCustomer('Bayu Clinic');

        $atHospital = $this->postJson("/api/v1/customers/{$hospital}/contacts", ['name' => 'Dr Tan', 'email' => 'tan@example.test'])
            ->assertCreated()->json('core_contact_uuid');
        $atClinic = $this->postJson("/api/v1/customers/{$clinic}/contacts", ['name' => 'Dr Tan', 'email' => 'TAN@example.test'])
            ->assertCreated()->json('core_contact_uuid');

        $this->assertSame($atHospital, $atClinic, 'A shared email (tier 1) must still link the same person.');
    }

    private function createCustomer(string $name): int
    {
        return (int) $this->postJson('/api/v1/customers', ['name' => $name, 'type' => 'company'])
            ->assertCreated()->json('id');
    }
}
