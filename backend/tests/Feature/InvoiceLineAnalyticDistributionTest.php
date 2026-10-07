<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #332 — analytic distribution per sales line (Odoo parity).
 *
 * Before: a sales line could name at most ONE department and ONE project, and
 * the invoice header carried a free-text "cost center" that never reached the
 * ledger. A line could not be split across several analytic accounts by
 * percentage, so the analytic P&L could not show a shared sale.
 *
 * AC: line-level distribution (analytic account + %) on quotations, sales
 * orders and invoices, carried through the conversions and posted with the
 * journal lines; the analytic P&L reflects sales invoices. Percentages must
 * total 100 and reference active analytic accounts (422 otherwise).
 *
 * Everything goes through the real routes (router → middleware → controller),
 * and posting runs through the real PostingEngine.
 */
class InvoiceLineAnalyticDistributionTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private ChartOfAccount $defaultRevenue;

    private ChartOfAccount $servicesRevenue;

    private ChartOfAccount $goodsRevenue;

    private ChartOfAccount $receivable;

    private Product $serviceProduct;

    private int $legalEntityId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->admin = User::factory()->create(['email' => 'ad332-'.uniqid().'@example.local']);
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
            'company_name' => 'AD332 Income Flow Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $entityGroupId = DB::table('entity_groups')->insertGetId([
            'name' => 'AD332 Group',
            'code' => 'AD332-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->legalEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $entityGroupId,
            'name' => 'AD332 Entity',
            'code' => 'AD332-E-'.Str::upper(Str::random(5)),
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

        $this->defaultRevenue = $this->revenueAccount('AD332-4100', 'AD332 Default Sales Revenue');
        $this->servicesRevenue = $this->revenueAccount('AD332-4110', 'AD332 Services Income');
        $this->goodsRevenue = $this->revenueAccount('AD332-4120', 'AD332 Goods Income');
        $this->receivable = ChartOfAccount::factory()->create([
            'code' => 'AD332-1240',
            'name' => 'AD332 Accounts Receivable',
            'type' => 'asset',
            'is_active' => true,
        ]);

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

        // The product's own income account is the fallback — the quotation
        // line override below must beat it.
        $this->serviceProduct = Product::factory()->create([
            'name' => 'AD332 Consulting Hours',
            'sku' => 'AD332-SRV-'.Str::upper(Str::random(5)),
            'price' => 100,
            'cost' => 0,
            'track_inventory' => false,
            'income_account_id' => $this->servicesRevenue->id,
        ]);

        DB::table('currencies')->updateOrInsert(
            ['code' => 'MYR'],
            ['name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /**
     * A direct invoice line split 60/40 over two departments posts two
     * revenue credits (60 → A, 40 → B) on the line's income account, the
     * entry balances, and each department's analytic P&L shows its share.
     */
    public function test_direct_invoice_line_distribution_splits_revenue_onto_the_journal_and_the_analytic_pnl(): void
    {
        $deptA = $this->createAnalytic('department', 'AD332 Sales North');
        $deptB = $this->createAnalytic('department', 'AD332 Sales South');

        $response = $this->createInvoice([
            ['analytic_account_id' => $deptA, 'percentage' => 60],
            ['analytic_account_id' => $deptB, 'percentage' => 40],
        ]);
        $response->assertCreated($response->getContent());

        $invoiceId = (int) $response->json('id');

        // The distribution is stored on the line and shown on the invoice.
        $show = $this->actingAs($this->admin)->getJson("/api/v1/invoices/{$invoiceId}");
        $show->assertOk();
        $distribution = collect($show->json('lines.0.analytic_distribution'));
        $this->assertCount(2, $distribution, 'The invoice line must keep both analytic shares.');
        $this->assertEqualsWithDelta(60.0, (float) $distribution->firstWhere('analytic_account_id', $deptA)['percentage'], 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $distribution->firstWhere('analytic_account_id', $deptB)['percentage'], 0.001);
        $this->assertSame('AD332 Sales North', $distribution->firstWhere('analytic_account_id', $deptA)['name'],
            'The invoice shows each share by its analytic account name.');

        $post = $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/post");
        $post->assertOk($post->getContent());

        $invoice = Invoice::with('journalEntry.lines')->findOrFail($invoiceId);
        $lines = $invoice->journalEntry->lines;
        $revenue = $lines->where('account_id', $this->goodsRevenue->id)->values();

        $this->assertCount(2, $revenue, 'A 60/40 distribution must post two revenue lines.');
        $this->assertEqualsWithDelta(60.0, (float) $revenue->firstWhere('department_id', $deptA)?->credit, 0.001,
            '60% of the line revenue must carry department A.');
        $this->assertEqualsWithDelta(40.0, (float) $revenue->firstWhere('department_id', $deptB)?->credit, 0.001,
            '40% of the line revenue must carry department B.');
        $this->assertEqualsWithDelta(100.0, (float) $lines->where('account_id', $this->receivable->id)->sum('debit'), 0.001,
            'AR still carries the whole document total.');
        $this->assertEqualsWithDelta((float) $lines->sum('debit'), (float) $lines->sum('credit'), 0.001,
            'The split entry must stay balanced.');

        // The analytic P&L reflects the sales invoice.
        $today = now()->toDateString();
        $pnlA = $this->actingAs($this->admin)
            ->getJson("/api/v1/accounting/analytic-accounts/{$deptA}/profit-loss?dimension=department&start_date={$today}&end_date={$today}");
        $pnlA->assertOk();
        $this->assertEqualsWithDelta(60.0, (float) $pnlA->json('revenues'), 0.001, 'Department A P&L must show its 60% share.');

        $pnlB = $this->actingAs($this->admin)
            ->getJson("/api/v1/accounting/analytic-accounts/{$deptB}/profit-loss?dimension=department&start_date={$today}&end_date={$today}");
        $pnlB->assertOk();
        $this->assertEqualsWithDelta(40.0, (float) $pnlB->json('revenues'), 0.001, 'Department B P&L must show its 40% share.');
    }

    public function test_distribution_that_does_not_total_100_is_rejected(): void
    {
        $deptA = $this->createAnalytic('department', 'AD332 Under');
        $deptB = $this->createAnalytic('department', 'AD332 Over');

        $response = $this->createInvoice([
            ['analytic_account_id' => $deptA, 'percentage' => 60],
            ['analytic_account_id' => $deptB, 'percentage' => 30],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lines.0.analytic_distribution']);
        $this->assertSame(0, Invoice::query()->count(), 'A rejected distribution must not create an invoice.');
    }

    public function test_distribution_referencing_an_inactive_or_unknown_analytic_account_is_rejected(): void
    {
        $active = $this->createAnalytic('department', 'AD332 Active');
        $inactive = $this->createAnalytic('department', 'AD332 Closed', isActive: false);

        $inactiveResponse = $this->createInvoice([
            ['analytic_account_id' => $active, 'percentage' => 50],
            ['analytic_account_id' => $inactive, 'percentage' => 50],
        ]);
        $inactiveResponse->assertStatus(422);
        $inactiveResponse->assertJsonValidationErrors(['lines.0.analytic_distribution.1.analytic_account_id']);

        $unknownResponse = $this->createInvoice([
            ['analytic_account_id' => 999999, 'percentage' => 100],
        ]);
        $unknownResponse->assertStatus(422);
        $unknownResponse->assertJsonValidationErrors(['lines.0.analytic_distribution.0.analytic_account_id']);

        $this->assertSame(0, Invoice::query()->count());
    }

    /**
     * Quotation → sales order → invoice: the distribution travels with every
     * conversion and the posted journal carries it (50% department, 50%
     * project). The quotation endpoint validates the same rule.
     */
    public function test_quotation_distribution_flows_through_the_order_to_the_invoice_and_the_ledger(): void
    {
        $dept = $this->createAnalytic('department', 'AD332 Pre-Sales');
        $project = $this->createAnalytic('project', 'AD332 Rollout');

        $bad = $this->postQuotation([
            ['analytic_account_id' => $dept, 'percentage' => 50],
        ]);
        $bad->assertStatus(422);
        $bad->assertJsonValidationErrors(['lines.0.analytic_distribution']);

        $response = $this->postQuotation([
            ['analytic_account_id' => $dept, 'percentage' => 50],
            ['analytic_account_id' => $project, 'percentage' => 50],
        ]);
        $response->assertCreated($response->getContent());

        $quotation = Quotation::with('lines')->findOrFail($response->json('data.id'));
        $this->assertCount(2, (array) $quotation->lines->firstOrFail()->analytic_distribution,
            'The quotation line must persist its distribution.');

        $confirm = $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$quotation->id}/confirm");
        $confirm->assertOk($confirm->getContent());

        $quotation = $quotation->fresh(['salesOrder.lines']);
        $orderLine = $quotation->salesOrder->lines->firstOrFail();
        $this->assertCount(2, (array) $orderLine->analytic_distribution,
            'The sales order line must inherit the quotation line distribution.');

        $invoiceResponse = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/orders/{$quotation->sales_order_id}/invoice");
        $invoiceResponse->assertStatus(201, $invoiceResponse->getContent());

        $invoice = Invoice::with('lines')->findOrFail($invoiceResponse->json('invoice.id'));
        $this->assertCount(2, (array) $invoice->lines->firstOrFail()->analytic_distribution,
            'The invoice line must inherit the order line distribution.');

        $post = $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoice->id}/post");
        $post->assertOk($post->getContent());

        $lines = Invoice::with('journalEntry.lines')->findOrFail($invoice->id)->journalEntry->lines;
        $revenue = $lines->where('account_id', $this->goodsRevenue->id)->values();

        $this->assertCount(2, $revenue, 'A 50/50 distribution must post two revenue lines.');
        $this->assertEqualsWithDelta(50.0, (float) $revenue->firstWhere('department_id', $dept)?->credit, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $revenue->firstWhere('project_id', $project)?->credit, 0.001);
        $this->assertEqualsWithDelta((float) $lines->sum('debit'), (float) $lines->sum('credit'), 0.001);
    }

    private function createInvoice(array $distribution): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'product_id' => $this->serviceProduct->id,
                'description' => 'Shared consulting',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
                'gl_account_id' => $this->goodsRevenue->id,
                'analytic_distribution' => $distribution,
            ]],
        ]);
    }

    private function postQuotation(array $distribution): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->serviceProduct->id,
                'description' => 'Shared rollout',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
                'income_account_id' => $this->goodsRevenue->id,
                'analytic_distribution' => $distribution,
            ]],
        ]);
    }

    private function createAnalytic(string $type, string $name, bool $isActive = true): int
    {
        return DB::table('analytic_accounts')->insertGetId([
            'code' => 'AD332-'.Str::upper(Str::random(6)),
            'name' => $name,
            'type' => $type,
            'is_active' => $isActive,
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
