<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #321 ([SALES-UX] Q13) — the quotation's payment term is picked from
 * the payment-terms master, carried to the Sales Order and the invoice, and
 * drives the invoice due date (instead of the customer's day count).
 *
 * Everything runs through the real HTTP routes (router → middleware →
 * controller → service).
 */
class Issue321QuotationPaymentTermMasterTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private PaymentTerm $net45;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Issue 321 Tenant');

        CompanySettings::query()->create(['base_currency' => 'MYR']);

        $category = ProductCategory::create(['name' => 'Issue321 Widgets', 'is_active' => true]);

        // The customer's own default is 30 days — the quotation's master term
        // (45 days) must win on the invoice.
        $this->customer = Customer::factory()->create([
            'name' => 'Issue321 Customer',
            'is_active' => true,
            'payment_terms' => 30,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Issue321 Widget',
            'sku' => 'I321-'.uniqid(),
            'category_id' => $category->id,
            'price' => 100,
            'cost' => 10,
            'track_inventory' => false,
        ]);

        $this->net45 = PaymentTerm::create([
            'name' => 'Net 45',
            'code' => 'NET45-'.uniqid(),
            'type' => 'net_days',
            'days' => 45,
            'is_active' => true,
        ]);
    }

    private function quotationPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => 'Issue321 quoted line',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ], $overrides);
    }

    public function test_payment_term_from_master_is_stored_carried_to_order_and_invoice_and_sets_due_date(): void
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/quotations', $this->quotationPayload([
            'payment_term_id' => $this->net45->id,
        ]));
        $create->assertCreated();

        $quotation = Quotation::findOrFail((int) $create->json('data.id'));
        $this->assertSame($this->net45->id, (int) $quotation->payment_term_id, 'The picked master term must be stored on the quotation');
        $this->assertSame('Net 45', $quotation->credit_terms, 'The printed credit terms follow the master term name');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $quotation->refresh();
        $salesOrder = SalesOrder::findOrFail($quotation->sales_order_id);
        $this->assertSame($this->net45->id, (int) $salesOrder->payment_term_id, 'The term must carry quote → sales order');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertCreated();

        $invoice = Invoice::query()->where('quotation_id', $quotation->id)->firstOrFail();
        $this->assertSame($this->net45->id, (int) $invoice->payment_term_id, 'The term must carry quote → invoice');

        $expectedDue = Carbon::parse($invoice->invoice_date)->addDays(45)->toDateString();
        $this->assertSame(
            $expectedDue,
            $invoice->due_date->toDateString(),
            'The invoice due date must come from the quotation payment term (45 days), not the customer default (30)'
        );
    }

    public function test_payment_term_can_be_changed_on_a_draft_edit(): void
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/quotations', $this->quotationPayload());
        $create->assertCreated();
        $quotation = Quotation::findOrFail((int) $create->json('data.id'));

        $this->actingAs($this->admin)->putJson("/api/v1/quotations/{$quotation->id}", [
            'payment_term_id' => $this->net45->id,
        ])->assertOk();

        $quotation->refresh();
        $this->assertSame($this->net45->id, (int) $quotation->payment_term_id);
        $this->assertSame('Net 45', $quotation->credit_terms);
    }

    public function test_unknown_payment_term_is_rejected(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/quotations', $this->quotationPayload([
            'payment_term_id' => 999999,
        ]))->assertStatus(422)->assertJsonValidationErrors('payment_term_id');
    }
}
