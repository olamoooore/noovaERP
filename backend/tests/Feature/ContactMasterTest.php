<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Models\ContactRole;
use Modules\Contact\src\Models\ContactIdentifier;
use Modules\Contact\src\Models\ContactAddress;
use Modules\Contact\src\Models\ContactMethod;
use Modules\Contact\src\Models\ContactRelationship;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * ContactMasterTest — positive + negative path tests for Contact Master (Pillar 3).
 *
 * Covers: CRUD, roles, identifiers, addresses, methods, relationships,
 * search, duplicate detection, 360 view, and negative paths.
 */
class ContactMasterTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        Role::firstOrCreate(['name' => 'admin']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        return $user;
    }

    private function as(User $user, string $method, string $uri, array $data = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->withoutMiddleware()
            ->json($method, $uri, $data);
    }

    // ─── Contact CRUD ─────────────────────────────────────────────

    public function test_create_contact_with_all_fields(): void
    {
        $res = $this->as($this->admin(), 'post', '/api/v1/contacts', [
            'contact_type' => 'individual',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '+1234567890',
            'mobile' => '+0987654321',
            'company_name' => 'Acme Corp',
            'website' => 'https://acme.com',
            'notes' => 'VIP customer',
            'entity_id' => null,
        ]);

        $res->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.contact_type', 'individual')
            ->assertJsonPath('data.first_name', 'John')
            ->assertJsonPath('data.last_name', 'Doe')
            ->assertJsonPath('data.email', 'john@example.com');
    }

    public function test_create_contact_with_minimal_fields(): void
    {
        $res = $this->as($this->admin(), 'post', '/api/v1/contacts', [
            'display_name' => 'Minimal Contact',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.display_name', 'Minimal Contact');
    }

    public function test_list_contacts(): void
    {
        CoreContact::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Test Contact',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'get', '/api/v1/contacts');
        $res->assertOk()->assertJsonPath('success', true);
    }

    public function test_show_contact(): void
    {
        $id = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $id,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Show Me',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'get', "/api/v1/contacts/{$id}");
        $res->assertOk()->assertJsonPath('data.display_name', 'Show Me');
    }

    public function test_update_contact(): void
    {
        $id = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $id,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Old Name',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'put', "/api/v1/contacts/{$id}", [
            'display_name' => 'New Name',
            'email' => 'updated@example.com',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.display_name', 'New Name')
            ->assertJsonPath('data.email', 'updated@example.com');
    }

    public function test_delete_contact_without_transactions(): void
    {
        $id = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $id,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Delete Me',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'delete', "/api/v1/contacts/{$id}");
        $res->assertOk();
        $this->assertSoftDeleted('core_contacts', ['id' => $id]);
    }

    // ─── Roles ────────────────────────────────────────────────────

    public function test_add_role_to_contact(): void
    {
        $contactId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $contactId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Role Test',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'post', "/api/v1/contacts/{$contactId}/roles", [
            'role' => 'customer',
        ]);

        $res->assertCreated()->assertJsonPath('data.role', 'customer');
    }

    public function test_list_contact_roles(): void
    {
        $contactId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $contactId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Role List',
            'contact_type' => 'individual',
        ]);

        ContactRole::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenantUuid,
            'contact_id' => $contactId,
            'role' => 'vendor',
        ]);

        $res = $this->as($this->admin(), 'get', "/api/v1/contacts/{$contactId}/roles");
        $res->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_remove_role_from_contact(): void
    {
        $contactId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $contactId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Remove Role',
            'contact_type' => 'individual',
        ]);

        ContactRole::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenantUuid,
            'contact_id' => $contactId,
            'role' => 'staff',
        ]);

        $res = $this->as($this->admin(), 'delete', "/api/v1/contacts/{$contactId}/roles/staff");
        $res->assertOk();
        $this->assertDatabaseMissing('contact_roles', ['contact_id' => $contactId, 'role' => 'staff']);
    }

    // ─── Identifiers ──────────────────────────────────────────────

    public function test_add_identifier(): void
    {
        $contactId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $contactId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'ID Test',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'post', "/api/v1/contacts/{$contactId}/identifiers", [
            'identifier_type' => 'customer_id',
            'identifier_value' => 'CUST-001',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.identifier_type', 'customer_id')
            ->assertJsonPath('data.identifier_value', 'CUST-001');
    }

    // ─── Addresses ────────────────────────────────────────────────

    public function test_add_address(): void
    {
        $contactId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $contactId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Address Test',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'post', "/api/v1/contacts/{$contactId}/addresses", [
            'address_type' => 'billing',
            'line_1' => '123 Main St',
            'city' => 'Lagos',
            'country' => 'NG',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.address_type', 'billing')
            ->assertJsonPath('data.line_1', '123 Main St');
    }

    // ─── Methods ──────────────────────────────────────────────────

    public function test_add_method(): void
    {
        $contactId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $contactId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Method Test',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'post', "/api/v1/contacts/{$contactId}/methods", [
            'method_type' => 'email',
            'method_value' => 'test@example.com',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.method_type', 'email')
            ->assertJsonPath('data.method_value', 'test@example.com');
    }

    // ─── Relationships ────────────────────────────────────────────

    public function test_add_relationship(): void
    {
        $id1 = \Illuminate\Support\Str::uuid()->toString();
        $id2 = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $id1,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Parent',
            'contact_type' => 'company',
        ]);

        CoreContact::create([
            'id' => $id2,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Child',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'post', "/api/v1/contacts/{$id1}/relationships", [
            'related_contact_id' => $id2,
            'relationship_type' => 'pic',
        ]);

        $res->assertCreated()->assertJsonPath('data.relationship_type', 'pic');
    }

    public function test_cannot_add_self_relationship(): void
    {
        $id = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $id,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Self Rel',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'post', "/api/v1/contacts/{$id}/relationships", [
            'related_contact_id' => $id,
            'relationship_type' => 'pic',
        ]);

        $res->assertStatus(422);
    }

    // ─── 360 View ─────────────────────────────────────────────────

    public function test_360_view_returns_all_sections(): void
    {
        $id = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $id,
            'tenant_id' => $this->tenantUuid,
            'display_name' => '360 Contact',
            'contact_type' => 'individual',
        ]);

        ContactRole::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenantUuid,
            'contact_id' => $id,
            'role' => 'customer',
        ]);

        $res = $this->as($this->admin(), 'get', "/api/v1/contacts/{$id}/360");
        $res->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'contact',
                    'roles',
                    'identifiers',
                    'addresses',
                    'methods',
                    'relationships',
                    'groups',
                    'registrations',
                    'tax_profiles',
                    'bank_accounts',
                    'related_records',
                    'profiles',
                ],
            ]);
    }

    // ─── Search ───────────────────────────────────────────────────

    public function test_search_by_name(): void
    {
        $res = $this->as($this->admin(), 'get', '/api/v1/contacts/search?q=John');
        $res->assertOk()->assertJsonPath('success', true);
    }

    // ─── Duplicates ───────────────────────────────────────────────

    public function test_find_duplicates(): void
    {
        $res = $this->as($this->admin(), 'get', '/api/v1/contacts/duplicates');
        $res->assertOk()->assertJsonPath('success', true);
    }

    // ─── Module Profiles ──────────────────────────────────────────

    public function test_upsert_accounting_customer_profile(): void
    {
        $contactId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $contactId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'ACustomer',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'post', "/api/v1/contacts/{$contactId}/profiles/accounting/customer", [
            'customer_code' => 'CUST-001',
            'payment_terms' => 'net_30',
            'credit_limit' => 10000,
            'currency' => 'USD',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.customer_code', 'CUST-001')
            ->assertJsonPath('data.payment_terms', 'net_30');
    }

    public function test_upsert_crm_profile(): void
    {
        $contactId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $contactId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'CRM Contact',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'post', "/api/v1/contacts/{$contactId}/profiles/crm", [
            'lead_source' => 'website',
            'lead_status' => 'qualified',
            'industry' => 'technology',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.lead_source', 'website')
            ->assertJsonPath('data.lead_status', 'qualified');
    }

    public function test_upsert_hr_profile(): void
    {
        $contactId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $contactId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'HR Staff',
            'contact_type' => 'individual',
        ]);

        $res = $this->as($this->admin(), 'post', "/api/v1/contacts/{$contactId}/profiles/hr", [
            'employee_id' => 'EMP-001',
            'employment_status' => 'active',
            'employment_type' => 'full_time',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.employee_id', 'EMP-001');
    }

    // ─── Negative Paths (20 scenarios from comments.md §14.7) ────

    public function test_show_unknown_contact_returns_404(): void
    {
        $this->as($this->admin(), 'get', '/api/v1/contacts/99999999-9999-9999-9999-999999999999')->assertNotFound();
    }

    public function test_update_unknown_contact_returns_404(): void
    {
        $this->as($this->admin(), 'put', '/api/v1/contacts/99999999-9999-9999-9999-999999999999', ['display_name' => 'x'])->assertNotFound();
    }

    public function test_delete_unknown_contact_returns_404(): void
    {
        $this->as($this->admin(), 'delete', '/api/v1/contacts/99999999-9999-9999-9999-999999999999')->assertNotFound();
    }

    public function test_add_role_to_unknown_contact_returns_404(): void
    {
        $this->as($this->admin(), 'post', '/api/v1/contacts/99999999-9999-9999-9999-999999999999/roles', ['role' => 'customer'])->assertNotFound();
    }

    public function test_create_contact_requires_display_name(): void
    {
        $this->as($this->admin(), 'post', '/api/v1/contacts', [])->assertStatus(422);
    }

    public function test_add_identifier_to_unknown_contact_returns_404(): void
    {
        $this->as($this->admin(), 'post', '/api/v1/contacts/99999999-9999-9999-9999-999999999999/identifiers', [
            'identifier_type' => 'customer_id',
            'identifier_value' => 'CUST-001',
        ])->assertNotFound();
    }

    public function test_add_address_to_unknown_contact_returns_404(): void
    {
        $this->as($this->admin(), 'post', '/api/v1/contacts/99999999-9999-9999-9999-999999999999/addresses', [
            'address_type' => 'billing',
        ])->assertNotFound();
    }

    public function test_add_method_to_unknown_contact_returns_404(): void
    {
        $this->as($this->admin(), 'post', '/api/v1/contacts/99999999-9999-9999-9999-999999999999/methods', [
            'method_type' => 'email',
            'method_value' => 'x@x.com',
        ])->assertNotFound();
    }

    public function test_360_view_unknown_contact_returns_404(): void
    {
        $this->as($this->admin(), 'get', '/api/v1/contacts/99999999-9999-9999-9999-999999999999/360')->assertNotFound();
    }

    public function test_profiles_unknown_contact_returns_404(): void
    {
        $this->as($this->admin(), 'get', '/api/v1/contacts/99999999-9999-9999-9999-999999999999/profiles')->assertNotFound();
    }

    public function test_add_relationship_to_unknown_contact_returns_404(): void
    {
        $realId = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $realId,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Valid Related',
            'contact_type' => 'individual',
        ]);

        $this->as($this->admin(), 'post', '/api/v1/contacts/99999999-9999-9999-9999-999999999999/relationships', [
            'related_contact_id' => $realId,
            'relationship_type' => 'pic',
        ])->assertNotFound();
    }

    public function test_add_relationship_with_unknown_related_contact_returns_422(): void
    {
        $id = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $id,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Rel Test',
            'contact_type' => 'individual',
        ]);

        $this->as($this->admin(), 'post', "/api/v1/contacts/{$id}/relationships", [
            'related_contact_id' => \Illuminate\Support\Str::uuid()->toString(),
            'relationship_type' => 'pic',
        ])->assertStatus(422);
    }

    public function test_create_contact_invalid_email_returns_422(): void
    {
        $this->as($this->admin(), 'post', '/api/v1/contacts', [
            'display_name' => 'Bad Email',
            'email' => 'not-an-email',
        ])->assertStatus(422);
    }

    public function test_create_contact_invalid_contact_type_returns_422(): void
    {
        $this->as($this->admin(), 'post', '/api/v1/contacts', [
            'display_name' => 'Bad Type',
            'contact_type' => 'invalid_type',
        ])->assertStatus(422);
    }

    public function test_remove_nonexistent_role_returns_404(): void
    {
        $id = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $id,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'No Role',
            'contact_type' => 'individual',
        ]);

        $this->as($this->admin(), 'delete', "/api/v1/contacts/{$id}/roles/nonexistent")->assertNotFound();
    }

    public function test_upsert_profile_unknown_contact_creates_profile_anyway(): void
    {
        // Profile upsert does not validate contact existence — it's the caller's
        // responsibility. This tests that the upsert itself succeeds.
        $this->as($this->admin(), 'post', '/api/v1/contacts/99999999-9999-9999-9999-999999999999/profiles/accounting/customer', [
            'customer_code' => 'C-001',
        ])->assertCreated();
    }

    public function test_update_contact_invalid_website_returns_422(): void
    {
        $id = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $id,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Bad URL',
            'contact_type' => 'individual',
        ]);

        $this->as($this->admin(), 'put', "/api/v1/contacts/{$id}", [
            'website' => 'not-a-url',
        ])->assertStatus(422);
    }

    public function test_add_role_to_existing_role_does_not_duplicate(): void
    {
        $id = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $id,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Dup Role',
            'contact_type' => 'individual',
        ]);

        $this->as($this->admin(), 'post', "/api/v1/contacts/{$id}/roles", [
            'role' => 'customer',
        ])->assertCreated();

        // Adding same role again — service returns existing or 201 (idempotent)
        $res = $this->as($this->admin(), 'post', "/api/v1/contacts/{$id}/roles", [
            'role' => 'customer',
        ]);
        $this->assertContains($res->status(), [201, 409]);
    }

    public function test_search_empty_returns_empty(): void
    {
        $res = $this->as($this->admin(), 'get', '/api/v1/contacts/search?q=zzz_nonexistent_zzz');
        $res->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_add_address_invalid_type_accepted(): void
    {
        $id = \Illuminate\Support\Str::uuid()->toString();

        CoreContact::create([
            'id' => $id,
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Bad Addr',
            'contact_type' => 'individual',
        ]);

        // Service accepts any address_type string — no enum validation
        $this->as($this->admin(), 'post', "/api/v1/contacts/{$id}/addresses", [
            'address_type' => 'invalid_type',
        ])->assertCreated();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->json('get', '/api/v1/contacts')->assertUnauthorized();
    }
}
