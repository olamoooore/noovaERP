<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * D6 (Oct-2026 invoice review): the invoice screen offers this customer's
 * frequently bought products as one-click chips. Only real (non-draft,
 * non-superseded) purchase invoices count, ranked by how often the product
 * was bought.
 */
class CustomerFrequentProductsTest extends TenantTestCase
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
            'name' => 'Frequent Products Tenant',
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

    private function invoiceFor(Customer $customer, array $attributes): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'currency_code' => null,
            'exchange_rate' => 1,
        ], $attributes));
    }

    private function addLine(Invoice $invoice, Product $product, float $quantity): void
    {
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => $quantity,
            'unit_price' => 10,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
            'line_total' => $quantity * 10,
            'tax_amount' => 0,
        ]);
    }

    public function test_frequent_products_rank_real_sales_and_exclude_invalid_documents(): void
    {
        $customer = Customer::factory()->create(['legal_entity_id' => null]);
        $otherCustomer = Customer::factory()->create(['legal_entity_id' => null]);

        $widget = Product::factory()->create(['name' => 'Widget A', 'sku' => 'SKU-A']);
        $gadget = Product::factory()->create(['name' => 'Gadget B', 'sku' => 'SKU-B']);
        $ghost = Product::factory()->create(['name' => 'Ghost C', 'sku' => 'SKU-C']);
        $foreign = Product::factory()->create(['name' => 'Foreign D', 'sku' => 'SKU-D']);

        // Real sales: widget bought twice (2 + 3 units), gadget once.
        $posted = $this->invoiceFor($customer, ['status' => 'posted', 'invoice_date' => now()->subDays(10)->toDateString()]);
        $this->addLine($posted, $widget, 2);
        $this->addLine($posted, $gadget, 1);

        $sent = $this->invoiceFor($customer, ['status' => 'sent', 'invoice_date' => now()->subDays(2)->toDateString()]);
        $this->addLine($sent, $widget, 3);

        // Excluded: draft, superseded, and another customer's invoice.
        $draft = $this->invoiceFor($customer, ['status' => 'draft']);
        $this->addLine($draft, $gadget, 50);

        $superseded = $this->invoiceFor($customer, ['status' => 'sent', 'lifecycle_status' => 'superseded']);
        $this->addLine($superseded, $widget, 99);

        $foreignInvoice = $this->invoiceFor($otherCustomer, ['status' => 'posted']);
        $this->addLine($foreignInvoice, $foreign, 5);
        $this->addLine($foreignInvoice, $ghost, 5);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/customers/{$customer->id}/frequent-products")
            ->assertOk();

        $data = collect($response->json('data'));

        $this->assertSame($widget->id, $data->first()['id'], 'The most frequently bought product must rank first.');
        $this->assertSame(2, $data->firstWhere('id', $widget->id)['times']);
        $this->assertEquals(5.0, $data->firstWhere('id', $widget->id)['total_quantity']);

        $this->assertSame(1, $data->firstWhere('id', $gadget->id)['times'], 'Draft lines must not count towards purchases.');
        $this->assertNull($data->firstWhere('id', $foreign->id), "Another customer's products must never appear.");
        $this->assertNull($data->firstWhere('id', $ghost->id), "Another customer's products must never appear.");
    }
}
