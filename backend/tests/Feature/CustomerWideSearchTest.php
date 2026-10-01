<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\User;
use App\Services\TagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Odoo-style wide customer search: a customer must be findable through the
 * person you know (name / job title), the building you visit (address label
 * and text), its tax or registration number, website, tags, and its parent
 * company hierarchy — not just its own name.
 */
class CustomerWideSearchTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Customer Wide Search Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'code' => 'VC-'.Str::upper(Str::random(5)),
            'type' => 'company',
        ], $attributes));
    }

    private function search(string $term): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/customers?search='.urlencode($term))
            ->assertOk()
            ->json('data');
    }

    public function test_search_matches_a_contact_job_title(): void
    {
        $customer = $this->customer(['name' => 'Aspire Medical Centre']);

        CustomerContact::create([
            'customer_id' => $customer->id,
            'name' => 'Fong Yang',
            'job_title' => 'Medical Director',
            'contact_category' => 'doctor',
            'contact_type' => 'doctor',
        ]);

        $row = collect($this->search('Medical Director'))->firstWhere('id', $customer->id);

        $this->assertNotNull($row, 'Searching a contact job title must find the customer.');
    }

    public function test_search_matches_a_building_address_label_and_reports_it(): void
    {
        $customer = $this->customer(['name' => 'Thomson Fertility']);

        CustomerAddress::create([
            'customer_id' => $customer->id,
            'type' => 'billing',
            'label' => 'Thomson Medical Building',
            'street' => '339 Thomson Road',
            'city' => 'Singapore',
            'is_default' => true,
        ]);

        $row = collect($this->search('Thomson Medical Building'))->firstWhere('id', $customer->id);

        $this->assertNotNull($row, 'Searching a building/address label must find the customer.');
        $this->assertNotEmpty($row['matched_addresses'] ?? [], 'The matched address must be reported back.');
    }

    public function test_search_matches_tax_number_website_and_registration_number(): void
    {
        $customer = $this->customer([
            'name' => 'Astra Holdings',
            'tax_number' => 'GST-998877',
            'company_registration_number' => 'ACRA-202612345Z',
            'website' => 'astra-holdings.example',
        ]);

        foreach (['GST-998877', 'ACRA-202612345Z', 'astra-holdings.example'] as $term) {
            $row = collect($this->search($term))->firstWhere('id', $customer->id);
            $this->assertNotNull($row, "Searching '{$term}' must find the customer.");
        }
    }

    public function test_search_matches_tag_names_and_reports_them(): void
    {
        $customer = $this->customer(['name' => 'Prime Specialist Clinic']);

        app(TagService::class)->attach($customer, ['VIP', 'Corporate']);

        $row = collect($this->search('VIP'))->firstWhere('id', $customer->id);

        $this->assertNotNull($row, 'Searching a tag name must find every tagged customer.');
        $this->assertContains('VIP', $row['matched_tags'] ?? [], 'The matched tag must be reported back.');
    }

    public function test_search_matches_child_account_through_parent_company(): void
    {
        $parent = $this->customer(['name' => 'Acme Group', 'company' => 'Acme Group Pte Ltd']);

        $branch = $this->customer([
            'name' => 'Acme @ Orchard',
            'parent_id' => $parent->id,
        ]);

        $row = collect($this->search('Acme Group Pte Ltd'))->firstWhere('id', $branch->id);

        $this->assertNotNull($row, 'Child accounts must match through their parent company (Odoo complete_name behaviour).');
    }
}