<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Tax;
use App\Models\TaxDeterminationResult;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorCreditNote;
use App\Models\VendorCreditNoteLine;
use App\Services\TaxDeterminationService;
use App\Services\TaxEngineService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Services\TaxReturnAggregator;
use Modules\Ecommerce\Models\WebOrder;
use Modules\Ecommerce\Models\WebOrderItem;
use Modules\Ecommerce\Services\WebOrderPostingService;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\POS\Services\PosPostingService;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

/**
 * Tax Engine MRD Phase 0 (docs/tax-engine/TAX-ENGINE-MRD-2026-09-29.md §11).
 *
 * Verifies the integrity fixes D2/D3/D4/D5/D10 through the REAL posting
 * pipeline (PostingEngine → authority gates → tax_transaction_details →
 * statutory aggregation) for every flow that previously lost its tax trail:
 * POS, e-commerce web orders, customer/vendor credit notes, and vendor bills.
 */
class TaxEnginePhase0IntegrityTest extends TenantTestCase
{
    private User $user;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'taxes', 'journal_entries', 'journal_entry_lines', 'tax_transaction_details',
            'tax_determination_results', 'pos_orders', 'web_orders', 'bills', 'invoices',
        ]);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['pos.create', 'sales.write', 'accounting.write', 'reports.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $this->user->givePermissionTo($permission);
        }

        $this->ensureAccountingScaffold();
        $this->period = now()->format('Y-m');
    }

    private function ensureAccountingScaffold(): void
    {
        $accounts = [
            'cash' => ['1000-T0', 'asset'],
            'accounts_receivable' => ['1100-T0', 'asset'],
            'sales_tax_payable' => ['2200-T0', 'liability'],
            'input_tax' => ['2210-T0', 'asset'],
            'accounts_payable' => ['2000-T0', 'liability'],
            'sales_revenue' => ['3000-T0', 'revenue'],
            'general_expense' => ['4000-T0', 'expense'],
        ];

        foreach ($accounts as $key => [$code, $type]) {
            $account = ChartOfAccount::create([
                'code' => $code,
                'name' => "T0 {$key}",
                'type' => $type,
                'account_type' => $type,
                'is_active' => true,
            ]);
            \App\Models\AccountMapping::create(['mapping_key' => $key, 'account_id' => $account->id]);
        }
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'name' => 'T0 Product',
            'sku' => 'T0-'.Str::random(8),
            'is_active' => true,
            'track_inventory' => false,
        ]);
    }

    private function makePosSession(): int
    {
        $terminal = \Modules\POS\Models\PosTerminal::create([
            'name' => 'T0 Terminal',
            'code' => 'T0TERM-'.Str::random(6),
            'is_active' => true,
        ]);

        $session = \Modules\POS\Models\PosSession::create([
            'terminal_id' => $terminal->id,
            'user_id' => $this->user->id,
            'session_number' => 'T0SESS-'.Str::random(6),
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);

        return (int) $session->id;
    }

    private function makeTaxRule(string $code, string $scope): Tax
    {
        return Tax::create([
            'name' => "SG GST 9% ({$scope})",
            'code' => $code,
            'type' => 'gst',
            'regime' => 'GST',
            'jurisdiction' => 'SG',
            'scope' => $scope,
            'treatment' => 'standard_rated',
            'rate' => 9,
            'is_active' => true,
        ]);
    }

    // ── D5: no silent country-default taxation ────────────────────────────

    public function test_engine_never_charges_tax_for_an_unconfigured_tenant(): void
    {
        $resolved = app(TaxEngineService::class)->resolve($this->makeProduct(), 'SG', 'output');

        $this->assertSame(0.0, (float) $resolved['rate']);
        $this->assertSame('none', $resolved['source']);
        $this->assertSame('NO-TAX', $resolved['tax_code']);
    }

    public function test_untaxed_pos_sale_still_posts_when_no_rule_is_configured(): void
    {
        $product = $this->makeProduct();
        $order = $this->makePosOrder($product, taxRate: 0.0);

        $entry = app(PosPostingService::class)->postPaidOrder($order, 100.00, 'cash');

        $this->assertNotNull($entry);
        $this->assertSame(0, DB::table('tax_transaction_details')->where('journal_entry_id', $entry->id)->count());
    }

    // ── D2: POS tax postings satisfy the TaxGate and reach the return ─────

    public function test_taxed_pos_sale_posts_with_determination_and_tax_transaction_details(): void
    {
        $this->makeTaxRule('SG-GST-9', 'output');
        $product = $this->makeProduct();
        $order = $this->makePosOrder($product, taxRate: 9.0);

        $entry = app(PosPostingService::class)->postPaidOrder($order, 109.00, 'cash');

        $this->assertNotNull($entry, 'A tax-bearing POS sale must pass the TaxGate.');

        $detail = DB::table('tax_transaction_details')->where('journal_entry_id', $entry->id)->first();
        $this->assertNotNull($detail, 'POS tax posting must write tax_transaction_details.');
        $this->assertSame('SG-GST-9', $detail->tax_code);
        $this->assertSame('pos_order', $detail->source_document_type);
        $this->assertSame('SG', $detail->jurisdiction);
        $this->assertSame(100.0, round((float) $detail->taxable_amount, 2));
        $this->assertSame(9.0, round((float) $detail->tax_amount, 2));

        $determination = TaxDeterminationResult::where('source_record_type', 'pos_order')
            ->where('source_record_id', $order->id)
            ->current()
            ->first();
        $this->assertNotNull($determination, 'POS posting must persist a versioned determination.');
        $this->assertSame(1, (int) $determination->result_version);
        $this->assertSame('SG', $determination->jurisdiction);

        $aggregate = app(TaxReturnAggregator::class)->aggregate($this->period);
        $posRows = array_values(array_filter($aggregate['output'], fn ($r) => $r['source_document_type'] === 'pos_order'));
        $this->assertCount(1, $posRows);
        $this->assertSame(9.0, (float) $posRows[0]['tax_amount']);
    }

    // ── D3: e-commerce web orders land on the return ──────────────────────

    public function test_taxed_web_order_posts_with_tax_identity_and_determination(): void
    {
        $this->makeTaxRule('SG-GST-9', 'output');
        $product = $this->makeProduct();

        $order = WebOrder::create([
            'order_number' => 'WO-'.Str::random(8),
            'email' => 'buyer@example.test',
            'customer_id' => Customer::create(['name' => 'Web Buyer', 'email' => 'web@example.test', 'is_active' => true])->id,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'paid_at' => now(),
            'subtotal' => 100,
            'tax_amount' => 9,
            'total' => 109,
        ]);

        WebOrderItem::create([
            'web_order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'T0 Product',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 9,
            'tax_amount' => 9,
            'line_total' => 109,
        ]);

        $entry = app(WebOrderPostingService::class)->postPaidOrder($order->load('items.product'), 109.00, 'card');

        $this->assertNotNull($entry, 'Web order with tax must post.');
        $detail = DB::table('tax_transaction_details')->where('journal_entry_id', $entry->id)->first();
        $this->assertNotNull($detail, 'Web order tax must be visible to statutory reporting.');
        $this->assertSame('web_order', $detail->source_document_type);
        $this->assertSame('SG-GST-9', $detail->tax_code);
        $this->assertSame(9.0, round((float) $detail->tax_amount, 2));
    }

    // ── D10: vendor bills persist versioned determinations too ────────────

    public function test_posted_bill_persists_determination_and_input_tax_details(): void
    {
        $this->makeTaxRule('SG-GST-9-IN', 'input');
        $product = $this->makeProduct();
        $bill = $this->makeBill($product, taxRate: 9.0, taxAmount: 9.0);

        $result = app(\App\Services\VendorBillService::class)->postBill($bill->load('lines.product', 'vendor.addresses'));

        $this->assertTrue($result->success, "Bill posting failed: {$result->error}");

        $determination = TaxDeterminationResult::where('source_record_type', 'bill')
            ->where('source_record_id', $bill->id)
            ->current()
            ->first();
        $this->assertNotNull($determination, 'MRD D10: bill determination must be persisted.');
        $this->assertSame('SG', $determination->jurisdiction);

        $detail = DB::table('tax_transaction_details')
            ->where('source_document_type', 'bill')
            ->where('source_document_id', $bill->id)
            ->first();
        $this->assertNotNull($detail);
        $this->assertSame('SG-GST-9-IN', $detail->tax_code);
        $this->assertSame(9.0, round((float) $detail->tax_amount, 2));

        $aggregate = app(TaxReturnAggregator::class)->aggregate($this->period);
        $billRows = array_values(array_filter($aggregate['input'], fn ($r) => $r['source_document_type'] === 'bill'));
        $this->assertCount(1, $billRows);
    }

    // ── D4: credit notes reverse tax with identity ────────────────────────

    public function test_customer_credit_note_reverses_invoice_tax_identity_and_nets_on_the_return(): void
    {
        $this->makeTaxRule('SG-GST-9', 'output');
        $product = $this->makeProduct();

        $customer = Customer::create(['name' => 'CN Customer', 'email' => 'cn@example.test', 'is_active' => true]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-'.Str::random(8),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'subtotal' => 100,
            'tax_amount' => 9,
            'discount_amount' => 0,
            'total' => 109,
            'paid_amount' => 0,
            'balance' => 109,
            'created_by' => $this->user->id,
        ]);
        $invoiceLine = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'T0 line',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 9,
            'tax_inclusive' => false,
            'discount_amount' => 0,
            'line_total' => 109,
            'tax_amount' => 9,
        ]);

        // The invoice was sold with a canonical determination (what
        // InvoiceService::postInvoice does; posted here directly to isolate
        // the credit-note leg).
        app(TaxDeterminationService::class)->determineForInvoice($invoice->fresh(['lines.product', 'customer']));

        $creditNote = CustomerCreditNote::create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_number' => 'CN-'.Str::random(8),
            'credit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 9,
            'total_amount' => 109,
            'reason' => 'Phase 0 tax identity test',
            'created_by' => $this->user->id,
        ]);
        CustomerCreditNoteLine::create([
            'credit_note_id' => $creditNote->id,
            'invoice_line_id' => $invoiceLine->id,
            'product_id' => $product->id,
            'description' => 'T0 reversed',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 9,
            'line_total' => 109,
        ]);

        $posted = app(\App\Services\CustomerCreditNoteService::class)->postCreditNote($creditNote);

        $this->assertSame('posted', $posted->status);

        $detail = DB::table('tax_transaction_details')
            ->where('source_document_type', 'credit_note')
            ->where('source_document_id', $creditNote->id)
            ->first();
        $this->assertNotNull($detail, 'MRD D4: credit-note tax reversal must write tax_transaction_details.');
        $this->assertSame('SG-GST-9', $detail->tax_code, 'The reversal must inherit the ORIGINAL invoice tax identity.');
        $this->assertSame(9.0, round((float) $detail->tax_amount, 2));

        // Statutory aggregation signs credit notes negative.
        $aggregate = app(TaxReturnAggregator::class)->aggregate($this->period);
        $cnRows = array_values(array_filter($aggregate['output'], fn ($r) => $r['source_document_type'] === 'credit_note'));
        $this->assertCount(1, $cnRows);
        $this->assertSame(-9.0, (float) $cnRows[0]['tax_amount']);
    }

    public function test_vendor_credit_note_reverses_input_tax_with_inherited_identity(): void
    {
        $this->makeTaxRule('SG-GST-9-IN', 'input');
        $product = $this->makeProduct();
        $bill = $this->makeBill($product, taxRate: 9.0, taxAmount: 9.0);

        $postResult = app(\App\Services\VendorBillService::class)->postBill($bill->load('lines.product', 'vendor.addresses'));
        $this->assertTrue($postResult->success, "Bill posting failed: {$postResult->error}");

        $billLine = $bill->lines()->first();

        $creditNote = VendorCreditNote::create([
            'vendor_id' => $bill->vendor_id,
            'bill_id' => $bill->id,
            'credit_note_number' => 'VCN-'.Str::random(8),
            'credit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 9,
            'total_amount' => 109,
            'reason' => 'Phase 0 input tax reversal test',
            'created_by' => $this->user->id,
        ]);
        VendorCreditNoteLine::create([
            'credit_note_id' => $creditNote->id,
            'bill_line_id' => $billLine->id,
            'product_id' => $product->id,
            'description' => 'T0 returned to vendor',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 9,
            'line_total' => 109,
        ]);

        $posted = app(\App\Services\VendorCreditNoteService::class)->postCreditNote($creditNote);
        $this->assertSame('posted', $posted->status);

        $detail = DB::table('tax_transaction_details')
            ->where('source_document_type', 'vendor_credit_note')
            ->where('source_document_id', $creditNote->id)
            ->first();
        $this->assertNotNull($detail, 'MRD D4: vendor credit-note reversal must write tax_transaction_details.');
        $this->assertSame('SG-GST-9-IN', $detail->tax_code, 'Identity must be inherited from the original bill determination.');

        $aggregate = app(TaxReturnAggregator::class)->aggregate($this->period);
        $vcnRows = array_values(array_filter($aggregate['input'], fn ($r) => $r['source_document_type'] === 'vendor_credit_note'));
        $this->assertCount(1, $vcnRows);
        $this->assertSame(-9.0, (float) $vcnRows[0]['tax_amount']);
    }

    public function test_vendor_debit_note_reverses_input_tax_with_inherited_identity(): void
    {
        $this->makeTaxRule('SG-GST-9-IN', 'input');
        $product = $this->makeProduct();
        $bill = $this->makeBill($product, taxRate: 9.0, taxAmount: 9.0);

        $postResult = app(\App\Services\VendorBillService::class)->postBill($bill->load('lines.product', 'vendor.addresses'));
        $this->assertTrue($postResult->success, "Bill posting failed: {$postResult->error}");

        $billLine = $bill->lines()->first();

        $debitNote = \App\Models\VendorDebitNote::create([
            'vendor_id' => $bill->vendor_id,
            'bill_id' => $bill->id,
            'debit_note_number' => 'VDN-'.Str::random(8),
            'debit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 9,
            'total_amount' => 109,
            'reason' => 'Phase 0 debit-note tax identity test',
            'created_by' => $this->user->id,
        ]);
        \App\Models\VendorDebitNoteLine::create([
            'debit_note_id' => $debitNote->id,
            'bill_line_id' => $billLine->id,
            'product_id' => $product->id,
            'description' => 'T0 debited to vendor',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 9,
        ]);

        $posted = app(\App\Services\VendorDebitNoteService::class)->postDebitNote($debitNote);
        $this->assertSame('posted', $posted->status);

        $detail = DB::table('tax_transaction_details')
            ->where('source_document_type', 'vendor_debit_note')
            ->where('source_document_id', $debitNote->id)
            ->first();
        $this->assertNotNull($detail, 'Debit-note tax reversal must write tax_transaction_details.');
        $this->assertSame('SG-GST-9-IN', $detail->tax_code, 'Identity inherited from the original bill determination.');

        $aggregate = app(TaxReturnAggregator::class)->aggregate($this->period);
        $dnRows = array_values(array_filter($aggregate['input'], fn ($r) => $r['source_document_type'] === 'vendor_debit_note'));
        $this->assertCount(1, $dnRows);
        $this->assertSame(-9.0, (float) $dnRows[0]['tax_amount']);
    }

    public function test_sales_default_rule_never_applies_to_purchase_side_resolution(): void
    {
        $rule = $this->makeTaxRule('SG-GST-9', 'output');
        $rule->forceFill(['is_default' => true])->save();
        \App\Models\Setting::setValue('tax', [
            'enabled' => true,
            'pricing_mode' => 'exclusive',
            'default_tax_id' => $rule->id,
            'jurisdiction' => 'SG',
        ]);

        $product = $this->makeProduct();
        $engine = app(TaxEngineService::class);

        $input = $engine->resolve($product, 'SG', 'input');
        $this->assertSame(0.0, (float) $input['rate'], 'MRD R2.2: an output default must never auto-tax a purchase.');
        $this->assertSame('none', $input['source']);

        $output = $engine->resolve($product, 'SG', 'output');
        $this->assertSame('tenant_default', $output['source']);
        $this->assertSame(9.0, (float) $output['rate']);
    }

    // ── MRD R10.1: engine API served under the accounting namespace ──────

    public function test_statutory_periods_endpoint_lists_periods_with_tax_activity(): void
    {
        $this->makeTaxRule('SG-GST-9', 'output');
        $order = $this->makePosOrder($this->makeProduct(), 9.0);
        app(PosPostingService::class)->postPaidOrder($order, 109.00, 'cash');

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/statutory-returns/periods');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('period', $this->period);
        $this->assertNotNull($row, 'A period with posted tax activity must be listed for the picker.');
        $this->assertGreaterThanOrEqual(9.0, (float) $row['output_tax']);
    }

    public function test_tax_engine_endpoints_are_live_under_accounting_namespace(): void
    {
        $this->makeTaxRule('SG-GST-9', 'output');

        $rules = $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/rules?jurisdiction=SG');
        $rules->assertOk()
            ->assertJsonPath('data.jurisdiction', 'SG')
            ->assertJsonPath('data.configured_rules.0.code', 'SG-GST-9');

        $advisory = $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/advisory?jurisdiction=SG&direction=output&amount=100');
        $advisory->assertOk()
            ->assertJsonPath('data.basis.rate', 9)
            ->assertJsonPath('data.calculation.tax', 9);

        // Query-string booleans: "1"/"0" must validate and actually toggle the
        // inclusive extraction path (regression: axios "true"/"false" strings
        // were rejected by nullable|boolean; switch state was never read).
        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/advisory?jurisdiction=SG&direction=output&amount=1000&inclusive=1')
            ->assertOk()
            ->assertJsonPath('data.calculation.gross', 1000)
            ->assertJsonPath('data.calculation.tax', 82.57);

        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/advisory?jurisdiction=SG&direction=output&amount=1000&inclusive=0')
            ->assertOk()
            ->assertJsonPath('data.calculation.gross', 1090)
            ->assertJsonPath('data.calculation.tax', 90);

        // Legacy axios boolean serialization ("true"/"false") must never 422.
        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/advisory?jurisdiction=SG&direction=output&amount=1000&inclusive=true')
            ->assertOk()
            ->assertJsonPath('data.calculation.gross', 1000);
        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/advisory?jurisdiction=SG&direction=output&amount=1000&inclusive=false')
            ->assertOk()
            ->assertJsonPath('data.calculation.gross', 1090);

        $unconfigured = $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/advisory?jurisdiction=DE&direction=output&amount=100');
        $unconfigured->assertOk()
            ->assertJsonPath('data.basis.source', 'none')
            ->assertJsonPath('data.basis.rate', 0);
    }

    // ── Storefront: postPaidOrder must not drop tax identity (audit gap A) ──

    public function test_taxed_storefront_order_posts_with_tax_identity_and_reaches_return(): void
    {
        $this->makeTaxRule('SG-GST-9', 'output');
        $product = $this->makeProduct();

        $order = \Modules\Storefront\Models\StorefrontOrder::create([
            'order_number' => 'SF-'.Str::random(8),
            'email' => 'shopper@example.test',
            'status' => 'paid',
            'payment_status' => 'paid',
            'subtotal' => 100,
            'tax_amount' => 9,
            'total' => 109,
            'paid_at' => now(),
        ]);
        \Modules\Storefront\Models\StorefrontOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'T0 SF',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 9,
            'tax_amount' => 9,
            'line_total' => 109,
        ]);

        $entry = app(\Modules\Storefront\Services\StorefrontPostingService::class)
            ->postPaidOrder($order, 109.00, 'card');

        $this->assertNotNull($entry);
        $detail = DB::table('tax_transaction_details')->where('journal_entry_id', $entry->id)->first();
        $this->assertNotNull($detail, 'MRD gap A: storefront tax must write tax_transaction_details.');
        $this->assertSame('storefront_order', $detail->source_document_type);
        $this->assertSame('SG-GST-9', $detail->tax_code);

        $aggregate = app(TaxReturnAggregator::class)->aggregate($this->period);
        $this->assertContains('storefront_order', array_column($aggregate['output'], 'source_document_type'));
    }

    // ── Accounting-origin bills: canonical tax identity (audit gap B) ─────

    public function test_accounting_origin_bill_books_input_tax_with_identity(): void
    {
        $this->makeTaxRule('SG-GST-9-IN', 'input');
        $expenseAccountId = \App\Models\AccountMapping::where('mapping_key', 'general_expense')->value('account_id');

        $bill = app(\App\Services\AccountingOriginBillService::class)->create(
            new \App\Accounting\DTOs\AccountingOriginBillData(
                vendorId: Vendor::factory()->create()->id,
                billType: 'expense',
                lines: [new \App\Accounting\DTOs\AccountingDocumentLineData(
                    lineType: 'expense',
                    description: 'Origin bill line',
                    quantity: 1,
                    unitPrice: 100.0,
                    glAccountId: (int) $expenseAccountId,
                    taxRate: 9.0,
                )],
                autoPost: true,
            ),
        );

        $this->assertSame('posted', $bill->status);

        $detail = DB::table('tax_transaction_details')
            ->where('source_document_type', 'bill')
            ->where('source_document_id', $bill->id)
            ->first();
        $this->assertNotNull($detail, 'MRD gap B: origin-bill input tax must reach tax_transaction_details.');
        $this->assertSame('SG-GST-9-IN', $detail->tax_code);

        $aggregate = app(TaxReturnAggregator::class)->aggregate($this->period);
        $this->assertCount(1, array_filter($aggregate['input'], fn ($r) => $r['source_document_type'] === 'bill'));
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    private function makePosOrder(Product $product, float $taxRate): PosOrder
    {
        $tax = round(100 * $taxRate / 100, 2);

        $order = PosOrder::create([
            'external_id' => Str::uuid()->toString(),
            'order_number' => 'POS-'.Str::random(8),
            'session_id' => $this->makePosSession(),
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => $tax,
            'total' => 100 + $tax,
            'created_by' => $this->user->id,
        ]);

        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'T0 Product',
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => $taxRate,
            'tax_amount' => $tax,
            'line_total' => 100 + $tax,
        ]);

        return $order->load('items.product', 'items.variant');
    }

    private function makeBill(Product $product, float $taxRate, float $taxAmount): Bill
    {
        $vendor = Vendor::factory()->create();

        $bill = Bill::create([
            'vendor_id' => $vendor->id,
            'bill_number' => 'BILL-'.Str::random(8),
            'bill_type' => 'expense',
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => $taxAmount,
            'discount_amount' => 0,
            'total' => 100 + $taxAmount,
            'paid_amount' => 0,
            'balance' => 100 + $taxAmount,
            'created_by' => $this->user->id,
        ]);

        BillLine::create([
            'bill_id' => $bill->id,
            'product_id' => $product->id,
            'description' => 'T0 bill line',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'line_total' => 100,
            'account_id' => \App\Models\AccountMapping::where('mapping_key', 'general_expense')->value('account_id'),
        ]);

        return $bill->fresh(['lines', 'vendor']);
    }
}
