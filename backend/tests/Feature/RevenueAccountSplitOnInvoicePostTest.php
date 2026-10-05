<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * Issue #876 (SA2) — invoice revenue was posted as ONE `sales_revenue` role
 * line for the whole document, so `products.income_account_id`,
 * `sales_order_lines.income_account_id` and the line's own GL override were
 * never read, and the line's project/department dimensions never reached the
 * journal.
 *
 * Everything below goes through the real routes (create → post), not a
 * service instantiated by the test.
 */
class RevenueAccountSplitOnInvoicePostTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private ChartOfAccount $defaultRevenue;

    private ChartOfAccount $servicesRevenue;

    private ChartOfAccount $goodsRevenue;

    private ChartOfAccount $receivable;

    private Product $serviceProduct;

    private Product $goodsProduct;

    private int $legalEntityId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->admin = User::factory()->create(['email' => 'sa2-'.uniqid().'@example.local']);
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        CompanySettings::query()->create([
            'company_name' => 'SA2 Revenue Split Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $entityGroupId = DB::table('entity_groups')->insertGetId([
            'name' => 'SA2 Group',
            'code' => 'SA2-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->legalEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $entityGroupId,
            'name' => 'SA2 Entity',
            'code' => 'SA2-E-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'legal_entity_id' => $this->legalEntityId,
            'payment_terms' => 30,
        ]);

        $this->defaultRevenue = $this->revenueAccount('SA2-4100', 'SA2 Default Sales Revenue');
        $this->servicesRevenue = $this->revenueAccount('SA2-4110', 'SA2 Services Income');
        $this->goodsRevenue = $this->revenueAccount('SA2-4120', 'SA2 Goods Income');
        $this->receivable = ChartOfAccount::factory()->create([
            'code' => 'SA2-1240',
            'name' => 'SA2 Accounts Receivable',
            'type' => 'asset',
            'is_active' => true,
        ]);

        // Deterministic role → account wiring for this fixture (the tenant
        // migrations may auto-seed several NULL-document_type rows).
        foreach (['accounts_receivable' => $this->receivable->id, 'sales_revenue' => $this->defaultRevenue->id] as $key => $accountId) {
            DB::table('account_mappings')->where('mapping_key', $key)->delete();
            DB::table('account_mappings')->insert([
                'mapping_key' => $key,
                'account_id' => $accountId,
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->serviceProduct = Product::factory()->create([
            'name' => 'SA2 Consulting Hours',
            'sku' => 'SA2-SRV-'.Str::upper(Str::random(5)),
            'price' => 100,
            'cost' => 0,
            'track_inventory' => false,
            'income_account_id' => $this->servicesRevenue->id,
        ]);

        $this->goodsProduct = Product::factory()->create([
            'name' => 'SA2 Hardware Widget',
            'sku' => 'SA2-GDS-'.Str::upper(Str::random(5)),
            'price' => 50,
            'cost' => 20,
            'track_inventory' => false,
            'income_account_id' => $this->goodsRevenue->id,
        ]);

        DB::table('currencies')->updateOrInsert(
            ['code' => 'MYR'],
            ['name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('currencies')->updateOrInsert(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        \App\Models\ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'MYR',
            'rate' => 1.3527,
            'effective_date' => now()->subDay()->toDateString(),
            'source' => 'manual',
        ]);
    }

    /**
     * AC: "an invoice with two products mapped to different income accounts
     * posts two revenue lines; a department filter on the P&L returns the
     * line." The header discount is allocated per line, so the two revenue
     * lines still add up to subtotal − discount.
     */
    public function test_two_products_with_different_income_accounts_post_two_revenue_lines(): void
    {
        $invoice = $this->createInvoice([
            $this->serviceProduct->id => ['description' => 'Consulting', 'price' => 100],
            $this->goodsProduct->id => ['description' => 'Hardware', 'price' => 50],
        ], discount: 15.0);

        $departmentId = $this->createDepartment();
        $servicesLine = $invoice->lines()->where('product_id', $this->serviceProduct->id)->firstOrFail();
        $servicesLine->forceFill(['department_id' => $departmentId])->save();

        $this->postInvoice($invoice);

        $invoice = Invoice::with('journalEntry.lines')->findOrFail($invoice->id);
        $lines = $invoice->journalEntry->lines;

        $servicesPosted = $lines->where('account_id', $this->servicesRevenue->id)->values();
        $goodsPosted = $lines->where('account_id', $this->goodsRevenue->id)->values();
        $defaultPosted = $lines->where('account_id', $this->defaultRevenue->id)->values();

        $this->assertCount(1, $servicesPosted, 'The service product must get its own revenue line.');
        $this->assertEqualsWithDelta(90.0, (float) $servicesPosted->first()->credit, 0.001,
            'The header discount must be allocated per line (100 of 150 net → 90 after the 15 discount).');

        $this->assertCount(1, $goodsPosted, 'The goods product must get its own revenue line.');
        $this->assertEqualsWithDelta(45.0, (float) $goodsPosted->first()->credit, 0.001);

        $this->assertSame([], $defaultPosted->all(),
            'The default sales_revenue account must not receive revenue when every line names its own income account.');

        // The entry still balances and AR carries the whole document total.
        $this->assertEqualsWithDelta(135.0, (float) $lines->where('account_id', $this->receivable->id)->sum('debit'), 0.001);
        $debits = (float) $lines->sum('debit');
        $credits = (float) $lines->sum('credit');
        $this->assertEqualsWithDelta($debits, $credits, 0.001, 'The journal entry must stay balanced.');

        // The line's department travels onto its journal line.
        $this->assertEquals($departmentId, (int) $servicesPosted->first()->department_id,
            'The department on the invoice line must reach the journal line.');

        // …so a department filter on the ledger finds exactly that revenue.
        $filtered = JournalEntryLine::byDepartment($departmentId)
            ->where('journal_entry_id', $invoice->journal_entry_id)
            ->get();
        $this->assertCount(1, $filtered, 'A department filter must return the revenue line.');
        $this->assertEqualsWithDelta(90.0, (float) $filtered->first()->credit, 0.001);
        $this->assertSame((int) $this->servicesRevenue->id, (int) $filtered->first()->account_id);

        // The P&L shows both income accounts instead of one blended default.
        $response = $this->actingAs($this->admin)->getJson(
            '/api/v1/accounting/profit-and-loss?start_date='.now()->toDateString().'&end_date='.now()->toDateString()
        );
        $response->assertOk();
        $revenue = collect($response->json('revenue'));
        $this->assertEqualsWithDelta(
            90.0,
            (float) ($revenue->firstWhere('id', $this->servicesRevenue->id)['balance'] ?? 0),
            0.001,
            'The P&L must show the services income account.'
        );
        $this->assertEqualsWithDelta(
            45.0,
            (float) ($revenue->firstWhere('id', $this->goodsRevenue->id)['balance'] ?? 0),
            0.001,
            'The P&L must show the goods income account.'
        );
        $this->assertSame(
            0.0,
            round((float) ($revenue->firstWhere('id', $this->defaultRevenue->id)['balance'] ?? 0), 2),
            'The default sales_revenue account must stay untouched.'
        );
    }

    /**
     * Precedence AC: the line's own GL override beats the product's income
     * account, which beats the account_mappings default.
     */
    public function test_line_gl_override_wins_over_the_product_income_account(): void
    {
        $invoice = $this->createInvoice([
            $this->serviceProduct->id => ['description' => 'Consulting', 'price' => 100],
        ]);
        $invoice->lines()->firstOrFail()->forceFill(['gl_account_id' => $this->goodsRevenue->id])->save();

        $this->postInvoice($invoice);

        $invoice = Invoice::with('journalEntry.lines')->findOrFail($invoice->id);
        $lines = $invoice->journalEntry->lines;

        $this->assertEqualsWithDelta(
            100.0,
            (float) $lines->where('account_id', $this->goodsRevenue->id)->sum('credit'),
            0.001,
            'The line override must win over the product income account.'
        );
        $this->assertSame(0.0, round((float) $lines->where('account_id', $this->servicesRevenue->id)->sum('credit'), 2),
            'The product income account must not be used when the line overrides it.');
        $this->assertSame(0.0, round((float) $lines->where('account_id', $this->defaultRevenue->id)->sum('credit'), 2));
    }

    /**
     * A sales-order line's income account (per-line override carried onto the
     * invoice line) drives the revenue line, not the product's account.
     */
    public function test_sales_order_line_income_account_drives_the_revenue_line(): void
    {
        $service = app(SalesOrderService::class);
        $warehouse = Warehouse::create([
            'name' => 'SA2 Warehouse',
            'code' => 'SA2-'.Str::upper(Str::random(5)),
            'is_active' => true,
        ]);

        $order = $service->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $warehouse->id,
            'created_by' => $this->admin->id,
            'legal_entity_id' => $this->legalEntityId,
            'lines' => [[
                'product_id' => $this->serviceProduct->id,
                'description' => 'Consulting retainer',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
                // The order line pins a different income account than the product.
                'income_account_id' => $this->goodsRevenue->id,
            ]],
        ]);

        $order = $service->allocateOrder($service->confirmOrder($order));

        $response = $this->actingAs($this->admin)->postJson("/api/v1/sales/orders/{$order->id}/invoice");
        $response->assertStatus(201, $response->getContent());

        $invoice = Invoice::with('lines')->find($response->json('invoice.id'));
        $this->assertNotNull($invoice);
        $this->assertSame((int) $order->lines->first()->id, (int) $invoice->lines->first()->sales_order_line_id);

        $this->postInvoice($invoice);

        $invoice = Invoice::with('journalEntry.lines')->findOrFail($invoice->id);
        $lines = $invoice->journalEntry->lines;

        $this->assertEqualsWithDelta(
            100.0,
            (float) $lines->where('account_id', $this->goodsRevenue->id)->sum('credit'),
            0.001,
            'The sales order line income account must drive the revenue line.'
        );
        $this->assertSame(0.0, round((float) $lines->where('account_id', $this->servicesRevenue->id)->sum('credit'), 2),
            'The product income account must not win over the order line.');
        $this->assertSame(0.0, round((float) $lines->where('account_id', $this->defaultRevenue->id)->sum('credit'), 2));
    }

    /**
     * SA7 (#880) still holds when revenue is split: each income account keeps
     * its own line, and the shares add up to the EXACT converted amount in
     * whole cents — so the entry balances and the AR leg matches, with no
     * residual invented at revenue.
     */
    public function test_foreign_currency_split_keeps_the_fx_totals_and_the_balance(): void
    {
        $invoice = $this->createInvoice([
            $this->serviceProduct->id => ['description' => 'Consulting', 'price' => 100.01],
            $this->goodsProduct->id => ['description' => 'Hardware', 'price' => 50.00],
        ], currency: 'USD');

        $this->postInvoice($invoice);

        $invoice = Invoice::with('journalEntry.lines')->findOrFail($invoice->id);
        $lines = $invoice->journalEntry->lines;

        // 150.01 USD @ 1.3527 → 202.92 MYR (AR = revenue = the same exact
        // conversion, so no FX residual line is due).
        $servicesPosted = $lines->where('account_id', $this->servicesRevenue->id)->values();
        $goodsPosted = $lines->where('account_id', $this->goodsRevenue->id)->values();

        $this->assertCount(1, $servicesPosted);
        $this->assertEqualsWithDelta(135.28, (float) $servicesPosted->first()->credit, 0.001,
            'The services share of the exact converted amount.');
        $this->assertEqualsWithDelta(100.01, (float) $servicesPosted->first()->original_amount, 0.001);

        $this->assertCount(1, $goodsPosted);
        $this->assertEqualsWithDelta(67.64, (float) $goodsPosted->first()->credit, 0.001);
        $this->assertEqualsWithDelta(50.00, (float) $goodsPosted->first()->original_amount, 0.001);

        $this->assertSame(0.0, round((float) $lines->where('account_id', $this->defaultRevenue->id)->sum('credit'), 2));

        $this->assertEqualsWithDelta(
            202.92,
            (float) $lines->where('account_id', $this->receivable->id)->sum('debit'),
            0.001,
            'AR carries the exact converted document total.'
        );
        $this->assertEqualsWithDelta(
            202.92,
            (float) ($servicesPosted->first()->credit + $goodsPosted->first()->credit),
            0.001,
            'The revenue shares must add up to the exact converted revenue — never a cent more or less.'
        );

        $debits = (float) $lines->sum('debit');
        $credits = (float) $lines->sum('credit');
        $this->assertEqualsWithDelta($debits, $credits, 0.001, 'The split entry must stay balanced.');
    }

    /**
     * @param  array<int, array{description:string, price:float}>  $lines
     */
    private function createInvoice(array $lines, float $discount = 0.0, ?string $currency = null): Invoice
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [],
        ];

        if ($discount > 0) {
            $payload['discount_amount'] = $discount;
        }

        if ($currency !== null) {
            $payload['currency_code'] = $currency;
        }

        foreach ($lines as $productId => $line) {
            $payload['lines'][] = [
                'product_id' => $productId,
                'description' => $line['description'],
                'quantity' => 1,
                'unit_price' => $line['price'],
                'tax_rate' => 0,
                'discount_amount' => 0,
            ];
        }

        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', $payload);
        $response->assertCreated($response->getContent());

        return Invoice::with('lines')->findOrFail($response->json('id'));
    }

    private function postInvoice(Invoice $invoice): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post");
        $response->assertOk($response->getContent());
    }

    private function createDepartment(): int
    {
        return DB::table('analytic_accounts')->insertGetId([
            'code' => 'SA2-DEP-'.Str::upper(Str::random(5)),
            'name' => 'SA2 Services Department',
            'type' => 'department',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function revenueAccount(string $code, string $name): ChartOfAccount
    {
        return ChartOfAccount::factory()->create([
            'code' => $code,
            'name' => $name,
            'type' => 'revenue',
            'is_active' => true,
        ]);
    }
}
