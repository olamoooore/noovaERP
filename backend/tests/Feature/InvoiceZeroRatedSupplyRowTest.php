<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Singapore\Services\GstF5ReturnService;
use Tests\TenantTestCase;

/**
 * #304 (X4): with a tenant default tax, TaxDeterminationService treated every
 * 0%-rated invoice line as "engine-applied" — it reported standard-rated 9%
 * tax while the document booked none, so no tax GL line and no supply row
 * were written. Intended zero-rated exports vanished from GST F5 box 2.
 *
 * Guard: an explicit 0% is zero-rated, produces the supply row on the tax
 * trail and appears in F5 box 2.
 */
class InvoiceZeroRatedSupplyRowTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->user = User::factory()->create(['name' => 'Zero Rated Tester', 'email' => 'zero-rated-'.uniqid().'@test.local']);
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        // Tenant default: 9% GST (exclusive) — the trap from the issue.
        $defaultTax = Tax::create([
            'name' => 'Default GST 9', 'code' => 'SG-GST-9-'.Str::upper(Str::random(3)),
            'type' => 'gst', 'regime' => 'GST', 'jurisdiction' => 'SG',
            'scope' => 'both', 'treatment' => 'standard_rated', 'rate' => 9,
            'is_active' => true, 'is_inclusive' => false, 'is_default' => true,
        ]);
        Setting::setValue('tax', [
            'enabled' => true,
            'pricing_mode' => 'exclusive',
            'default_tax_id' => $defaultTax->id,
            'jurisdiction' => 'SG',
        ]);

        $this->customer = Customer::factory()->create(['name' => 'Zero Rated Customer', 'payment_terms' => 30]);
        $this->product = Product::factory()->create([
            'name' => 'Zero Rated Export', 'sku' => 'ZRE-'.uniqid(),
            'track_inventory' => false, 'price' => 100, 'cost' => 60,
        ]);
    }

    private function postInvoice(float $taxRate): Invoice
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Zero rated export line',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => $taxRate,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        $invoiceId = $response->json('id');
        $this->actingAs($this->user)->postJson("/api/v1/invoices/{$invoiceId}/send")->assertOk();

        return Invoice::with(['journalEntry', 'lines'])->findOrFail($invoiceId);
    }

    public function test_explicit_zero_rated_line_produces_a_supply_row_and_f5_box_2(): void
    {
        $invoice = $this->postInvoice(0);

        $this->assertSame(0.0, round((float) $invoice->tax_amount, 2), 'An explicit 0% books no tax.');
        $this->assertSame(100.0, round((float) $invoice->total, 2));

        $row = DB::table('tax_transaction_details')
            ->where('journal_entry_id', $invoice->journalEntry->id)
            ->first();

        $this->assertNotNull($row, 'A zero-rated supply row must be written to the tax trail.');
        $this->assertSame('zero_rated', $row->tax_treatment);
        $this->assertSame('supply', $row->direction);
        $this->assertSame(0.0, round((float) $row->tax_amount, 2));
        $this->assertSame(100.0, round((float) $row->taxable_amount, 2));

        $f5 = app(GstF5ReturnService::class)->generate(now()->format('Y-m'));

        $this->assertSame(100.0, round((float) $f5['boxes']['box_2_zero_rated_supplies'], 2), 'The sale must appear in F5 box 2.');
        $this->assertSame(0.0, round((float) $f5['boxes']['box_1_standard_rated_supplies'], 2), 'It must NOT be reported as standard-rated.');
        $this->assertSame(0.0, round((float) $f5['boxes']['box_6_output_tax_due'], 2), 'No output tax may be reported.');
    }

    public function test_a_line_booked_at_the_default_rate_stays_standard_rated(): void
    {
        $invoice = $this->postInvoice(9);

        $this->assertSame(9.0, round((float) $invoice->tax_amount, 2));

        $f5 = app(GstF5ReturnService::class)->generate(now()->format('Y-m'));

        $this->assertSame(100.0, round((float) $f5['boxes']['box_1_standard_rated_supplies'], 2), 'A genuinely standard-rated sale still lands in box 1.');
        $this->assertSame(9.0, round((float) $f5['boxes']['box_6_output_tax_due'], 2));
        $this->assertSame(0.0, round((float) $f5['boxes']['box_2_zero_rated_supplies'], 2));
    }
}
