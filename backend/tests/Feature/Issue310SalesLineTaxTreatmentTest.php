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
 * #310 (X10): the sales line editor could only send a numeric tax %, and
 * TaxDeterminationService::determineForInvoice() turned every 0% line into
 * `zero_rated` unless a document override said `exempt`. Exempt sales landed
 * in GST F5 box 2 (zero-rated) instead of box 3 (exempt).
 *
 * Business rule under test: a sales line carries an explicit tax treatment —
 * Standard-rated, Zero-rated, Exempt or Out-of-scope — chosen either as a
 * tax code (rule) or as a treatment, persisted per line, and honoured at
 * posting. The F5 boxes follow the SG pack's own mapping (config/tax_packs.php
 * f5_boxes: box 1 = output_supplies:standard_rated, box 2 = zero_rated,
 * box 3 = exempt, box 4 = sum of 1-3); an out-of-scope supply is in none of
 * boxes 1-4. Every assertion runs through the real HTTP routes. The rule rows
 * are the SG pack's own rows — no rate or date is invented here.
 */
class Issue310SalesLineTaxTreatmentTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    /** @var array<string, Tax> */
    private array $rules = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantUuid,
            'name' => 'Issue 310 Tenant',
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
            if (! in_array($rule['code'], ['SG-GST-9', 'SG-GST-ZERO', 'SG-GST-EXEMPT'], true)) {
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

        $this->assertCount(3, $this->rules, 'The SG pack must provide the GST 9, zero-rated and exempt rows.');

        Setting::setValue('tax', [
            'enabled' => true,
            'pricing_mode' => 'exclusive',
            'default_tax_id' => $this->rules['SG-GST-9']->id,
            'jurisdiction' => 'SG',
        ]);

        $this->customer = Customer::factory()->create(['name' => 'Issue 310 Customer', 'payment_terms' => 30]);
        $this->product = Product::factory()->create([
            'name' => 'Issue 310 Product',
            'sku' => 'I310-'.uniqid(),
            'track_inventory' => false,
            'price' => 100,
            'cost' => 60,
        ]);
    }

    private function line(string $description, array $tax): array
    {
        return array_merge([
            'product_id' => $this->product->id,
            'description' => $description,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
        ], $tax);
    }

    private function createInvoice(array $lines)
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => $lines,
        ]);
    }

    private function sendAndLoad(int $invoiceId): Invoice
    {
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/invoices/{$invoiceId}/send")->assertOk();

        return Invoice::with(['journalEntry.lines', 'lines'])->findOrFail($invoiceId);
    }

    private function f5Boxes(): array
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/accounting/statutory-returns/SG/'.now()->format('Y-m'));
        $response->assertOk();

        return (array) $response->json('data.boxes');
    }

    private function assertBooksBalance(Invoice $invoice): void
    {
        $this->assertNotNull($invoice->journalEntry, 'The invoice must post through the PostingEngine.');
        $debits = round((float) $invoice->journalEntry->lines->sum('debit'), 2);
        $credits = round((float) $invoice->journalEntry->lines->sum('credit'), 2);
        $this->assertGreaterThan(0.0, $debits);
        $this->assertSame($debits, $credits, 'The posted journal must balance.');
    }

    public function test_each_treatment_is_persisted_per_line_and_lands_in_its_f5_box(): void
    {
        $response = $this->createInvoice([
            $this->line('Standard-rated line', ['tax_rate' => 9]),
            $this->line('Zero-rated line', ['tax_rate' => 0, 'tax_rule_id' => $this->rules['SG-GST-ZERO']->id]),
            $this->line('Exempt line', ['tax_rate' => 0, 'tax_rule_id' => $this->rules['SG-GST-EXEMPT']->id]),
            $this->line('Out-of-scope line', ['tax_rate' => 0, 'tax_treatment' => 'out_of_scope']),
        ]);
        $response->assertCreated();

        $lines = DB::table('invoice_lines')
            ->where('invoice_id', $response->json('id'))
            ->orderBy('id')
            ->get()
            ->keyBy('description');

        $this->assertSame('standard_rated', $lines['Standard-rated line']->tax_treatment);
        $this->assertSame('zero_rated', $lines['Zero-rated line']->tax_treatment);
        $this->assertSame('exempt', $lines['Exempt line']->tax_treatment);
        $this->assertSame('SG-GST-EXEMPT', $lines['Exempt line']->tax_code);
        $this->assertSame('out_of_scope', $lines['Out-of-scope line']->tax_treatment);
        $this->assertSame(0.0, round((float) $lines['Out-of-scope line']->tax_rate, 2));

        $invoice = $this->sendAndLoad((int) $response->json('id'));
        $this->assertSame(9.0, round((float) $invoice->tax_amount, 2));
        $this->assertSame(409.0, round((float) $invoice->total, 2));
        $this->assertBooksBalance($invoice);

        $boxes = $this->f5Boxes();
        $this->assertSame(100.0, round((float) $boxes['box_1_standard_rated_supplies'], 2), 'Standard-rated line in box 1.');
        $this->assertSame(100.0, round((float) $boxes['box_2_zero_rated_supplies'], 2), 'Only the zero-rated line in box 2.');
        $this->assertSame(100.0, round((float) $boxes['box_3_exempt_supplies'], 2), 'The exempt line must land in F5 box 3.');
        $this->assertSame(300.0, round((float) $boxes['box_4_total_supplies'], 2), 'Box 4 = 1+2+3; the out-of-scope line is not a supply.');
        $this->assertSame(9.0, round((float) $boxes['box_6_output_tax_due'], 2));
    }

    public function test_an_exempt_tax_code_on_the_line_lands_in_f5_box_3_not_box_2(): void
    {
        $response = $this->createInvoice([
            $this->line('Exempt by code', ['tax_rate' => 0, 'tax_rule_id' => $this->rules['SG-GST-EXEMPT']->id]),
        ]);
        $response->assertCreated();

        $invoice = $this->sendAndLoad((int) $response->json('id'));
        $this->assertBooksBalance($invoice);

        $row = DB::table('tax_transaction_details')->where('journal_entry_id', $invoice->journalEntry->id)->first();
        $this->assertNotNull($row, 'An exempt supply row must be written to the tax trail.');
        $this->assertSame('exempt', $row->tax_treatment);

        $boxes = $this->f5Boxes();
        $this->assertSame(100.0, round((float) $boxes['box_3_exempt_supplies'], 2), 'The exempt sale must land in F5 box 3.');
        $this->assertSame(0.0, round((float) $boxes['box_2_zero_rated_supplies'], 2), 'It must NOT be reported as zero-rated.');
    }

    public function test_an_exempt_treatment_without_a_code_adopts_the_exempt_rule_and_lands_in_box_3(): void
    {
        $response = $this->createInvoice([
            $this->line('Exempt by treatment', ['tax_rate' => 0, 'tax_treatment' => 'exempt']),
        ]);
        $response->assertCreated();

        $line = DB::table('invoice_lines')->where('invoice_id', $response->json('id'))->first();
        $this->assertSame('exempt', $line->tax_treatment);
        $this->assertSame((int) $this->rules['SG-GST-EXEMPT']->id, (int) $line->tax_rule_id);

        $invoice = $this->sendAndLoad((int) $response->json('id'));
        $this->assertBooksBalance($invoice);

        $boxes = $this->f5Boxes();
        $this->assertSame(100.0, round((float) $boxes['box_3_exempt_supplies'], 2));
        $this->assertSame(0.0, round((float) $boxes['box_2_zero_rated_supplies'], 2), 'An exempt sale is not zero-rated.');
    }

    public function test_a_zero_percent_line_without_a_treatment_stays_zero_rated(): void
    {
        $response = $this->createInvoice([
            $this->line('Plain 0% line', ['tax_rate' => 0]),
        ]);
        $response->assertCreated();

        $this->sendAndLoad((int) $response->json('id'));

        $boxes = $this->f5Boxes();
        $this->assertSame(100.0, round((float) $boxes['box_2_zero_rated_supplies'], 2), 'Existing behaviour: an explicit 0% is zero-rated.');
        $this->assertSame(0.0, round((float) $boxes['box_3_exempt_supplies'], 2));
    }

    public function test_a_non_charging_treatment_with_a_positive_rate_is_refused(): void
    {
        $this->createInvoice([
            $this->line('Contradictory line', ['tax_rate' => 9, 'tax_treatment' => 'exempt']),
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.tax_treatment']);
    }

    public function test_an_unknown_treatment_is_refused(): void
    {
        $this->createInvoice([
            $this->line('Bad treatment', ['tax_rate' => 0, 'tax_treatment' => 'tax_free']),
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.tax_treatment']);
    }

    public function test_quotation_and_sales_order_lines_persist_the_chosen_treatment(): void
    {
        $quotation = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'lines' => [$this->line('Quoted exempt', ['tax_rate' => 0, 'tax_treatment' => 'exempt'])],
        ]);
        $quotation->assertCreated();

        $quoteLine = DB::table('quotation_lines')->where('quotation_id', $quotation->json('data.id'))->first();
        $this->assertSame('exempt', $quoteLine->tax_treatment);

        $order = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'lines' => [$this->line('Ordered out of scope', ['tax_rate' => 0, 'tax_treatment' => 'out_of_scope'])],
        ]);
        $order->assertCreated();

        $orderLine = DB::table('sales_order_lines')->where('sales_order_id', $order->json('id'))->first();
        $this->assertSame('out_of_scope', $orderLine->tax_treatment);
    }
}
