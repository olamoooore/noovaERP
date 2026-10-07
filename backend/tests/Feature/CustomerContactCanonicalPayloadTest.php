<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ADR-003B W4: the customer contact dialog sends only the canonical ADR-003A
 * fields (functional category, classification, persistent roles, department)
 * and no legacy contact_category / contact_type.
 *
 * ADR-003A CT-VEN-021: the legacy columns are read projections derived from
 * the canonical values. They must be projected, not blanked, when the
 * request carries only canonical fields — other screens (invoice billing
 * contact, contact cards, exports) still read them.
 *
 * Every test goes through the real HTTP routes.
 */
class CustomerContactCanonicalPayloadTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantUuid,
            'name' => 'Canonical Contact Payload Tenant',
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

    public function test_canonical_only_payload_projects_the_legacy_columns(): void
    {
        $customerId = $this->createCustomer();

        $contact = $this->postJson("/api/v1/customers/{$customerId}/contacts", [
            'name' => 'Siti',
            'email' => 'siti@example.test',
            'functional_category_code' => 'FINANCE',
            'persistent_role_codes' => ['BILLING_CONTACT'],
            'department_name' => 'Accounts Payable',
        ])->assertCreated();

        $this->assertSame('FINANCE', $contact->json('contact_category'));
        $this->assertSame(
            'Billing Contact',
            $contact->json('contact_type'),
            'contact_type must be projected from the canonical role, not left empty.'
        );
    }

    public function test_canonical_only_edit_of_a_legacy_contact_keeps_a_projected_type(): void
    {
        $customerId = $this->createCustomer();

        // A contact created the old way (legacy category/type).
        $legacy = $this->postJson("/api/v1/customers/{$customerId}/contacts", [
            'name' => 'Siti',
            'email' => 'siti@example.test',
            'contact_category' => 'Finance',
            'contact_type' => 'Billing',
        ])->assertCreated();
        $this->assertNotNull($legacy->json('contact_type'));

        // Edited through the new dialog: canonical fields only.
        $edited = $this->putJson("/api/v1/customers/{$customerId}/contacts/{$legacy->json('id')}", [
            'name' => 'Siti Aminah',
            'functional_category_code' => 'FINANCE',
            'persistent_role_codes' => ['BILLING_CONTACT'],
            'department_name' => null,
        ])->assertOk();

        $this->assertSame('Billing Contact', $edited->json('contact_type'));
        $this->assertSame('FINANCE', $edited->json('contact_category'));
    }

    private function createCustomer(): int
    {
        return (int) $this->postJson('/api/v1/customers', [
            'name' => 'Acme Trading',
            'type' => 'company',
        ])->assertCreated()->json('id');
    }
}
