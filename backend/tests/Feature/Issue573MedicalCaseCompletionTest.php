<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #573 (MC8): invoice() was allowed from `allocated` (invoiced_qty ≤
 * delivered_qty = 0), so a case could go allocated → invoiced → completed with
 * nothing delivered and nothing invoiced.
 *
 * Acceptance: the case status is derived from the linked DO and invoice
 * quantities; `completed` requires every used line to be delivered and
 * invoiced (or FOC-redeemed).
 *
 * Real HTTP through the router (POST /api/v1/medical-cases/{id}/invoice and
 * POST /api/v1/medical-cases/{id}/complete).
 */
class Issue573MedicalCaseCompletionTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    private Product $focProduct;

    private SalesOrderLine $salesOrderLine;

    private SalesOrderLine $focSalesOrderLine;

    private Invoice $invoice;

    private InvoiceLine $invoiceLine;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '57357357-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'sales_order_lines', 'invoices', 'invoice_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'Issue573 User',
            'email' => 'issue573-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->user->assignRole('admin');

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
        $this->focProduct = Product::factory()->create();

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-573-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);
        $this->salesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 573 line',
            'product_id' => $this->product->id,
            'quantity' => 100,
            'unit_price' => 25,
        ]);
        $this->focSalesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 573 FOC line',
            'product_id' => $this->focProduct->id,
            'quantity' => 100,
            'unit_price' => 0,
        ]);

        $this->invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'posted',
            'created_by' => $this->user->id,
        ]);
        $this->invoiceLine = InvoiceLine::create([
            'invoice_id' => $this->invoice->id,
            'product_id' => $this->product->id,
            'description' => 'Issue 573 invoice line',
            'quantity' => 10,
            'unit_price' => 25,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => 250,
        ]);
    }

    private function makeCase(string $status, float $allocated, float $delivered, float $invoiced = 0): MedicalCaseItem
    {
        $case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'status' => $status,
            'created_by' => $this->user->id,
        ]);

        return MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLine->id,
            'product_id' => $this->product->id,
            'allocated_qty' => $allocated,
            'delivered_qty' => $delivered,
            'invoiced_qty' => $invoiced,
            'invoice_item_id' => $invoiced > 0 ? $this->invoiceLine->id : null,
            'unit_price' => 25,
        ]);
    }

    private function invoiceCase(MedicalCaseItem $item, float $qty): TestResponse
    {
        return $this->actingAs($this->user)->postJson(
            '/api/v1/medical-cases/'.$item->medical_case_id.'/invoice',
            [
                'invoice_id' => $this->invoice->id,
                'items' => [[
                    'id' => $item->id,
                    'invoiced_qty' => $qty,
                    'invoice_item_id' => $this->invoiceLine->id,
                ]],
            ],
        );
    }

    private function complete(int $caseId): TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/v1/medical-cases/'.$caseId.'/complete');
    }

    public function test_an_allocated_case_with_nothing_delivered_cannot_be_invoiced_or_completed(): void
    {
        $item = $this->makeCase('allocated', allocated: 6, delivered: 0);
        $caseId = (int) $item->medical_case_id;

        $invoice = $this->invoiceCase($item, 0);

        $this->assertNotSame(
            'invoiced',
            MedicalCase::findOrFail($caseId)->status,
            'A case with nothing delivered must not become invoiced (status '.$invoice->status().').',
        );

        $this->complete($caseId);

        $this->assertNotSame(
            'completed',
            MedicalCase::findOrFail($caseId)->status,
            'A case with nothing delivered or invoiced must never reach completed.',
        );
    }

    public function test_a_partially_invoiced_case_stays_delivered_and_cannot_complete(): void
    {
        $item = $this->makeCase('delivered', allocated: 6, delivered: 6);
        $caseId = (int) $item->medical_case_id;

        $this->invoiceCase($item, 3)->assertOk();

        $this->assertSame(
            'delivered',
            MedicalCase::findOrFail($caseId)->status,
            'Only 3 of 6 delivered units are invoiced, so the derived status is not invoiced.',
        );
        $this->assertSame((int) $this->invoice->id, (int) MedicalCase::findOrFail($caseId)->invoice_id);

        $this->complete($caseId)->assertStatus(403);
        $this->assertSame('delivered', MedicalCase::findOrFail($caseId)->status);
    }

    public function test_a_fully_delivered_and_invoiced_case_is_invoiced_then_completes(): void
    {
        $item = $this->makeCase('delivered', allocated: 6, delivered: 6);
        $caseId = (int) $item->medical_case_id;

        $this->invoiceCase($item, 6)->assertOk();
        $this->assertSame('invoiced', MedicalCase::findOrFail($caseId)->status);

        $this->complete($caseId)->assertOk();
        $this->assertSame('completed', MedicalCase::findOrFail($caseId)->status);
    }

    public function test_an_invoiced_case_with_undelivered_or_uninvoiced_lines_cannot_complete(): void
    {
        // Legacy rows created by the pre-fix flow: stamped invoiced with
        // nothing delivered or invoiced.
        $item = $this->makeCase('invoiced', allocated: 6, delivered: 0, invoiced: 0);
        $caseId = (int) $item->medical_case_id;

        $response = $this->complete($caseId);

        $response->assertStatus(422);
        $this->assertSame('invoiced', MedicalCase::findOrFail($caseId)->status);

        $partly = $this->makeCase('invoiced', allocated: 6, delivered: 6, invoiced: 2);

        $this->complete((int) $partly->medical_case_id)->assertStatus(422);
        $this->assertSame('invoiced', MedicalCase::findOrFail($partly->medical_case_id)->status);
    }

    public function test_a_delivered_foc_line_does_not_need_an_invoice_to_complete(): void
    {
        $item = $this->makeCase('delivered', allocated: 6, delivered: 6);
        $caseId = (int) $item->medical_case_id;

        MedicalCaseItem::create([
            'medical_case_id' => $caseId,
            'sales_order_item_id' => $this->focSalesOrderLine->id,
            'product_id' => $this->focProduct->id,
            'allocated_qty' => 1,
            'delivered_qty' => 1,
            'invoiced_qty' => 0,
            'unit_price' => 0,
            'is_foc' => true,
        ]);

        $this->invoiceCase($item, 6)->assertOk();
        $this->assertSame('invoiced', MedicalCase::findOrFail($caseId)->status);

        $this->complete($caseId)->assertOk();
        $this->assertSame('completed', MedicalCase::findOrFail($caseId)->status);
    }

    public function test_an_undelivered_foc_line_blocks_completion(): void
    {
        $item = $this->makeCase('invoiced', allocated: 6, delivered: 6, invoiced: 6);
        $caseId = (int) $item->medical_case_id;

        MedicalCaseItem::create([
            'medical_case_id' => $caseId,
            'sales_order_item_id' => $this->focSalesOrderLine->id,
            'product_id' => $this->focProduct->id,
            'allocated_qty' => 1,
            'delivered_qty' => 0,
            'unit_price' => 0,
            'is_foc' => true,
        ]);

        $this->complete($caseId)->assertStatus(422);
        $this->assertSame('invoiced', MedicalCase::findOrFail($caseId)->status);
    }
}
