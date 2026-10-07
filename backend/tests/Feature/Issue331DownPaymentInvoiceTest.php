<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #331 — down-payment invoices from a sales order / confirmed quotation
 * (Odoo parity), deducted on the final invoice.
 *
 * Decision (7 Oct 2026, product owner):
 *  - a `customer_deposit` account-mapping key (liability) + account role;
 *    MY template default 221200, other tenants a seeded liability account;
 *  - the down-payment invoice posts Dr AR / Cr Customer Deposit (+ Cr Output
 *    Tax where the tax engine returns tax) through PostingEngine; amount is a
 *    percentage of, or a fixed amount from, the order total;
 *  - the final invoice carries a negative "Less: down payment <number>" line
 *    posting Dr Customer Deposit / Cr AR, tax reversed with the same tax
 *    identity as the down-payment invoice.
 *
 * Every step goes through the real HTTP routes and the real PostingEngine.
 * The tax rule is the SG country pack's own row (config/tax_packs.php) — no
 * rate is invented here.
 */
class Issue331DownPaymentInvoiceTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private Tax $gst;

    private ChartOfAccount $receivable;

    private ChartOfAccount $revenue;

    private ChartOfAccount $outputTax;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantId = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantId,
            'name' => 'Issue 331 Down Payment Tenant',
            'email' => $tenantId.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->admin->id,
            ]
        );

        // Hermetic, distinct accounts so every ledger figure below is read
        // from exactly one account.
        $this->receivable = $this->account('I331-1100', 'I331 Accounts Receivable', 'asset');
        $this->revenue = $this->account('I331-4100', 'I331 Sales Revenue', 'revenue');
        $this->outputTax = $this->account('I331-2200', 'I331 Output Tax', 'liability');

        DB::table('account_mappings')->whereIn('mapping_key', ['accounts_receivable', 'sales_revenue', 'sales_tax_payable', 'customer_deposit'])->delete();
        foreach ([
            'accounts_receivable' => $this->receivable->id,
            'sales_revenue' => $this->revenue->id,
            'sales_tax_payable' => $this->outputTax->id,
        ] as $key => $accountId) {
            DB::table('account_mappings')->insert([
                'mapping_key' => $key,
                'account_id' => $accountId,
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The SG pack's current standard GST row, exactly as the pack declares it.
        $rule = collect((array) config('tax_packs.SG.rules', []))->firstWhere('code', 'SG-GST-9');
        $this->assertNotNull($rule, 'The SG pack must provide the GST 9 row.');
        $this->gst = Tax::create([
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
            'is_default' => true,
        ]);
        Setting::setValue('tax', [
            'enabled' => true,
            'pricing_mode' => 'exclusive',
            'default_tax_id' => $this->gst->id,
            'jurisdiction' => 'SG',
        ]);

        $this->customer = Customer::factory()->create([
            'legal_entity_id' => null,
            'payment_terms' => 30,
            'credit_limit' => null,
            'total_outstanding' => 0,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'I331 Installation Project',
            'sku' => 'I331-'.Str::upper(Str::random(6)),
            'price' => 500,
            'cost' => 0,
            'track_inventory' => false,
        ]);
    }

    /**
     * The full cycle: 30% down payment, then the final invoice deducts it.
     * After both are posted the deposit nets to zero, AR holds exactly what
     * the customer still owes, revenue is the full order net and the books
     * balance (AR subledger == AR ledger).
     */
    public function test_down_payment_posts_to_customer_deposit_and_the_final_invoice_deducts_it(): void
    {
        $order = $this->confirmedOrder();
        $rate = (float) $this->gst->rate;
        $orderNet = 1000.0;
        $orderTax = round($orderNet * $rate / 100, 2);
        $this->assertEqualsWithDelta($orderNet + $orderTax, (float) $order->total, 0.001);

        // ── Down-payment invoice: 30% of the order ─────────────────────
        $dpResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/down-payment-invoice", [
                'method' => 'percentage',
                'percentage' => 30,
            ]);
        $dpResponse->assertStatus(201, $dpResponse->getContent());

        $dpId = (int) $dpResponse->json('invoice.id');
        $dp = Invoice::with('lines')->findOrFail($dpId);
        $dpNet = round($orderNet * 0.30, 2);
        $dpTax = round($dpNet * $rate / 100, 2);

        $this->assertTrue((bool) $dp->is_down_payment, 'The invoice is flagged as a down payment.');
        $this->assertSame((int) $order->id, (int) $dp->sales_order_id, 'The down payment is linked to its order.');
        $this->assertEqualsWithDelta($dpNet, (float) $dp->subtotal, 0.001);
        $this->assertEqualsWithDelta($dpTax, (float) $dp->tax_amount, 0.001, 'Tax comes from the existing engine (the order line\'s GST rule).');
        $this->assertEqualsWithDelta($dpNet + $dpTax, (float) $dp->total, 0.001);
        $dpLine = $dp->lines->firstWhere('line_type', 'product');
        $this->assertSame((int) $this->gst->id, (int) $dpLine->tax_rule_id, 'The down-payment line carries the GST rule identity.');

        $this->postInvoice($dpId);

        $deposit = $this->depositAccount();
        $this->assertSame('liability', $deposit->type, 'Customer Deposit is a liability account.');

        $dpEntry = $this->entryLines($dpId);
        $this->assertEqualsWithDelta($dpNet + $dpTax, $this->sumOn($dpEntry, $this->receivable->id, 'debit'), 0.001, 'Dr AR for the down payment.');
        $this->assertEqualsWithDelta($dpNet, $this->sumOn($dpEntry, $deposit->id, 'credit'), 0.001, 'Cr Customer Deposit for the net down payment.');
        $this->assertEqualsWithDelta($dpTax, $this->sumOn($dpEntry, $this->outputTax->id, 'credit'), 0.001, 'Cr Output Tax on the down payment.');
        $this->assertEqualsWithDelta(0.0, $this->sumOn($dpEntry, $this->revenue->id, 'credit'), 0.001, 'A down payment is not revenue.');
        $this->assertEqualsWithDelta($dpEntry->sum('debit'), $dpEntry->sum('credit'), 0.001);

        // ── Final invoice: the existing order → invoice action ─────────
        $finalResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/invoice");
        $finalResponse->assertStatus(201, $finalResponse->getContent());

        $finalId = (int) $finalResponse->json('invoice.id');
        $final = Invoice::with('lines')->findOrFail($finalId);
        $deduction = $final->lines->firstWhere('down_payment_invoice_id', $dpId);

        $this->assertNotNull($deduction, 'The final invoice carries a deduction line for the down payment.');
        $this->assertSame("Less: down payment {$dp->invoice_number}", $deduction->description);
        $this->assertEqualsWithDelta(-$dpNet, (float) $deduction->line_total - (float) $deduction->tax_amount, 0.001);
        $this->assertEqualsWithDelta(-$dpTax, (float) $deduction->tax_amount, 0.001, 'Tax on the deduction is reversed.');
        $this->assertSame((int) $dpLine->tax_rule_id, (int) $deduction->tax_rule_id, 'Same tax identity as the down payment.');
        $this->assertSame($dpLine->tax_code, $deduction->tax_code);
        $this->assertEqualsWithDelta($orderNet + $orderTax - $dpNet - $dpTax, (float) $final->total, 0.001);

        $this->postInvoice($finalId);

        $finalEntry = $this->entryLines($finalId);
        $this->assertEqualsWithDelta($dpNet, $this->sumOn($finalEntry, $deposit->id, 'debit'), 0.001, 'Dr Customer Deposit for the deduction.');
        $this->assertEqualsWithDelta($orderNet, $this->sumOn($finalEntry, $this->revenue->id, 'credit'), 0.001, 'Revenue is the full order net on the final invoice.');
        $this->assertEqualsWithDelta((float) $final->total, $this->sumOn($finalEntry, $this->receivable->id, 'debit'), 0.001);
        $this->assertEqualsWithDelta($finalEntry->sum('debit'), $finalEntry->sum('credit'), 0.001);

        // ── After both ─────────────────────────────────────────────────
        $all = DB::table('journal_entry_lines')->get();
        $this->assertEqualsWithDelta((float) $all->sum('debit'), (float) $all->sum('credit'), 0.001, 'Sum of debits = sum of credits.');

        $depositBalance = $this->balance($deposit->id);
        $this->assertEqualsWithDelta(0.0, $depositBalance, 0.001, 'Customer Deposit nets to zero.');
        $this->assertEqualsWithDelta(-$orderNet, $this->balance($this->revenue->id), 0.001, 'Revenue = full order net.');
        $this->assertEqualsWithDelta(-$orderTax, $this->balance($this->outputTax->id), 0.001, 'Output tax = full order tax.');

        $arLedger = $this->balance($this->receivable->id);
        $arSubledger = (float) Invoice::query()
            ->where('customer_id', $this->customer->id)
            ->where('status', '!=', 'cancelled')
            ->sum('balance');
        $this->assertEqualsWithDelta($orderNet + $orderTax, $arLedger, 0.001, 'AR = the amounts still owed (the whole order, nothing paid yet).');
        $this->assertEqualsWithDelta($arSubledger, $arLedger, 0.001, 'AR subledger == AR ledger.');
    }

    /** A fixed amount is a share of the order total (tax included). */
    public function test_fixed_amount_down_payment(): void
    {
        $order = $this->confirmedOrder();
        $rate = (float) $this->gst->rate;
        $amount = round(2 * (100 + $rate), 2); // gross of 200 net at the pack rate

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/down-payment-invoice", [
                'method' => 'fixed',
                'amount' => $amount,
            ]);
        $response->assertStatus(201, $response->getContent());

        $dp = Invoice::findOrFail((int) $response->json('invoice.id'));
        $this->assertEqualsWithDelta(200.0, (float) $dp->subtotal, 0.001);
        $this->assertEqualsWithDelta($amount, (float) $dp->total, 0.001);
    }

    /** Down payments can never exceed the order total. */
    public function test_down_payments_cannot_exceed_the_order_total(): void
    {
        $order = $this->confirmedOrder();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/down-payment-invoice", ['method' => 'percentage', 'percentage' => 60])
            ->assertStatus(201);

        $over = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/down-payment-invoice", ['method' => 'percentage', 'percentage' => 50]);
        $over->assertStatus(422);

        $tooMuch = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/down-payment-invoice", ['method' => 'fixed', 'amount' => 999999]);
        $tooMuch->assertStatus(422);

        $this->assertSame(1, Invoice::query()->where('is_down_payment', true)->count());
    }

    /** A draft order cannot take a down payment. */
    public function test_a_draft_order_cannot_take_a_down_payment(): void
    {
        $order = $this->draftOrder();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/down-payment-invoice", ['method' => 'percentage', 'percentage' => 10])
            ->assertStatus(422);
    }

    /**
     * Only a POSTED down payment is deducted, and posting refuses a deduction
     * larger than what is still left on the down payment.
     */
    public function test_deduction_never_exceeds_the_down_payment(): void
    {
        $order = $this->confirmedOrder();

        $dpId = (int) $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/down-payment-invoice", ['method' => 'percentage', 'percentage' => 30])
            ->assertStatus(201)
            ->json('invoice.id');
        $this->postInvoice($dpId);

        $finalId = (int) $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/invoice")
            ->assertStatus(201)
            ->json('invoice.id');

        // Inflate the deduction beyond the 300 down-paid (as a stale or
        // hand-edited draft would) — posting must refuse it.
        $line = DB::table('invoice_lines')->where('invoice_id', $finalId)->where('down_payment_invoice_id', $dpId)->first();
        $this->assertNotNull($line);
        DB::table('invoice_lines')->where('id', $line->id)->update(['unit_price' => -400, 'line_total' => -400 - (float) $line->tax_amount]);

        $post = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/invoices/{$finalId}/post");
        $this->assertGreaterThanOrEqual(400, $post->status(), $post->getContent());
        $this->assertStringContainsString('remains on it', (string) $post->getContent(), 'Refused by the down-payment guard.');
        $this->assertNull(Invoice::findOrFail($finalId)->journal_entry_id, 'An over-deduction must not reach the ledger.');
    }

    /** From a confirmed quotation: the down payment is raised on its order. */
    public function test_down_payment_from_a_confirmed_quotation(): void
    {
        $quotation = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'lines' => [$this->line()],
        ]);
        $quotation->assertCreated($quotation->getContent());
        $quotationId = (int) $quotation->json('data.id');

        $draftAttempt = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/down-payment-invoice", ['method' => 'percentage', 'percentage' => 20]);
        $draftAttempt->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/quotations/{$quotationId}/confirm")->assertOk();
        $orderId = (int) Quotation::findOrFail($quotationId)->sales_order_id;
        $this->assertGreaterThan(0, $orderId);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/down-payment-invoice", ['method' => 'percentage', 'percentage' => 20]);
        $response->assertStatus(201, $response->getContent());

        $dp = Invoice::findOrFail((int) $response->json('invoice.id'));
        $this->assertTrue((bool) $dp->is_down_payment);
        $this->assertSame($orderId, (int) $dp->sales_order_id);
        $this->assertEqualsWithDelta(200.0, (float) $dp->subtotal, 0.001);
    }

    /** MY template: the decision's default deposit account is 221200. */
    public function test_my_template_maps_customer_deposit_to_receipt_in_advance(): void
    {
        DB::table('account_mappings')->delete();
        DB::table('settings')->where('key', 'accounting.coa_template')->delete();

        (new \Database\Seeders\MalaysiaCountrySeeder)->run();

        $mapped = DB::table('account_mappings')->where('mapping_key', 'customer_deposit')->whereNull('document_type')->first();
        $this->assertNotNull($mapped, 'The MY template maps customer_deposit.');
        $this->assertSame('221200', DB::table('chart_of_accounts')->where('id', $mapped->account_id)->value('code'));
        $this->assertTrue(DB::table('account_roles')->where('code', 'CUSTOMER_DEPOSIT')->exists(), 'The CUSTOMER_DEPOSIT account role exists.');
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function line(): array
    {
        return [
            'product_id' => $this->product->id,
            'description' => 'Installation milestone',
            'quantity' => 2,
            'unit_price' => 500,
            'tax_rule_id' => $this->gst->id,
            'discount_amount' => 0,
        ];
    }

    private function draftOrder(): SalesOrder
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'lines' => [$this->line()],
        ]);
        $response->assertCreated($response->getContent());

        return SalesOrder::findOrFail((int) $response->json('id'));
    }

    private function confirmedOrder(): SalesOrder
    {
        $order = $this->draftOrder();

        $confirm = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/sales/orders/{$order->id}/confirm");
        $confirm->assertOk($confirm->getContent());

        return $order->fresh();
    }

    private function postInvoice(int $invoiceId): TestResponse
    {
        $post = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/invoices/{$invoiceId}/post");
        $post->assertOk($post->getContent());

        return $post;
    }

    private function entryLines(int $invoiceId): \Illuminate\Support\Collection
    {
        $journalEntryId = Invoice::findOrFail($invoiceId)->journal_entry_id;
        $this->assertNotNull($journalEntryId);

        return DB::table('journal_entry_lines')->where('journal_entry_id', $journalEntryId)->get();
    }

    private function sumOn(\Illuminate\Support\Collection $lines, int $accountId, string $side): float
    {
        return (float) $lines->where('account_id', $accountId)->sum($side);
    }

    /** Debit-positive balance of an account over every posted line. */
    private function balance(int $accountId): float
    {
        $row = DB::table('journal_entry_lines')
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS bal')
            ->first();

        return round((float) $row->bal, 2);
    }

    private function depositAccount(): ChartOfAccount
    {
        $mapping = DB::table('account_mappings')->where('mapping_key', 'customer_deposit')->whereNull('document_type')->first();
        $this->assertNotNull($mapping, 'customer_deposit resolves through the mapping defaults.');

        return ChartOfAccount::findOrFail($mapping->account_id);
    }

    private function account(string $code, string $name, string $type): ChartOfAccount
    {
        return ChartOfAccount::factory()->create([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'is_active' => true,
        ]);
    }
}
