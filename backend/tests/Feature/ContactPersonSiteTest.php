<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ADR-003B W3 (decisions D3/D4, rule R1): a person listed under a company
 * owns no address; instead the company has `site` addresses and the
 * company-person relationship links the person to one of them.
 *
 * - One site per person per company (a column on the org_person relationship).
 * - A person can have a different site at each company they work with.
 * - The site must be a `site` address of THAT company (R1): another
 *   company's site, or a billing/delivery address, is rejected.
 *
 * Every test goes through the real HTTP routes (router -> middleware ->
 * controller).
 */
class ContactPersonSiteTest extends TenantTestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantUuid,
            'name' => 'Contact Person Site Tenant',
            'email' => $tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_customer_contact_is_linked_to_a_site_of_its_company(): void
    {
        [$customerId, $orgUuid] = $this->createCompanyCustomer('Acme Hospital');
        $siteId = $this->addAddress($orgUuid, 'site', 'Main Clinic, Level 3');

        $contact = $this->postJson("/api/v1/customers/{$customerId}/contacts", [
            'name' => 'Dr Tan',
            'email' => 'tan@example.test',
            'site_address_id' => $siteId,
        ])->assertCreated();

        $relationship = $this->orgPersonRelationship($orgUuid, $contact->json('core_contact_uuid'));
        $this->assertSame($siteId, $relationship['site_address_id'], 'The person must be linked to the company site.');
    }

    public function test_a_person_has_a_different_site_at_each_company(): void
    {
        [$hospitalId, $hospitalOrg] = $this->createCompanyCustomer('Acme Hospital');
        [$clinicId, $clinicOrg] = $this->createCompanyCustomer('Bayu Clinic');
        $hospitalSite = $this->addAddress($hospitalOrg, 'site', 'Main Clinic, Level 3');
        $clinicSite = $this->addAddress($clinicOrg, 'site', 'Bayu Branch');

        $atHospital = $this->postJson("/api/v1/customers/{$hospitalId}/contacts", [
            'name' => 'Dr Tan',
            'email' => 'tan@example.test',
            'site_address_id' => $hospitalSite,
        ])->assertCreated();
        $atClinic = $this->postJson("/api/v1/customers/{$clinicId}/contacts", [
            'name' => 'Dr Tan',
            'email' => 'tan@example.test',
            'site_address_id' => $clinicSite,
        ])->assertCreated();

        $person = $atHospital->json('core_contact_uuid');
        $this->assertSame($person, $atClinic->json('core_contact_uuid'), 'Same email must resolve to the same person (ADR-003A tier 1).');

        $this->assertSame($hospitalSite, $this->orgPersonRelationship($hospitalOrg, $person)['site_address_id']);
        $this->assertSame($clinicSite, $this->orgPersonRelationship($clinicOrg, $person)['site_address_id']);
    }

    public function test_site_of_another_company_is_rejected(): void
    {
        [$customerId] = $this->createCompanyCustomer('Acme Hospital');
        [, $otherOrg] = $this->createCompanyCustomer('Bayu Clinic');
        $otherSite = $this->addAddress($otherOrg, 'site', 'Bayu Branch');

        $this->postJson("/api/v1/customers/{$customerId}/contacts", [
            'name' => 'Dr Tan',
            'email' => 'tan@example.test',
            'site_address_id' => $otherSite,
        ])->assertStatus(422)->assertJsonValidationErrors('site_address_id');

        $this->assertSame(
            [],
            $this->getJson("/api/v1/customers/{$customerId}")->assertOk()->json('customer_contacts'),
            'A rejected request must not leave a half-created contact behind.'
        );
    }

    public function test_non_site_address_is_rejected(): void
    {
        [$customerId, $orgUuid] = $this->createCompanyCustomer('Acme Hospital');
        $billing = $this->addAddress($orgUuid, 'billing', 'Head Office');

        $this->postJson("/api/v1/customers/{$customerId}/contacts", [
            'name' => 'Dr Tan',
            'email' => 'tan@example.test',
            'site_address_id' => $billing,
        ])->assertStatus(422)->assertJsonValidationErrors('site_address_id');
    }

    public function test_relationship_site_can_be_set_and_cleared_through_the_contact_master_api(): void
    {
        [$customerId, $orgUuid] = $this->createCompanyCustomer('Acme Hospital');
        $siteId = $this->addAddress($orgUuid, 'site', 'Main Clinic, Level 3');
        $contact = $this->postJson("/api/v1/customers/{$customerId}/contacts", [
            'name' => 'Dr Tan',
            'email' => 'tan@example.test',
        ])->assertCreated();
        $relationshipId = $this->orgPersonRelationship($orgUuid, $contact->json('core_contact_uuid'))['id'];

        $this->putJson("/api/v1/contacts/relationships/{$relationshipId}", [
            'functional_category_code' => null,
            'department_name' => null,
            'site_address_id' => $siteId,
        ])->assertOk()->assertJsonPath('data.site_address_id', $siteId);

        $this->putJson("/api/v1/contacts/relationships/{$relationshipId}", [
            'functional_category_code' => null,
            'department_name' => null,
            'site_address_id' => null,
        ])->assertOk()->assertJsonPath('data.site_address_id', null);
    }

    public function test_editing_a_contact_without_site_keeps_its_site(): void
    {
        [$customerId, $orgUuid] = $this->createCompanyCustomer('Acme Hospital');
        $siteId = $this->addAddress($orgUuid, 'site', 'Main Clinic, Level 3');
        $contact = $this->postJson("/api/v1/customers/{$customerId}/contacts", [
            'name' => 'Dr Tan',
            'email' => 'tan@example.test',
            'site_address_id' => $siteId,
        ])->assertCreated();

        $this->putJson("/api/v1/customers/{$customerId}/contacts/{$contact->json('id')}", [
            'name' => 'Dr Tan Wei',
        ])->assertOk();

        $relationship = $this->orgPersonRelationship($orgUuid, $contact->json('core_contact_uuid'));
        $this->assertSame($siteId, $relationship['site_address_id'], 'An edit that does not send site_address_id must not clear the site.');
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function createCompanyCustomer(string $name): array
    {
        $created = $this->postJson('/api/v1/customers', [
            'name' => $name,
            'type' => 'company',
        ])->assertCreated();

        $orgUuid = $created->json('core_contact_uuid');
        $this->assertNotNull($orgUuid, 'A company customer must be linked to a Contact Master organisation.');

        return [(int) $created->json('id'), $orgUuid];
    }

    private function addAddress(string $contactId, string $type, string $label): string
    {
        return $this->postJson("/api/v1/contacts/{$contactId}/addresses", [
            'address_type' => $type,
            'label' => $label,
            'line_1' => '1 Jalan Test',
            'city' => 'Kuala Lumpur',
            'country' => 'MY',
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function orgPersonRelationship(string $orgUuid, string $personUuid): array
    {
        $relationships = $this->getJson("/api/v1/contacts/{$orgUuid}/relationships")
            ->assertOk()
            ->json('data');

        $match = collect($relationships)->first(
            fn (array $r) => $r['relationship_type'] === 'org_person' && $r['related_contact_id'] === $personUuid
        );
        $this->assertNotNull($match, 'The company must have an org_person relationship with the person.');

        return $match;
    }
}
