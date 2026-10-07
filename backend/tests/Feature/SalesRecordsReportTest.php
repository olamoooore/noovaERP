<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * #1020: the client's Sales Records sheet reproduced through the real route.
 *
 * Sample row (invoice 2026/05/020 in the client's spreadsheet): Front
 * Morcellator paid qty 5 + FOC 1 -> Qty 6, S$ 5000 net (ERP line total 5450
 * incl. 9% GST), Bag qty 2 -> S$ 700, Total S$ 5700, monthly total row.
 */
class SalesRecordsReportTest extends TenantTestCase
{
    protected User $user;

    protected int $brandId;

    protected int $categoryId;

    protected int $supplierId;

    protected Product $morcellator;

    protected Product $bag;

    protected Customer $customer;

    protected int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path' => [database_path('migrations/tenant')],
            '--realpath' => true,
        ]);

        $this->withoutMiddleware([
            \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class,
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\ResolveEntityContext::class,
            \App\Http\Middleware\EnsureEntityAccess::class,
        ]);

        $this->user = $this->setUpTenantAdminUser('Sales Records Tenant');

        $this->brandId = DB::table('brands')->insertGetId([
            'name' => 'Front Medical',
            'slug' => 'front-medical',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->categoryId = DB::table('product_categories')->insertGetId([
            'name' => 'Morcellators',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'Front Medical Supplies',
            'code' => 'FMS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['name' => 'Dr Beh Suan Tiong']);

        $this->morcellator = Product::factory()->create([
            'name' => 'Front Morcellator',
            'sku' => 'F240115150',
            'short_description' => 'Front blade morcellator',
            'brand_id' => $this->brandId,
            'category_id' => $this->categoryId,
            'price' => 1000,
            'cost' => 500,
        ]);

        $this->bag = Product::factory()->create([
            'name' => 'Disposable Retrieval Bag',
            'sku' => 'F02306',
            'short_description' => 'Retrieval Bag',
            'brand_id' => $this->brandId,
            'category_id' => $this->categoryId,
            'price' => 350,
            'cost' => 150,
        ]);

        DB::table('product_suppliers')->insert([
            'product_id' => $this->morcellator->id,
            'supplier_id' => $this->supplierId,
            'cost' => 500,
            'is_preferred' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->invoiceId = DB::table('invoices')->insertGetId([
            'invoice_number' => '2026/05/020',
            'invoice_date' => '2026-05-06',
            'due_date' => '2026-06-05',
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
            'status' => 'posted',
            'subtotal' => 5700,
            'total' => 6213,
            'balance' => 6213,
            'paid_amount' => 0,
            'settlement_status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_lines')->insert([
            [
                'invoice_id' => $this->invoiceId,
                'product_id' => $this->morcellator->id,
                'description' => 'Front Morcellator',
                'quantity' => 5,
                'unit_price' => 1000,
                'tax_rate' => 9,
                'tax_amount' => 450,
                'tax_inclusive' => true,
                'line_total' => 5450,
                'lot_number' => 'A2502008',
                'line_type' => 'product',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_id' => $this->invoiceId,
                'product_id' => $this->morcellator->id,
                'description' => 'Front Morcellator (FOC)',
                'quantity' => 1,
                'unit_price' => 0,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'tax_inclusive' => true,
                'line_total' => 0,
                'lot_number' => '2601022',
                'line_type' => 'product',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_id' => $this->invoiceId,
                'product_id' => $this->bag->id,
                'description' => 'Disposable Retrieval Bag',
                'quantity' => 2,
                'unit_price' => 350,
                'tax_rate' => 9,
                'tax_amount' => 63,
                'tax_inclusive' => true,
                'line_total' => 763,
                'lot_number' => '2604042',
                'line_type' => 'product',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function fetch(array $params): array
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/reports/sales-records?'.http_build_query($params));
        $response->assertOk();

        return $response->json('data');
    }

    /** @test */
    public function it_reproduces_the_sheet_row_with_qty_including_foc_and_net_values()
    {
        $data = $this->fetch([
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'brand_id' => $this->brandId,
            'group_by' => 'item_code',
        ]);

        // Columns are the brand's active catalogue, sorted by SKU.
        $this->assertSame(['F02306', 'F240115150'], array_column($data['columns'], 'code'));
        $morcellatorColumn = collect($data['columns'])->firstWhere('code', 'F240115150');
        $this->assertSame('Front Morcellator', $morcellatorColumn['label']);
        $this->assertSame('Front blade morcellator', $morcellatorColumn['short_description']);

        $this->assertCount(1, $data['rows']);
        $row = $data['rows'][0];
        $this->assertSame('Dr Beh Suan Tiong', $row['customer_name']);
        $this->assertSame('2026-05-06', $row['date']);
        $this->assertSame('2026/05/020', $row['document_number']);
        $this->assertSame('2026-06-05', $row['due_date']);
        $this->assertStringContainsString('A2502008', $row['lot_number']);
        $this->assertStringContainsString('2601022', $row['lot_number']);

        // Qty 6 = paid 5 + FOC 1; S$ 5000 = net of the 9% tax (5450 - 450).
        $this->assertSame(6.0, (float) $row['values']['F240115150']['quantity']);
        $this->assertSame(5000.0, (float) $row['values']['F240115150']['value']);
        $this->assertSame(2.0, (float) $row['values']['F02306']['quantity']);
        $this->assertSame(700.0, (float) $row['values']['F02306']['value']);
        $this->assertSame(5700.0, (float) $row['total']);

        // Monthly total row (the sheet's "May Total").
        $this->assertCount(1, $data['month_totals']);
        $this->assertSame('May 2026', $data['month_totals'][0]['label']);
        $this->assertSame(6.0, (float) $data['month_totals'][0]['values']['F240115150']['quantity']);
        $this->assertSame(5700.0, (float) $data['month_totals'][0]['total']);

        $this->assertSame(8.0, (float) $data['summary']['total_quantity']);
        $this->assertSame(5700.0, (float) $data['summary']['total_value']);
        $this->assertSame(1, $data['summary']['invoice_count']);
    }

    /** @test */
    public function it_groups_by_brand_supplier_customer_and_category()
    {
        $brand = $this->fetch(['group_by' => 'brand', 'brand_id' => $this->brandId]);
        $this->assertSame(['Front Medical'], array_column($brand['columns'], 'key'));
        $this->assertSame(5700.0, (float) $brand['rows'][0]['values']['Front Medical']['value']);

        $supplier = $this->fetch(['group_by' => 'supplier', 'brand_id' => $this->brandId]);
        $this->assertContains('Front Medical Supplies', array_column($supplier['columns'], 'key'));
        $this->assertSame(5000.0, (float) $supplier['rows'][0]['values']['Front Medical Supplies']['value']);

        $customer = $this->fetch(['group_by' => 'customer', 'brand_id' => $this->brandId]);
        $this->assertSame(['Dr Beh Suan Tiong'], array_column($customer['columns'], 'key'));

        $category = $this->fetch(['group_by' => 'category', 'brand_id' => $this->brandId]);
        $this->assertSame(['Morcellators'], array_column($category['columns'], 'key'));
    }

    /** @test */
    public function it_filters_by_item_code_supplier_and_customer_individually_and_combined()
    {
        // Item code filter narrows both the columns and the rows.
        $byItem = $this->fetch(['item_code' => 'F02306', 'group_by' => 'item_code']);
        $this->assertSame(['F02306'], array_column($byItem['columns'], 'code'));
        $this->assertSame(700.0, (float) $byItem['rows'][0]['total']);

        // Supplier filter keeps only products linked to that supplier.
        $bySupplier = $this->fetch(['supplier_id' => $this->supplierId, 'group_by' => 'item_code']);
        $this->assertSame(['F240115150'], array_column($bySupplier['columns'], 'code'));

        $byCustomer = $this->fetch(['customer_id' => $this->customer->id, 'group_by' => 'item_code']);
        $this->assertCount(1, $byCustomer['rows']);

        $otherCustomer = Customer::factory()->create(['name' => 'Someone Else']);
        $none = $this->fetch(['customer_id' => $otherCustomer->id, 'group_by' => 'item_code']);
        $this->assertSame([], $none['rows']);

        // Combined filters intersect.
        $combined = $this->fetch([
            'brand_id' => $this->brandId,
            'supplier_id' => $this->supplierId,
            'customer_id' => $this->customer->id,
            'group_by' => 'item_code',
        ]);
        $this->assertSame(['F240115150'], array_column($combined['columns'], 'code'));
        $this->assertSame(5000.0, (float) $combined['rows'][0]['total']);
    }

    /** @test */
    public function it_reports_payment_date_and_status_and_keeps_bonus_scheme_blank_without_usage_rows()
    {
        DB::table('payments')->insert([
            'payment_number' => 'PAY-0001',
            'customer_id' => $this->customer->id,
            'invoice_id' => $this->invoiceId,
            'payment_date' => '2026-06-30',
            'amount' => 6213,
            'method' => 'bank_transfer',
            'received_by' => $this->user->id,
            'payment_status' => 'applied',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoices')->where('id', $this->invoiceId)->update([
            'paid_amount' => 6213,
            'balance' => 0,
            'settlement_status' => 'paid',
        ]);

        $data = $this->fetch(['brand_id' => $this->brandId, 'group_by' => 'item_code']);

        $this->assertSame('30 Jun 2026, Completed', $data['rows'][0]['status']);
        // The sheet's Bonusing Scheme column comes from recorded scheme usage;
        // none exists for this invoice, so the cell stays blank, not invented.
        $this->assertNull($data['rows'][0]['bonus_scheme']);
    }

    /** @test */
    public function unauthenticated_requests_are_rejected()
    {
        $this->getJson('/api/v1/reports/sales-records')->assertStatus(401);
    }
}
