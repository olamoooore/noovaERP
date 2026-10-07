<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #409 ([SALES-ODOO]) — delivery methods and shipping cost lines.
 *
 * Business rules under test (issue acceptance criteria):
 *  - a delivery-method master (fixed price / percentage of the order amount,
 *    free above a threshold) whose charge is billed through a service product;
 *  - "Add shipping" adds ONE shipping line to a quotation or draft order; the
 *    line is an ordinary line — priced by the method, taxed by the line tax
 *    resolver (#302) at the DOCUMENT date — and re-adding replaces it;
 *  - the shipping line is carried quote → order → invoice like any line, the
 *    posted invoice journal balances, and the method is printed on the
 *    delivery order (its carrier field).
 *
 * Everything goes through the real HTTP routes. Tax rows are the SG country
 * pack's own rows (config/tax_packs.php) — no rate or date is invented here.
 */
class Issue409DeliveryMethodShippingLineTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    /** @var array<string, Tax> */
    private array $rules = [];

    private Product $goods;

    private Product $shippingProduct;

    /** @var array<string, int> mapping key => account id */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantUuid,
            'name' => 'Issue 409 Tenant',
            'email' => $tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ((array) config('tax_packs.SG.rules', []) as $rule) {
            if (! in_array($rule['code'], ['SG-GST-9', 'SG-GST-8'], true)) {
                continue;
            }

            $this->rules[$rule['code']] = Tax::create([
                'name' => $rule['name'],
                'code' => $rule['code'],
                'type' => $rule['type'],
                'regime' => $rule['regime'],
                'jurisdiction' => $rule['jurisdiction'],
                'scope' => $rule['scope'],
                'treatment' => $rule['treatment'],
                'rate' => $rule['rate'],
                'is_inclusive' => (bool) $rule['is_inclusive'],
                'effective_from' => $rule['effective_from'],
                'effective_to' => $rule['effective_to'],
                'is_active' => true,
                'is_default' => $rule['code'] === 'SG-GST-9',
            ]);
        }
        $this->assertCount(2, $this->rules, 'The SG pack must provide the GST 9 and 8 rows.');

        Setting::setValue('tax', [
            'enabled' => true,
            'pricing_mode' => 'exclusive',
            'default_tax_id' => $this->rules['SG-GST-9']->id,
            'jurisdiction' => 'SG',
        ]);

        $revenue = ChartOfAccount::factory()->create(['code' => 'I409-4000', 'name' => 'I409 Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => 'I409-1100', 'name' => 'I409 Receivable', 'type' => 'asset', 'is_active' => true]);
        $taxPayable = ChartOfAccount::factory()->create(['code' => 'I409-2200', 'name' => 'I409 Tax Payable', 'type' => 'liability', 'is_active' => true]);
        $this->accounts = ['sales_revenue' => $revenue->id, 'accounts_receivable' => $ar->id, 'sales_tax_payable' => $taxPayable->id];
        foreach ($this->accounts as $key => $accountId) {
            AccountMapping::query()->where('mapping_key', $key)->delete();
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $accountId]);
        }

        $this->customer = Customer::factory()->create(['name' => 'Issue 409 Customer', 'payment_terms' => 30]);

        $this->goods = Product::factory()->create([
            'name' => 'I409 Widget',
            'sku' => 'I409-W-'.uniqid(),
            'type' => 'product',
            'track_inventory' => false,
            'price' => 100,
            'cost' => 40,
        ]);

        // The carrier charge is billed through a service product. Its own
        // list price is deliberately different from the method's price.
        $this->shippingProduct = Product::factory()->create([
            'name' => 'I409 Shipping',
            'sku' => 'I409-SHIP-'.uniqid(),
            'type' => 'service',
            'track_inventory' => false,
            'price' => 99,
            'cost' => 0,
        ]);
    }

    private function createMethod(array $attributes): int
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sales/delivery-methods', array_merge([
            'product_id' => $this->shippingProduct->id,
        ], $attributes));
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function createQuotation(string $date, float $unitPrice, float $quantity = 1): int
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => $date,
            'valid_until' => \Illuminate\Support\Carbon::parse($date)->addYears(5)->toDateString(),
            'lines' => [[
                'product_id' => $this->goods->id,
                'description' => 'Widget',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'price_mode' => 'manual',
            ]],
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function deliveryLines(string $table, string $fk, int $documentId)
    {
        return DB::table($table)->where($fk, $documentId)->where('is_delivery', true)->get();
    }

    public function test_fixed_price_shipping_line_is_engine_taxed_carried_to_order_and_invoice_and_posts_balanced(): void
    {
        $methodId = $this->createMethod(['name' => 'Standard Courier', 'pricing_type' => 'fixed', 'fixed_price' => 15]);
        $quotationId = $this->createQuotation(now()->toDateString(), 100);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/shipping", ['delivery_method_id' => $methodId])
            ->assertOk();

        // Adding shipping again replaces the line — never two shipping lines.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/shipping", ['delivery_method_id' => $methodId])
            ->assertOk();

        $standard = $this->rules['SG-GST-9'];
        $expectedTax = round(15 * (float) $standard->rate / 100, 2);

        $quoteShipping = $this->deliveryLines('quotation_lines', 'quotation_id', $quotationId);
        $this->assertCount(1, $quoteShipping, 'Exactly one shipping line on the quotation.');
        $line = $quoteShipping->first();
        $this->assertSame((int) $this->shippingProduct->id, (int) $line->product_id, 'The shipping line bills the method\'s service product.');
        $this->assertSame(15.0, round((float) $line->unit_price, 2), 'The method price wins over the shipping product list price.');
        $this->assertSame(1.0, (float) $line->quantity);
        $this->assertSame((int) $standard->id, (int) $line->tax_rule_id, 'The shipping line tax comes from the tax engine.');
        $this->assertSame('SG-GST-9', $line->tax_code);
        $this->assertSame($expectedTax, round((float) $line->tax_amount, 2));

        $quotation = DB::table('quotations')->where('id', $quotationId)->first();
        $this->assertSame($methodId, (int) $quotation->delivery_method_id);
        $this->assertSame(round(100 + 100 * (float) $standard->rate / 100 + 15 + $expectedTax, 2), round((float) $quotation->total, 2),
            'The quotation total includes the taxed shipping line.');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/quotations/{$quotationId}/confirm")->assertOk();
        $orderId = (int) DB::table('quotations')->where('id', $quotationId)->value('sales_order_id');
        $this->assertGreaterThan(0, $orderId);

        $orderShipping = $this->deliveryLines('sales_order_lines', 'sales_order_id', $orderId);
        $this->assertCount(1, $orderShipping, 'The shipping line is carried to the sales order.');
        $this->assertSame(15.0, round((float) $orderShipping->first()->unit_price, 2));
        $this->assertSame((int) $standard->id, (int) $orderShipping->first()->tax_rule_id);
        $this->assertSame($methodId, (int) DB::table('sales_orders')->where('id', $orderId)->value('delivery_method_id'));

        $invoiceResponse = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/sales/orders/{$orderId}/invoice");
        $invoiceResponse->assertStatus(201);
        $invoice = Invoice::with('lines')->findOrFail($invoiceResponse->json('invoice.id'));

        $invoiceShipping = $invoice->lines->firstWhere('sales_order_line_id', $orderShipping->first()->id);
        $this->assertNotNull($invoiceShipping, 'The shipping line is carried to the invoice.');
        $this->assertSame(15.0, round((float) $invoiceShipping->unit_price, 2));
        $this->assertSame((int) $standard->id, (int) $invoiceShipping->tax_rule_id);
        $this->assertSame($expectedTax, round((float) $invoiceShipping->tax_amount, 2));
        $this->assertSame(round((float) $quotation->total, 2), round((float) $invoice->total, 2));

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/invoices/{$invoice->id}/post")->assertOk();
        $journalLines = Invoice::with('journalEntry.lines')->findOrFail($invoice->id)->journalEntry->lines;
        $this->assertSame(
            round((float) $journalLines->sum('debit'), 2),
            round((float) $journalLines->sum('credit'), 2),
            'The posted invoice journal balances.'
        );
        $this->assertSame(round((float) $invoice->total, 2),
            round((float) $journalLines->where('account_id', $this->accounts['accounts_receivable'])->sum('debit'), 2),
            'Receivable carries the full total including shipping and its tax.');
        $this->assertSame(115.0,
            round((float) $journalLines->where('account_id', $this->accounts['sales_revenue'])->sum('credit'), 2),
            'Revenue is the widget (100) plus the shipping charge (15).');
        $this->assertSame(round(100 * (float) $standard->rate / 100 + $expectedTax, 2),
            round((float) $journalLines->where('account_id', $this->accounts['sales_tax_payable'])->sum('credit'), 2),
            'Output tax includes the engine-resolved tax on the shipping line.');
    }

    public function test_percentage_method_uses_order_amount_document_date_tax_and_free_threshold(): void
    {
        $methodId = $this->createMethod([
            'name' => 'Percent Freight',
            'pricing_type' => 'percentage',
            'percentage' => 10,
            'free_over_amount' => 500,
        ]);

        // Back-dated quotation: the shipping line takes the rule in force then.
        $quotationId = $this->createQuotation('2023-06-15', 100, 2);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/shipping", ['delivery_method_id' => $methodId])
            ->assertOk();

        $historical = $this->rules['SG-GST-8'];
        $line = $this->deliveryLines('quotation_lines', 'quotation_id', $quotationId)->first();
        $this->assertNotNull($line);
        $this->assertSame(20.0, round((float) $line->unit_price, 2), '10% of the 200 untaxed order amount.');
        $this->assertSame((int) $historical->id, (int) $line->tax_rule_id, 'Tax resolved at the quotation date, not today.');
        $this->assertSame(round(20 * (float) $historical->rate / 100, 2), round((float) $line->tax_amount, 2));

        // Above the free threshold the shipping line is added at zero.
        $bigQuotationId = $this->createQuotation(now()->toDateString(), 300, 2);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/quotations/{$bigQuotationId}/shipping", ['delivery_method_id' => $methodId])
            ->assertOk();

        $free = $this->deliveryLines('quotation_lines', 'quotation_id', $bigQuotationId)->first();
        $this->assertNotNull($free);
        $this->assertSame(0.0, round((float) $free->unit_price, 2), 'Free shipping above the threshold.');
        $this->assertSame(0.0, round((float) $free->tax_amount, 2));
    }

    public function test_draft_sales_order_add_shipping_and_delivery_order_prints_the_method(): void
    {
        $methodId = $this->createMethod(['name' => 'Express Van', 'pricing_type' => 'fixed', 'fixed_price' => 25]);

        $order = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->goods->id,
                'description' => 'Widget',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ]);
        $order->assertCreated();
        $orderId = (int) $order->json('id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$orderId}/shipping", ['delivery_method_id' => $methodId])
            ->assertOk();

        $line = $this->deliveryLines('sales_order_lines', 'sales_order_id', $orderId)->first();
        $this->assertNotNull($line, 'Add shipping adds a shipping line to a draft order.');
        $this->assertSame(25.0, round((float) $line->unit_price, 2));
        $this->assertSame((int) $this->rules['SG-GST-9']->id, (int) $line->tax_rule_id);
        $this->assertSame(round(25 * (float) $this->rules['SG-GST-9']->rate / 100, 2), round((float) $line->tax_amount, 2));

        // Printed on the delivery order: the method is the DO's carrier.
        $quotationId = $this->createQuotation(now()->toDateString(), 100);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/shipping", ['delivery_method_id' => $methodId])
            ->assertOk();
        Warehouse::factory()->create();
        $do = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/quotations/{$quotationId}/create-delivery-order");
        $this->assertContains($do->status(), [200, 201], $do->getContent());
        $this->assertSame('Express Van', $do->json('data.carrier'));
    }

    public function test_shipping_is_refused_on_a_confirmed_quotation_and_the_method_needs_a_service_product(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sales/delivery-methods', [
            'name' => 'Bad Method',
            'pricing_type' => 'fixed',
            'fixed_price' => 5,
            'product_id' => $this->goods->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['product_id']);

        $methodId = $this->createMethod(['name' => 'Standard Courier', 'pricing_type' => 'fixed', 'fixed_price' => 15]);
        $quotationId = $this->createQuotation(now()->toDateString(), 100);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/quotations/{$quotationId}/confirm")->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/shipping", ['delivery_method_id' => $methodId])
            ->assertStatus(422);
        $this->assertCount(0, DB::table('quotation_lines')->where('quotation_id', $quotationId)->where('product_id', $this->shippingProduct->id)->get());
    }
}
