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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #327 regression (found 2026-10-07 re-verification): every invoice
 * raised from a sales order carries the order's PO number and customer
 * reference, so the 2nd+ invoice of a staged / down-payment + final flow
 * matched the 1st as a LIKELY duplicate, and since #344 pre-flight refused to
 * post it (the confirmation can only be recorded on POST /invoices).
 *
 * Business rule: invoices raised from the same sales order (or the same
 * quotation) are parts of one sale, not duplicates of each other. A direct
 * invoice for the same customer with the same PO number is still a likely
 * duplicate of them.
 *
 * Router-level: every step goes through the real HTTP routes and the real
 * PostingEngine (setup mirrors Issue331DownPaymentInvoiceTest).
 */
class Issue327StagedInvoicingNotDuplicateTest extends TenantTestCase
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
            'name' => 'Issue 327 Staged Invoicing Tenant',
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
        $this->receivable = $this->account('I327S-1100', 'I327S Accounts Receivable', 'asset');
        $this->revenue = $this->account('I327S-4100', 'I327S Sales Revenue', 'revenue');
        $this->outputTax = $this->account('I327S-2200', 'I327S Output Tax', 'liability');

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
            'name' => 'I327S Installation Project',
            'sku' => 'I327S-'.Str::upper(Str::random(6)),
            'price' => 500,
            'cost' => 0,
            'track_inventory' => false,
        ]);
    }

    public function test_down_payments_and_final_invoice_from_one_order_all_post(): void
    {
        $order = $this->confirmedOrderWithReferences();

        $firstDp = $this->downPayment($order, 30);
        $this->assertSame('PO-327-STAGED', Invoice::findOrFail($firstDp)->po_number, 'The order PO travels onto its invoices.');
        $this->postInvoice($firstDp);

        // Second staged down payment: same order, same PO/reference.
        $secondDp = $this->downPayment($order, 30);
        $row = $this->preflightDuplicateRow($secondDp);
        $this->assertNotSame('fail', $row['status'], 'An invoice from the same order is not a likely duplicate of its sibling: '.$row['detail']);
        $this->postInvoice($secondDp);

        // Final invoice: the remainder of the same order.
        $final = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/sales/orders/{$order->id}/invoice");
        $final->assertStatus(201, $final->getContent());
        $finalId = (int) $final->json('invoice.id');
        $this->assertSame('PO-327-STAGED', Invoice::findOrFail($finalId)->po_number);

        $row = $this->preflightDuplicateRow($finalId);
        $this->assertNotSame('fail', $row['status'], 'Final invoice of the same order must not fail the duplicate check: '.$row['detail']);
        $this->assertNotSame('Likely duplicate', $row['label'], $row['detail']);

        $this->postInvoice($finalId);
        $this->assertNotNull(Invoice::findOrFail($finalId)->journal_entry_id, 'The final invoice reaches the ledger.');

        $all = DB::table('journal_entry_lines')->get();
        $this->assertEqualsWithDelta((float) $all->sum('debit'), (float) $all->sum('credit'), 0.001, 'Sum of debits = sum of credits.');
    }

    public function test_a_direct_invoice_with_the_same_po_is_still_a_likely_duplicate_of_order_invoices(): void
    {
        $order = $this->confirmedOrderWithReferences();
        $dp = $this->downPayment($order, 30);
        $dpNumber = Invoice::findOrFail($dp)->invoice_number;

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'po_number' => ' po-327-staged ',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Re-keyed invoice',
                'quantity' => 1,
                'unit_price' => 77,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_INVOICE_LIKELY')
            ->assertJsonPath('duplicate_check.level', 'likely')
            ->assertJsonPath('duplicate_check.matches.0.invoice_number', $dpNumber);
        $this->assertContains('po_number', $response->json('duplicate_check.matches.0.matched_on'));
    }

    public function test_invoices_from_another_order_with_the_same_po_are_still_flagged(): void
    {
        $first = $this->confirmedOrderWithReferences();
        $firstDp = $this->downPayment($first, 30);
        $this->postInvoice($firstDp);

        // A second order for the same customer re-using the PO is a
        // different source: its invoice is a likely duplicate.
        $second = $this->confirmedOrderWithReferences();
        $secondDp = $this->downPayment($second, 30);

        $row = $this->preflightDuplicateRow($secondDp);
        $this->assertSame('fail', $row['status'], 'Same PO on another order is still a likely duplicate.');
        $this->assertStringContainsString(Invoice::findOrFail($firstDp)->invoice_number, $row['detail']);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function confirmedOrderWithReferences(): SalesOrder
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'customer_reference' => 'CUST-REF-327',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Installation milestone',
                'quantity' => 2,
                'unit_price' => 500,
                'tax_rule_id' => $this->gst->id,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated($response->getContent());
        $order = SalesOrder::findOrFail((int) $response->json('id'));

        // Fixture: POST /sales/orders has no po_number input (orders take it
        // from their source documents), so it is set on the row directly.
        DB::table('sales_orders')->where('id', $order->id)->update(['po_number' => 'PO-327-STAGED']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm")
            ->assertOk();

        return $order->fresh();
    }

    private function downPayment(SalesOrder $order, int $percentage): int
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/sales/orders/{$order->id}/down-payment-invoice", [
                'method' => 'percentage',
                'percentage' => $percentage,
            ]);
        $response->assertStatus(201, $response->getContent());

        return (int) $response->json('invoice.id');
    }

    private function postInvoice(int $invoiceId): void
    {
        $post = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/invoices/{$invoiceId}/post");
        $post->assertOk($post->getContent());
    }

    private function preflightDuplicateRow(int $invoiceId): array
    {
        return collect($this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/invoices/{$invoiceId}/preflight")
            ->assertOk()
            ->json('checks'))->keyBy('key')['duplicate'];
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
