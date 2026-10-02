<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use App\Services\TagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Tests\TenantTestCase;

/**
 * The quotation LIST search must be as wide as the invoice list and the
 * customer picker: typing a customer, a contact person, an address, a
 * line/product value, a header contact or a tag must find the quotation.
 *
 * Regression: the list only matched quotation_number + customer name, so a
 * user typing a known contact/address/line/tag got "No quotations found."
 */
class QuotationWideSearchTest extends TenantTestCase
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
            'name' => 'Quotation Wide Search Tenant',
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

    private function quotationFor(Customer $customer): Quotation
    {
        return Quotation::create([
            'customer_id' => $customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * @return int[]
     */
    private function searchQuotationIds(string $term): array
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/quotations?search='.urlencode($term).'&per_page=50')
            ->assertOk();

        return collect($response->json('data'))->pluck('id')->map('intval')->all();
    }

    public function test_quotation_search_matches_customer_contact_person(): void
    {
        $customer = Customer::factory()->create([
            'name' => "Astra Women's Specialists",
            'type' => 'company',
        ]);
        CustomerContact::create([
            'customer_id' => $customer->id,
            'name' => 'Tan Toh Lick',
            'contact_category' => 'doctor',
            'contact_type' => 'doctor',
            'email' => 'tan.toh.lick@example.test',
        ]);
        $quotation = $this->quotationFor($customer);

        $this->assertContains($quotation->id, $this->searchQuotationIds('Tan Toh'));
    }

    public function test_quotation_search_matches_customer_address_and_tags(): void
    {
        $customer = Customer::factory()->create(['name' => 'Acme Pte Ltd']);
        CustomerAddress::create([
            'customer_id' => $customer->id,
            'type' => 'billing',
            'label' => 'HQ',
            'street' => '12 Jalan Kilang',
            'city' => 'Petaling Jaya',
            'state' => 'Selangor',
            'zip' => '47301',
            'country' => 'Malaysia',
        ]);
        $quotation = $this->quotationFor($customer);

        $this->assertContains($quotation->id, $this->searchQuotationIds('Petaling Jaya'));

        app(TagService::class)->attach($quotation, ['VIP'], $this->admin->id);
        $this->assertContains($quotation->id, $this->searchQuotationIds('vip'));
    }

    public function test_quotation_search_matches_header_contact_and_line_contents(): void
    {
        $customer = Customer::factory()->create(['name' => 'Acme Pte Ltd']);
        $quotation = $this->quotationFor($customer);

        $contact = CoreContact::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Dr. Gregory House',
            'first_name' => 'Gregory',
            'contact_type' => 'individual',
        ]);
        $quotation->update(['billing_contact_id' => $contact->id]);

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'line_type' => 'product',
            'sequence' => 0,
            'description' => 'Widget Assembly',
            'model_number' => 'MOD-9000',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'line_total' => 10,
        ]);

        $this->assertContains($quotation->id, $this->searchQuotationIds('Gregory House'));
        $this->assertContains($quotation->id, $this->searchQuotationIds('MOD-9000'));
    }

    public function test_quotation_search_still_matches_number_and_ignores_unknown_terms(): void
    {
        $customer = Customer::factory()->create(['name' => 'Acme Pte Ltd']);
        $quotation = $this->quotationFor($customer);

        $this->assertContains($quotation->id, $this->searchQuotationIds($quotation->quotation_number));
        $this->assertSame([], $this->searchQuotationIds('Nobody Contactperson Zzz'));
    }
}
