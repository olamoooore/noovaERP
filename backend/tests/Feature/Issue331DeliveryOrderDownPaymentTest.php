<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #331 — gap found on re-verification (2026-10-07): invoicing a sales
 * order through its delivery orders after a down payment billed the customer
 * in full and left the deposit in the liability account. The delivery-order
 * invoice must carry the same "Less: down payment" deduction as the
 * order → invoice path, consume each deposit once across both paths, and
 * keep the books balanced.
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
class Issue331DeliveryOrderDownPaymentTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private Tax $gst;

    private ChartOfAccount $receivable;

    private ChartOfAccount $revenue;

    private ChartOfAccount $outputTax;

    private Warehouse $warehouse;

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

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.delivery_order.default_requires_approval', false);

        $this->warehouse = Warehouse::create([
            'name' => 'I331 DO Warehouse',
            'code' => 'I331W-'.Str::upper(Str::random(5)),
            'is_active' => true,
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
     * Whole order delivered on one DO, invoiced from the DO: the deduction
     * is on the DO invoice, the deposit nets to zero and the books balance.
     */
    public function test_delivery_order_invoice_deducts_the_posted_down_payment(): void
    {
        $order = $this->confirmedOrder();
        $rate = (float) $this->gst->rate;
        $orderNet = 1000.0;
        $orderTax = round($orderNet * $rate / 100, 2);

        [$dp, $dpLine] = $this->postedDownPayment($order, 30);
        $dpNet = 300.0;
        $dpTax = round($dpNet * $rate / 100, 2);

        $deliveryOrder = $this->completedDeliveryOrder($order, 2);
        $final = $this->invoiceDeliveryOrder($deliveryOrder);

        $deduction = $final->lines->firstWhere('down_payment_invoice_id', $dp->id);
        $this->assertNotNull($deduction, 'The delivery-order invoice carries a deduction line for the down payment.');
        $this->assertSame("Less: down payment {$dp->invoice_number}", $deduction->description);
        $this->assertEqualsWithDelta(-$dpNet, (float) $deduction->line_total - (float) $deduction->tax_amount, 0.001);
        $this->assertEqualsWithDelta(-$dpTax, (float) $deduction->tax_amount, 0.001, 'Tax on the deduction is reversed.');
        $this->assertSame((int) $dpLine->tax_rule_id, (int) $deduction->tax_rule_id, 'Same tax identity as the down payment.');
        $this->assertSame($dpLine->tax_code, $deduction->tax_code);
        $this->assertEqualsWithDelta($orderNet + $orderTax - $dpNet - $dpTax, (float) $final->total, 0.001, 'Invoice total = delivered value - deposit.');

        $this->postInvoice($final->id);

        $deposit = $this->depositAccount();
        $finalEntry = $this->entryLines($final->id);
        $this->assertEqualsWithDelta($dpNet, $this->sumOn($finalEntry, $deposit->id, 'debit'), 0.001, 'Dr Customer Deposit for the deduction.');
        $this->assertEqualsWithDelta($orderNet, $this->sumOn($finalEntry, $this->revenue->id, 'credit'), 0.001, 'Revenue is the full delivered net.');
        $this->assertEqualsWithDelta((float) $final->total, $this->sumOn($finalEntry, $this->receivable->id, 'debit'), 0.001);
        $this->assertEqualsWithDelta($finalEntry->sum('debit'), $finalEntry->sum('credit'), 0.001);

        $this->assertBooksBalance($orderNet, $orderTax);
    }

    /**
     * Partial delivery invoicing mirrors the order -> invoice rule: each
     * invoice deducts what is left on the deposit, capped at its own goods
     * net. A 60% deposit (600) over two 500-net deliveries: 500 then 100.
     * The deposit is consumed once.
     */
    public function test_partial_delivery_invoices_consume_the_deposit_once(): void
    {
        $order = $this->confirmedOrder();
        $rate = (float) $this->gst->rate;
        [$dp] = $this->postedDownPayment($order, 60);

        $first = $this->invoiceDeliveryOrder($this->completedDeliveryOrder($order, 1));
        $this->assertEqualsWithDelta(-500.0, $this->deductedNet($first, $dp->id), 0.001, 'Capped at this invoice\'s goods net.');
        $this->assertEqualsWithDelta(0.0, (float) $first->total, 0.001);
        $this->postInvoice($first->id);

        $second = $this->invoiceDeliveryOrder($this->completedDeliveryOrder($order, 1));
        $this->assertEqualsWithDelta(-100.0, $this->deductedNet($second, $dp->id), 0.001, 'Only what is left on the deposit.');
        $this->assertEqualsWithDelta(400.0 + round(400.0 * $rate / 100, 2), (float) $second->total, 0.001);
        $this->postInvoice($second->id);

        $this->assertBooksBalance(1000.0, round(1000.0 * $rate / 100, 2));
    }

    /**
     * Mixed paths on the same order: a DO invoice first, then the order ->
     * invoice action for the remainder. Neither path re-deducts what the
     * other already consumed.
     */
    public function test_deposit_is_consumed_once_across_delivery_and_order_invoicing(): void
    {
        $order = $this->confirmedOrder();
        $rate = (float) $this->gst->rate;
        [$dp] = $this->postedDownPayment($order, 60);

        $doInvoice = $this->invoiceDeliveryOrder($this->completedDeliveryOrder($order, 1));
        $this->assertEqualsWithDelta(-500.0, $this->deductedNet($doInvoice, $dp->id), 0.001);
        $this->postInvoice($doInvoice->id);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/sales/orders/{$order->id}/invoice");
        $response->assertStatus(201, $response->getContent());
        $soInvoice = Invoice::with('lines')->findOrFail((int) $response->json('invoice.id'));
        $this->assertEqualsWithDelta(-100.0, $this->deductedNet($soInvoice, $dp->id), 0.001, 'The order path deducts only the remaining 100.');
        $this->postInvoice($soInvoice->id);

        $this->assertBooksBalance(1000.0, round(1000.0 * $rate / 100, 2));
    }

    private function postedDownPayment(SalesOrder $order, float $percentage): array
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/down-payment-invoice", [
                'method' => 'percentage',
                'percentage' => $percentage,
            ]);
        $response->assertStatus(201, $response->getContent());

        $dp = Invoice::with('lines')->findOrFail((int) $response->json('invoice.id'));
        $this->postInvoice($dp->id);

        return [$dp->fresh('lines'), $dp->lines->firstWhere('line_type', 'product')];
    }

    private function completedDeliveryOrder(SalesOrder $order, float $quantity): DeliveryOrder
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'lines' => [[
                'sales_order_line_id' => $order->lines()->first()->id,
                'quantity_delivered' => $quantity,
            ]],
        ]);
        $response->assertStatus(201, $response->getContent());
        $id = (int) $response->json('data.id');

        $confirm = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/sales/delivery-orders/{$id}/confirm");
        $confirm->assertSuccessful();
        $complete = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/sales/delivery-orders/{$id}/complete");
        $this->assertTrue($complete->isSuccessful(), (string) $complete->getContent());

        $deliveryOrder = DeliveryOrder::findOrFail($id);
        $this->assertSame('done', $deliveryOrder->status);

        return $deliveryOrder;
    }

    private function invoiceDeliveryOrder(DeliveryOrder $deliveryOrder): Invoice
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/create-invoice");
        $response->assertStatus(201, $response->getContent());

        return Invoice::with('lines')->findOrFail((int) $response->json('data.id'));
    }

    private function deductedNet(Invoice $invoice, int $downPaymentId): float
    {
        return round((float) $invoice->lines
            ->where('down_payment_invoice_id', $downPaymentId)
            ->sum(fn ($line) => (float) $line->line_total - (float) $line->tax_amount), 2);
    }

    /** Trial balance, deposit, revenue, tax, and AR subledger == ledger. */
    private function assertBooksBalance(float $orderNet, float $orderTax): void
    {
        $all = DB::table('journal_entry_lines')->get();
        $this->assertEqualsWithDelta((float) $all->sum('debit'), (float) $all->sum('credit'), 0.001, 'Trial balance: debits = credits.');

        $this->assertEqualsWithDelta(0.0, $this->balance($this->depositAccount()->id), 0.001, 'Customer Deposit nets to zero once fully deducted.');
        $this->assertEqualsWithDelta(-$orderNet, $this->balance($this->revenue->id), 0.001, 'Revenue = full order net (not net of the deposit, not doubled).');
        $this->assertEqualsWithDelta(-$orderTax, $this->balance($this->outputTax->id), 0.001, 'Output tax = full order tax.');

        $arLedger = $this->balance($this->receivable->id);
        $arSubledger = (float) Invoice::query()
            ->where('customer_id', $this->customer->id)
            ->where('status', '!=', 'cancelled')
            ->sum('balance');
        $this->assertEqualsWithDelta($orderNet + $orderTax, $arLedger, 0.001, 'AR = everything still owed (nothing paid), not deposit + full bill.');
        $this->assertEqualsWithDelta($arSubledger, $arLedger, 0.001, 'AR subledger == AR ledger.');
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
