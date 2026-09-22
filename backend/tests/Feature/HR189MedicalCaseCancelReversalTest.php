<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BonusScheme;
use App\Models\BonusSchemeUsage;
use App\Models\Customer;
use App\Models\CustomerBonusScheme;
use App\Models\Invoice;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Product;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #189 — cancel() only flipped the case status: delivered/invoiced
 * quantities stayed on the items (and bonus usage rows stayed applied), so
 * downstream reports kept counting a case the business had reversed.
 */
class HR189MedicalCaseCancelReversalTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    private SalesOrderLine $salesOrderLine;

    private CustomerBonusScheme $customerScheme;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '55555555-4444-3333-2222-111111111111',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['medical_cases', 'medical_case_items', 'bonus_scheme_usages', 'sales_order_lines']);

        $this->user = User::factory()->create();

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

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-'.Str::upper(Str::random(8)),
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);
        $this->salesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'HR189 line',
            'product_id' => $this->product->id,
        ]);

        $scheme = BonusScheme::create([
            'scheme_code' => 'HR189-'.Str::upper(Str::random(5)),
            'name' => 'HR189 Scheme',
            'start_date' => '2026-01-01',
            'created_by' => $this->user->id,
        ]);
        $this->customerScheme = CustomerBonusScheme::create([
            'customer_id' => $this->customer->id,
            'bonus_scheme_id' => $scheme->id,
            'start_date' => '2026-01-01',
        ]);
    }

    private function medicalCase(string $status, ?int $invoiceId = null): MedicalCase
    {
        return MedicalCase::create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoiceId,
            'status' => $status,
            'created_by' => $this->user->id,
        ]);
    }

    private function item(MedicalCase $case, float $delivered, float $invoiced): MedicalCaseItem
    {
        return MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLine->id,
            'product_id' => $this->product->id,
            'allocated_qty' => 10,
            'delivered_qty' => $delivered,
            'invoiced_qty' => $invoiced,
            'unit_price' => 5,
        ]);
    }

    public function test_cancelling_an_invoiced_case_reverses_fulfilment_and_bonus_usage(): void
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        $case = $this->medicalCase('invoiced', $invoice->id);
        $item = $this->item($case, 10, 10);

        $usage = BonusSchemeUsage::create([
            'customer_bonus_scheme_id' => $this->customerScheme->id,
            'sales_order_item_id' => $this->salesOrderLine->id,
            'medical_case_item_id' => $item->id,
            'qualifying_qty' => 10,
            'discount_amount' => 50,
            'foc_qty' => 1,
            'status' => 'applied',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/medical-cases/'.$case->id.'/cancel');

        $response->assertOk();
        $this->assertSame('cancelled', $case->fresh()->status);

        $item->refresh();
        $this->assertSame(0.0, round((float) $item->delivered_qty, 4));
        $this->assertSame(0.0, round((float) $item->invoiced_qty, 4));

        $this->assertSame('reversed', $usage->fresh()->status);

        // The linked invoice is left to the credit-note/return flow and the
        // caller is told so.
        $this->assertNotEmpty($response->json('warnings'));
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_cancelling_a_draft_case_leaves_quantities_untouched(): void
    {
        $case = $this->medicalCase('draft');
        $item = $this->item($case, 0, 0);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/medical-cases/'.$case->id.'/cancel');

        $response->assertOk();
        $this->assertSame('cancelled', $case->fresh()->status);
        $this->assertSame([], $response->json('warnings'));

        $item->refresh();
        $this->assertSame(10.0, round((float) $item->allocated_qty, 4));
    }

    public function test_completed_cases_cannot_be_cancelled(): void
    {
        $case = $this->medicalCase('completed');

        $this->actingAs($this->user)
            ->postJson('/api/v1/medical-cases/'.$case->id.'/cancel')
            ->assertStatus(403);
    }
}
