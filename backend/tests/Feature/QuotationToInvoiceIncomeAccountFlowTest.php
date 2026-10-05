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
 * Issue #884 (UX2) — the line's income account and analytic dimensions
 * (project/department) must flow quotation → sales order → invoice, and the
 * invoice line must persist the override it was created with so posting
 * recognises revenue on the right account.
 *
 * #876 (SA2) already resolves revenue per line at posting:
 *   invoice line gl override → sales-order line income account → product.
 * #884 closes the two holes that left the chain incomplete: quotation lines
 * could not carry the values at all, and invoice creation dropped the line's
 * gl account/dimensions instead of persisting them.
 *
 * Everything below goes through the real routes, not a service instantiated
 * by the test.
 */
class QuotationToInvoiceIncomeAccountFlowTest extends TenantTestCase
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

        $this->admin = User::factory()->create(['email' => 'ux2-'.uniqid().'@example.local']);
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
            'company_name' => 'UX2 Income Flow Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $entityGroupId = DB::table('entity_groups')->insertGetId([
            'name' => 'UX2 Group',
            'code' => 'UX2-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->legalEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $entityGroupId,
            'name' => 'UX2 Entity',
            'code' => 'UX2-E-'.Str::upper(Str::random(5)),
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

        $this->defaultRevenue = $this->revenueAccount('UX2-4100', 'UX2 Default Sales Revenue');
        $this->servicesRevenue = $this->revenueAccount('UX2-4110', 'UX2 Services Income');
        $this->goodsRevenue = $this->revenueAccount('UX2-4120', 'UX2 Goods Income');
        $this->receivable = ChartOfAccount::factory()->create([
            'code' => 'UX2-1240',
            'name' => 'UX2 Accounts Receivable',
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
            'name' => 'UX2 Consulting Hours',
            'sku' => 'UX2-SRV-'.Str::upper(Str::random(5)),
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
     * AC: "values flow quotation → SO → invoice." A quotation line pins its
     * own income account + project/department; the released order line and the
     * invoice line must carry the same values, and posting must credit the
     * pinned account with the dimensions on its journal line.
     */
    public function test_quotation_line_income_account_and_dimensions_flow_to_the_invoice_and_the_ledger(): void
    {
        $departmentId = $this->createAnalytic('department', 'UX2 Pre-Sales Department');
        $projectId = $this->createAnalytic('project', 'UX2 Consulting Project');

        $response = $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->serviceProduct->id,
                'description' => 'Consulting retainer',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
                // The line pins its own income account and dimensions — the
                // product's account is only the default, not the value.
                'income_account_id' => $this->goodsRevenue->id,
                'department_id' => $departmentId,
                'project_id' => $projectId,
            ]],
        ]);
        $response->assertCreated($response->getContent());

        $quotation = Quotation::with('lines')->findOrFail($response->json('data.id'));
        $quotationLine = $quotation->lines->firstOrFail();
        $this->assertSame((int) $this->goodsRevenue->id, (int) $quotationLine->income_account_id,
            'The quotation line must persist the income account the user pinned.');
        $this->assertSame($departmentId, (int) $quotationLine->department_id,
            'The quotation line must persist its department.');
        $this->assertSame($projectId, (int) $quotationLine->project_id,
            'The quotation line must persist its project.');

        $confirm = $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$quotation->id}/confirm");
        $confirm->assertOk($confirm->getContent());

        $quotation = $quotation->fresh(['salesOrder.lines']);
        $this->assertNotNull($quotation->sales_order_id, 'Confirming the quotation must create a sales order.');
        $orderLine = $quotation->salesOrder->lines->firstOrFail();
        $this->assertSame((int) $this->goodsRevenue->id, (int) $orderLine->income_account_id,
            'The sales order line must inherit the quotation line income account.');
        $this->assertSame($departmentId, (int) $orderLine->department_id,
            'The sales order line must inherit the quotation line department.');
        $this->assertSame($projectId, (int) $orderLine->project_id,
            'The sales order line must inherit the quotation line project.');

        $invoiceResponse = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/orders/{$quotation->sales_order_id}/invoice");
        $invoiceResponse->assertStatus(201, $invoiceResponse->getContent());

        $invoice = Invoice::with('lines')->findOrFail($invoiceResponse->json('invoice.id'));
        $invoiceLine = $invoice->lines->firstOrFail();
        $this->assertSame((int) $this->goodsRevenue->id, (int) $invoiceLine->gl_account_id,
            'The invoice line must carry the income account it was converted with.');
        $this->assertSame($departmentId, (int) $invoiceLine->department_id,
            'The invoice line must carry the department.');
        $this->assertSame($projectId, (int) $invoiceLine->project_id,
            'The invoice line must carry the project.');

        $post = $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoice->id}/post");
        $post->assertOk($post->getContent());

        $invoice = Invoice::with('journalEntry.lines')->findOrFail($invoice->id);
        $lines = $invoice->journalEntry->lines;

        $revenuePosted = $lines->where('account_id', $this->goodsRevenue->id)->first();
        $this->assertNotNull($revenuePosted,
            'Posting must credit the income account the quotation line pinned.');
        $this->assertEqualsWithDelta(100.0, (float) $revenuePosted->credit, 0.001);
        $this->assertSame($departmentId, (int) $revenuePosted->department_id,
            'The department must reach the journal line.');
        $this->assertSame($projectId, (int) $revenuePosted->project_id,
            'The project must reach the journal line.');
        $this->assertSame(0.0, round((float) $lines->where('account_id', $this->servicesRevenue->id)->sum('credit'), 2),
            'The product income account must not be used when the line pins its own.');
    }

    /**
     * The #876-disclosed gap: creating an invoice from the API with a line GL
     * override/dimensions dropped them on the floor, so posting silently fell
     * back to the product account and lost the analytics.
     */
    public function test_direct_invoice_creation_persists_the_line_income_account_and_dimensions(): void
    {
        $departmentId = $this->createAnalytic('department', 'UX2 Billing Department');
        $projectId = $this->createAnalytic('project', 'UX2 Billing Project');

        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'product_id' => $this->serviceProduct->id,
                'description' => 'Consulting retainer',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
                // Invoice lines store the GL override as gl_account_id.
                'gl_account_id' => $this->goodsRevenue->id,
                'department_id' => $departmentId,
                'project_id' => $projectId,
            ]],
        ]);
        $response->assertCreated($response->getContent());

        $invoice = Invoice::with('lines')->findOrFail($response->json('id'));
        $invoiceLine = $invoice->lines->firstOrFail();
        $this->assertSame((int) $this->goodsRevenue->id, (int) $invoiceLine->gl_account_id,
            'Creating an invoice with a line income account must persist it.');
        $this->assertSame($departmentId, (int) $invoiceLine->department_id,
            'Creating an invoice with a line department must persist it.');
        $this->assertSame($projectId, (int) $invoiceLine->project_id,
            'Creating an invoice with a line project must persist it.');

        $post = $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoice->id}/post");
        $post->assertOk($post->getContent());

        $invoice = Invoice::with('journalEntry.lines')->findOrFail($invoice->id);
        $lines = $invoice->journalEntry->lines;

        $revenuePosted = $lines->where('account_id', $this->goodsRevenue->id)->first();
        $this->assertNotNull($revenuePosted,
            'Posting must credit the income account sent with the line.');
        $this->assertEqualsWithDelta(100.0, (float) $revenuePosted->credit, 0.001);
        $this->assertSame($departmentId, (int) $revenuePosted->department_id);
        $this->assertSame($projectId, (int) $revenuePosted->project_id);
        $this->assertSame(0.0, round((float) $lines->where('account_id', $this->servicesRevenue->id)->sum('credit'), 2));
    }

    private function createAnalytic(string $type, string $name): int
    {
        return DB::table('analytic_accounts')->insertGetId([
            'code' => 'UX2-'.Str::upper(Str::random(6)),
            'name' => $name,
            'type' => $type,
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
