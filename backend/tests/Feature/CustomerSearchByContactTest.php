<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Customer picker search must find a customer through its CONTACT PEOPLE.
 *
 * Regression: the invoice customer field searched only the customer's own
 * name/email/phone/company/code — a receptionist typing the contact person
 * they know ("Tan Toh Lick") got nothing, while the clinic/company names were
 * all that surfaced, so the wrong customer (and its contacts) could be picked.
 */
class CustomerSearchByContactTest extends TenantTestCase
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
            'name' => 'Customer Contact Search Tenant',
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

    public function test_customer_search_matches_a_contact_person_name(): void
    {
        $customer = Customer::factory()->create([
            'name' => "Astra Women's Specialists",
            'code' => 'VC-'.Str::upper(Str::random(5)),
            'type' => 'company',
        ]);

        CustomerContact::create([
            'customer_id' => $customer->id,
            'name' => 'Tan Toh Lick',
            'contact_category' => 'doctor',
            'contact_type' => 'doctor',
            'email' => 'tan.toh.lick@example.test',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/customers?search='.urlencode('Tan Toh'))
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $customer->id);

        $this->assertNotNull($row, 'Searching a contact person must find the customer.');
        $this->assertContains(
            'Tan Toh Lick',
            $row['matched_contacts'] ?? [],
            'The matched contact must be returned so the picker can show why it matched.',
        );
    }

    public function test_customer_search_still_matches_company_names_and_ignores_unknown_contacts(): void
    {
        $customer = Customer::factory()->create([
            'name' => "Astra Women's Specialists",
            'company' => 'Astra Group Pte Ltd',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/customers?search='.urlencode('Astra Group'))
            ->assertOk()
            ->assertJsonFragment(['id' => $customer->id]);

        // Unknown names return nothing — no accidental full-list results.
        $empty = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/customers?search='.urlencode('Nobody Contactperson'))
            ->assertOk();

        $this->assertSame([], $empty->json('data'));
    }
}