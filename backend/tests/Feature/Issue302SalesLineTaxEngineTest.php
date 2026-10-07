<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #302 (X1 + X2): quotations, sales orders and invoices booked whatever tax %
 * the browser sent, and the browser took it from Product::effective_tax_rate —
 * a second ladder that ignores tax_mode='none', effective dates and rule scope.
 *
 * Business rule under test: a sales line's tax is resolved on the server at
 * save through the tax engine (document date, output direction, product), and
 * the browser-sent % is not booked as final. Only an explicit override — a
 * tax rule id on the line — replaces the engine result. The line persists the
 * resolved tax identity (rule id, code, treatment, inclusive flag).
 *
 * Every assertion runs through the real HTTP routes (router -> middleware ->
 * controller). The rule rows are the SG country pack's own rows
 * (config/tax_packs.php) — no rate or date is invented here.
 */
class Issue302SalesLineTaxEngineTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    /** @var array<string, Tax> */
    private array $rules = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantUuid,
            'name' => 'Issue 302 Tenant',
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

        // The SG pack's GST rows, exactly as the pack declares them.
        foreach ((array) config('tax_packs.SG.rules', []) as $rule) {
            if (! in_array($rule['code'], ['SG-GST-9', 'SG-GST-8', 'SG-GST-ZERO'], true)) {
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

        $this->assertCount(3, $this->rules, 'The SG pack must provide the GST 9, 8 and zero-rated rows.');

        // Tenant default = the current standard rate (what the Product ladder
        // and the screen fall back to).
        Setting::setValue('tax', [
            'enabled' => true,
            'pricing_mode' => 'exclusive',
            'default_tax_id' => $this->rules['SG-GST-9']->id,
            'jurisdiction' => 'SG',
        ]);

        $this->customer = Customer::factory()->create(['name' => 'Issue 302 Customer', 'payment_terms' => 30]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => 'Issue 302 Product '.Str::random(4),
            'sku' => 'I302-'.uniqid(),
            'track_inventory' => false,
            'price' => 100,
            'cost' => 60,
        ], $attributes));
    }

    /**
     * The rate the browser sends today: Product::effective_tax_rate before the
     * fix (the tenant default's 9% for every case in this file).
     */
    private function browserRate(): float
    {
        return (float) $this->rules['SG-GST-9']->rate;
    }

    private function postInvoice(Product $product, string $date, array $lineOverrides = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => $date,
            'lines' => [array_merge([
                'product_id' => $product->id,
                'description' => 'Issue 302 line',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => $this->browserRate(),
                'discount_amount' => 0,
            ], $lineOverrides)],
        ]);
    }

    private function invoiceLine(int $invoiceId): object
    {
        return DB::table('invoice_lines')->where('invoice_id', $invoiceId)->orderBy('id')->first();
    }

    public function test_exempt_product_invoiced_with_a_tenant_default_is_taxed_at_zero(): void
    {
        $product = $this->product(['tax_mode' => 'none']);

        $response = $this->postInvoice($product, now()->toDateString());
        $response->assertCreated();

        $invoice = Invoice::findOrFail($response->json('id'));
        $line = $this->invoiceLine((int) $invoice->id);

        $this->assertSame(0.0, round((float) $line->tax_rate, 2), 'An exempt product (tax_mode=none) is never taxed, whatever % the screen sent.');
        $this->assertSame(0.0, round((float) $invoice->tax_amount, 2));
        $this->assertSame(100.0, round((float) $invoice->total, 2));
        $this->assertNull($line->tax_rule_id, 'No tax rule applies to an exempt product.');
    }

    public function test_back_dated_invoice_uses_the_rate_in_force_on_that_date(): void
    {
        $product = $this->product();

        $response = $this->postInvoice($product, '2023-06-15');
        $response->assertCreated();

        $invoice = Invoice::findOrFail($response->json('id'));
        $line = $this->invoiceLine((int) $invoice->id);
        $historical = $this->rules['SG-GST-8'];

        $this->assertSame((float) $historical->rate, round((float) $line->tax_rate, 2), 'A 2023 invoice takes the rule in force in 2023, not today\'s rate.');
        $this->assertSame(round(100 * (float) $historical->rate / 100, 2), round((float) $invoice->tax_amount, 2));
        $this->assertSame((int) $historical->id, (int) $line->tax_rule_id);
        $this->assertSame('SG-GST-8', $line->tax_code);
        $this->assertSame('standard_rated', $line->tax_treatment);
        $this->assertFalse((bool) $line->tax_inclusive);
    }

    public function test_input_only_rule_is_never_applied_to_sales(): void
    {
        // A purchase-only rule linked to the product, and no output rule that
        // could apply: the tenant default is retired for this scenario.
        $inputOnly = $this->rules['SG-GST-9']->replicate();
        $inputOnly->code = 'SG-GST-9-INPUT-ONLY';
        $inputOnly->scope = 'input';
        $inputOnly->is_default = false;
        $inputOnly->save();

        foreach ($this->rules as $rule) {
            $rule->update(['is_active' => false]);
        }
        Setting::setValue('tax', ['enabled' => true, 'pricing_mode' => 'exclusive', 'default_tax_id' => null, 'jurisdiction' => 'SG']);

        $product = $this->product(['tax_id' => $inputOnly->id]);

        $response = $this->postInvoice($product, now()->toDateString());
        $response->assertCreated();

        $invoice = Invoice::findOrFail($response->json('id'));
        $line = $this->invoiceLine((int) $invoice->id);

        $this->assertSame(0.0, round((float) $line->tax_rate, 2), 'An input-only (purchase) rule must never charge tax on a sale.');
        $this->assertSame(0.0, round((float) $invoice->tax_amount, 2));
        $this->assertNotSame((int) $inputOnly->id, (int) ($line->tax_rule_id ?? 0));

        // An explicit override naming the input-only rule is refused, not booked.
        $this->postInvoice($product, now()->toDateString(), ['tax_rule_id' => $inputOnly->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.tax_rule_id']);
    }

    public function test_explicit_tax_rule_override_is_respected(): void
    {
        $product = $this->product();
        $zero = $this->rules['SG-GST-ZERO'];

        $response = $this->postInvoice($product, now()->toDateString(), ['tax_rule_id' => $zero->id]);
        $response->assertCreated();

        $invoice = Invoice::findOrFail($response->json('id'));
        $line = $this->invoiceLine((int) $invoice->id);

        $this->assertSame(0.0, round((float) $line->tax_rate, 2));
        $this->assertSame((int) $zero->id, (int) $line->tax_rule_id);
        $this->assertSame('SG-GST-ZERO', $line->tax_code);
        $this->assertSame('zero_rated', $line->tax_treatment);
    }

    public function test_quotation_and_sales_order_lines_resolve_through_the_engine(): void
    {
        $product = $this->product();
        $historical = $this->rules['SG-GST-8'];

        $quotation = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => '2023-06-15',
            'valid_until' => '2023-07-15',
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Quoted line',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => $this->browserRate(),
            ]],
        ]);
        $quotation->assertCreated();

        $quoteLine = DB::table('quotation_lines')->where('quotation_id', $quotation->json('data.id'))->first();
        $this->assertSame((float) $historical->rate, round((float) $quoteLine->tax_rate, 2));
        $this->assertSame((int) $historical->id, (int) $quoteLine->tax_rule_id);
        $this->assertSame('SG-GST-8', $quoteLine->tax_code);

        $order = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'order_date' => '2023-06-15',
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Ordered line',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => $this->browserRate(),
            ]],
        ]);
        $order->assertCreated();

        $orderLine = DB::table('sales_order_lines')->where('sales_order_id', $order->json('id'))->first();
        $this->assertSame((float) $historical->rate, round((float) $orderLine->tax_rate, 2));
        $this->assertSame((int) $historical->id, (int) $orderLine->tax_rule_id);
        $this->assertSame('SG-GST-8', $orderLine->tax_code);
        $this->assertSame('standard_rated', $orderLine->tax_treatment);
    }

    public function test_product_effective_tax_rate_delegates_to_the_engine(): void
    {
        $exempt = $this->product(['tax_mode' => 'none']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/products/{$exempt->id}")
            ->assertOk()
            ->assertJsonPath('effective_tax_rate', 0);
    }

    public function test_tax_preview_endpoint_returns_the_engine_identity_and_output_codes(): void
    {
        $product = $this->product();
        $historical = $this->rules['SG-GST-8'];

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/sales/tax-preview?'.http_build_query([
                'product_id' => $product->id,
                'date' => '2023-06-15',
            ]))
            ->assertOk();

        $this->assertSame((float) $historical->rate, (float) $response->json('data.tax_rate'));
        $this->assertSame((int) $historical->id, (int) $response->json('data.tax_rule_id'));
        $this->assertSame('SG-GST-8', $response->json('data.tax_code'));
        $this->assertSame('standard_rated', $response->json('data.tax_treatment'));

        $codes = collect($response->json('data.options'))->pluck('code')->all();
        $this->assertContains('SG-GST-8', $codes, 'The picker offers the rule in force on the date.');
        $this->assertContains('SG-GST-ZERO', $codes);
        $this->assertNotContains('SG-GST-9', $codes, 'A rule not yet in force on the date is not offered.');
    }
}
