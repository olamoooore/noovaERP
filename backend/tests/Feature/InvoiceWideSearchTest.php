<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\TagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Tests\TenantTestCase;

/**
 * The invoice LIST search must be as wide as the customer picker used on the
 * create-invoice page: typing a customer, a contact person, an address, a
 * line/product description or a tag must find the invoice.
 *
 * Regression: the list only matched invoice_number + customer name/code, so a
 * user typing a contact person ("Tan Toh Lick") or an address they know got
 * "No invoices found." even though the customer picker found the customer.
 */
class InvoiceWideSearchTest extends TenantTestCase
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
            'name' => 'Invoice Wide Search Tenant',
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

    private function invoiceFor(Customer $customer): Invoice
    {
        return Invoice::factory()->create([
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
        ]);
    }

    /**
     * @return int[]
     */
    private function searchInvoiceIds(string $term): array
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/invoices?search='.urlencode($term).'&per_page=50')
            ->assertOk();

        return collect($response->json('data'))->pluck('id')->map('intval')->all();
    }

    public function test_invoice_search_matches_the_customer_contact_person(): void
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
        $invoice = $this->invoiceFor($customer);

        $this->assertContains(
            $invoice->id,
            $this->searchInvoiceIds('Tan Toh'),
            'Searching a customer contact person must find the customer\'s invoices.',
        );
    }

    public function test_invoice_search_matches_customer_address_and_tags(): void
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
        $invoice = $this->invoiceFor($customer);

        $this->assertContains($invoice->id, $this->searchInvoiceIds('Petaling Jaya'));

        app(TagService::class)->attach($invoice, ['VIP'], $this->admin->id);
        $this->assertContains(
            $invoice->id,
            $this->searchInvoiceIds('vip'),
            'A tag name must find every invoice carrying that tag.',
        );
    }

    public function test_invoice_search_matches_header_contact_and_line_contents(): void
    {
        $customer = Customer::factory()->create(['name' => 'Acme Pte Ltd']);
        $invoice = $this->invoiceFor($customer);

        $doctor = CoreContact::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantUuid,
            'display_name' => 'Dr. Gregory House',
            'first_name' => 'Gregory',
            'contact_type' => 'individual',
        ]);
        $invoice->update(['doctor_contact_id' => $doctor->id]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Widget Assembly',
            'model_number' => 'MOD-9000',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        $this->assertContains(
            $invoice->id,
            $this->searchInvoiceIds('Gregory House'),
            'Searching the invoice header contact person must find the invoice.',
        );
        $this->assertContains(
            $invoice->id,
            $this->searchInvoiceIds('MOD-9000'),
            'Searching a line model/description must find the invoice.',
        );
    }

    public function test_invoice_search_still_matches_document_number_and_ignores_unknown_terms(): void
    {
        $customer = Customer::factory()->create(['name' => 'Acme Pte Ltd']);
        $invoice = $this->invoiceFor($customer);

        $this->assertContains($invoice->id, $this->searchInvoiceIds($invoice->invoice_number));
        $this->assertSame(
            [],
            $this->searchInvoiceIds('Nobody Contactperson'),
            'An unknown term must never fall back to the full list.',
        );
    }
}
