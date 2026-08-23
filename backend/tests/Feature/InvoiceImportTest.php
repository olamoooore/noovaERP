<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class InvoiceImportTest extends TenantTestCase
{
    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->tenantId = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'Invoice Import Test Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });
        $this->bindTenantContext($this->tenantId);

        $this->assertTenantSchemaReady([
            'chart_of_accounts',
            'invoices',
            'customers',
            'products',
            'sales_orders',
            'sales_order_lines',
        ]);
    }

    public function test_import_groups_rows_by_invoice_number_and_creates_invoices(): void
    {
        $user = User::create([
            'name' => 'Invoice Admin',
            'email' => 'admin-import@test.local',
            'password' => bcrypt('password'),
        ]);

        $customer = Customer::factory()->create([
            'code' => 'CUST-001',
            'name' => 'Acme Corp',
            'payment_terms' => 30,
        ]);

        $productA = Product::factory()->create([
            'sku' => 'SKU-A',
            'track_inventory' => false,
            'price' => 50,
            'cost' => 10,
        ]);
        $productB = Product::factory()->create([
            'sku' => 'SKU-B',
            'track_inventory' => false,
            'price' => 20,
            'cost' => 5,
        ]);

        // Row 1 + Row 2 share invoice_number IMP-001 -> one invoice, two lines.
        // Row 3 is a separate single-line invoice (IMP-002).
        $csv = implode("\n", [
            'invoice_number,status,customer_code,customer_name,invoice_date,due_date,customer_reference,notes,terms,discount_amount,product_sku,description,quantity,unit_price,tax_rate,line_discount_amount',
            'IMP-001,draft,CUST-001,Acme Corp,1/15/2026,2/15/2026,PO-1,First quote,Net 30,0,SKU-A,Widget A,10,50.00,0,0',
            'IMP-001,draft,CUST-001,Acme Corp,,,,,,,SKU-B,Widget B,5,20.00,0,0',
            'IMP-002,draft,CUST-001,Acme Corp,1/16/2026,2/16/2026,,,,,,Solo line item,2,15.00,0,0',
        ]);

        $file = UploadedFile::fake()->createWithContent('invoices.csv', $csv);

        $response = $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->post('/api/v1/invoices/import', ['file' => $file]);

        $response->assertStatus(200);
        $response->assertJson(['created' => 2]);

        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseCount('invoice_lines', 3);

        // First invoice carries the header overrides + 2 lines
        $invoiceOne = Invoice::where('customer_reference', 'PO-1')->first();
        $this->assertNotNull($invoiceOne);
        $this->assertSame('2026-01-15', $invoiceOne->invoice_date->format('Y-m-d'));
        $this->assertSame('2026-02-15', $invoiceOne->due_date->format('Y-m-d'));
        $this->assertSame('Net 30', $invoiceOne->terms);
        $this->assertSame((int) $customer->id, (int) $invoiceOne->customer_id);
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoiceOne->id,
            'product_id' => $productA->id,
            'description' => 'Widget A',
            'quantity' => 10,
            'unit_price' => 50,
        ]);
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoiceOne->id,
            'product_id' => $productB->id,
            'description' => 'Widget B',
            'quantity' => 5,
            'unit_price' => 20,
        ]);

        // Second invoice has a single line and was resolved by customer_code
        $invoiceTwo = Invoice::where('invoice_date', '2026-01-16')->first();
        $this->assertNotNull($invoiceTwo);
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoiceTwo->id,
            'description' => 'Solo line item',
            'quantity' => 2,
            'unit_price' => 15,
        ]);
    }

    public function test_import_reports_error_for_unknown_customer_without_failing_other_rows(): void
    {
        $user = User::create([
            'name' => 'Invoice Admin',
            'email' => 'admin-import2@test.local',
            'password' => bcrypt('password'),
        ]);

        Customer::factory()->create(['code' => 'CUST-001', 'name' => 'Acme Corp']);
        Product::factory()->create(['sku' => 'SKU-A', 'track_inventory' => false, 'price' => 50, 'cost' => 10]);

        $csv = implode("\n", [
            'invoice_number,status,customer_code,description,quantity,unit_price',
            'IMP-GOOD,draft,CUST-001,Widget A,2,50.00',
            'IMP-BAD,draft,NO-SUCH-CODE,Widget X,1,9.99',
        ]);

        $file = UploadedFile::fake()->createWithContent('invoices.csv', $csv);

        $response = $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->post('/api/v1/invoices/import', ['file' => $file]);

        $response->assertStatus(200);
        $response->assertJson(['created' => 1]);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Customer not found', $errors[0]['message']);

        $this->assertDatabaseCount('invoices', 1);
    }
}
