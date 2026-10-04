<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use Modules\Sales\Models\SalesOrder;
use Modules\TemplateBuilder\Services\DataSource\InvoiceDataSource;
use Modules\TemplateBuilder\Services\DataSource\QuotationDataSource;
use Modules\TemplateBuilder\Services\DataSource\SalesOrderDataSource;
use Tests\TenantTestCase;

/**
 * Issue #333 ([SALES-ODOO]) — cash rounding and Incoterms on sales documents.
 *
 * Acceptance criteria:
 *  1. A tenant cash-rounding setting (e.g. MYR 0.05) is applied to invoice
 *     totals and the adjustment is posted as its own journal line to a
 *     configured rounding account — through PostingEngine, never by writing
 *     JournalEntry rows directly.
 *  2. Incoterm code + place are stored on quotations, sales orders and
 *     invoices, carried quote → SO → invoice, and printed.
 *
 * All document flows below run through the real HTTP routes (router →
 * middleware → controller → service). The ledger is read back from
 * journal_entry_lines, and the trial-balance assertion proves the entry
 * the PostingEngine wrote balances exactly.
 */
class Issue333CashRoundingIncotermsTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private ChartOfAccount $roundingAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Issue 333 Tenant');

        CompanySettings::query()->create(['base_currency' => 'MYR']);

        $category = ProductCategory::create([
            'name' => 'Issue333 Widgets',
            'is_active' => true,
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'Issue333 Customer',
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Issue333 Widget',
            'sku' => 'I333-'.uniqid(),
            'category_id' => $category->id,
            'price' => 1000,
            'cost' => 100,
            'track_inventory' => false,
        ]);

        // A tenant-owned rounding account. The fix must never seed or invent
        // one — when the tenant has not chosen an account the posting fails
        // loudly instead (see the no-account test below).
        $this->roundingAccount = ChartOfAccount::create([
            'code' => '5299',
            'name' => 'Cash Rounding Differences',
            'type' => 'expense',
            'is_active' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Configure (or disable) cash rounding through the real tenant settings
     * endpoint — the same PUT the Sales Settings UI uses.
     */
    private function configureCashRounding(bool $enabled, float $precision = 0.05, ?int $accountId = null): void
    {
        $response = $this->actingAs($this->admin)->putJson('/api/v1/settings/sales', [
            'cash_rounding' => [
                'enabled' => $enabled,
                'method' => 'nearest',
                'precision' => $precision,
                'account_id' => $accountId ?? $this->roundingAccount->id,
            ],
        ]);

        $response->assertOk();
    }

    /**
     * Create + post an accounting-origin AR invoice through the real route.
     */
    private function postOriginInvoice(float $unitPrice): Invoice
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/accounting/origin/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'auto_post' => true,
            'lines' => [[
                'line_type' => 'service',
                'description' => 'Issue333 line',
                'quantity' => 1,
                'unit_price' => $unitPrice,
            ]],
        ]);

        $response->assertCreated();

        return Invoice::findOrFail((int) $response->json('id'));
    }

    /**
     * Every line of the invoice's posted journal entry, with a trial-balance
     * assertion: the PostingEngine entry must balance to the cent.
     *
     * @return \Illuminate\Support\Collection<int, JournalEntryLine>
     */
    private function postedLines(Invoice $invoice)
    {
        $this->assertNotNull($invoice->journal_entry_id, 'Invoice must be posted to the GL');

        $lines = JournalEntryLine::query()
            ->where('journal_entry_id', $invoice->journal_entry_id)
            ->get();

        $debit = round((float) $lines->sum(fn (JournalEntryLine $line) => (float) $line->debit), 2);
        $credit = round((float) $lines->sum(fn (JournalEntryLine $line) => (float) $line->credit), 2);

        $this->assertSame(
            0.0,
            round($debit - $credit, 2),
            "Trial balance: journal entry debits {$debit} must equal credits {$credit}"
        );

        return $lines;
    }

    // ─────────────────────────────────────────────────────────────────────
    // (1) Cash rounding on invoice totals + the posted rounding line
    // ─────────────────────────────────────────────────────────────────────

    /**
     * MYR 0.05 nearest rounding, rounding UP: 1,234.53 → 1,234.55. The 0.02
     * gain is credited to the configured rounding account, AR is debited the
     * rounded total the customer actually owes, and the entry balances.
     */
    public function test_cash_rounding_rounds_invoice_total_up_and_posts_a_credit_rounding_line(): void
    {
        $this->configureCashRounding(true, 0.05);

        $invoice = $this->postOriginInvoice(1234.53);

        $this->assertSame(
            1234.55,
            round((float) $invoice->total, 2),
            'Invoice total must be rounded to the 0.05 step (1234.53 → 1234.55)'
        );
        $this->assertSame(
            0.02,
            round((float) $invoice->rounding_amount, 2),
            'The persisted rounding delta must be the gain (0.02)'
        );

        $lines = $this->postedLines($invoice);

        $roundingLine = $lines->firstWhere('account_id', $this->roundingAccount->id);
        $this->assertNotNull($roundingLine, 'The rounding delta must post to the configured rounding account');
        $this->assertSame(0.02, round((float) $roundingLine->credit, 2), 'Rounding gain is a credit');
        $this->assertSame(0.0, round((float) $roundingLine->debit, 2));

        $arAccountId = app(AccountMappingService::class)->resolveId('accounts_receivable');
        $arLine = $lines->firstWhere('account_id', $arAccountId);
        $this->assertNotNull($arLine);
        $this->assertSame(
            1234.55,
            round((float) $arLine->debit, 2),
            'AR must be debited the rounded total the customer actually owes'
        );
    }

    /**
     * Rounding DOWN: 1,234.52 → 1,234.50. The 0.02 loss is a debit to the
     * rounding account and the entry still balances.
     */
    public function test_cash_rounding_rounds_invoice_total_down_and_posts_a_debit_rounding_line(): void
    {
        $this->configureCashRounding(true, 0.05);

        $invoice = $this->postOriginInvoice(1234.52);

        $this->assertSame(1234.50, round((float) $invoice->total, 2));
        $this->assertSame(-0.02, round((float) $invoice->rounding_amount, 2));

        $lines = $this->postedLines($invoice);

        $roundingLine = $lines->firstWhere('account_id', $this->roundingAccount->id);
        $this->assertNotNull($roundingLine);
        $this->assertSame(0.02, round((float) $roundingLine->debit, 2), 'Rounding loss is a debit');
        $this->assertSame(0.0, round((float) $roundingLine->credit, 2));
    }

    /**
     * Default OFF (backward compatible): no rounding is applied, no rounding
     * line exists, and the posting path is byte-identical to before.
     */
    public function test_cash_rounding_disabled_leaves_invoice_totals_and_journal_untouched(): void
    {
        $invoice = $this->postOriginInvoice(1234.53);

        $this->assertSame(1234.53, round((float) $invoice->total, 2));
        $this->assertSame(0.0, round((float) ($invoice->rounding_amount ?? 0), 2));

        $lines = $this->postedLines($invoice);
        $this->assertNull(
            $lines->firstWhere('account_id', $this->roundingAccount->id),
            'A disabled rounding setting must never post a rounding line'
        );
    }

    /**
     * Fail loudly, never silently: with rounding enabled but no rounding
     * account configured the posting is refused (the invoice stays draft and
     * no journal entry is written).
     */
    public function test_cash_rounding_enabled_without_an_account_blocks_posting(): void
    {
        // Store the setting directly without an account id — this is the
        // misconfiguration the posting path must surface.
        Setting::setValue('sales.cash_rounding', [
            'enabled' => true,
            'method' => 'nearest',
            'precision' => 0.05,
            'account_id' => null,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/accounting/origin/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'auto_post' => true,
            'lines' => [[
                'line_type' => 'service',
                'description' => 'Issue333 unconfigured rounding',
                'quantity' => 1,
                'unit_price' => 1234.53,
            ]],
        ]);

        $response->assertStatus(500);

        $this->assertSame(
            0,
            Invoice::query()->where('customer_id', $this->customer->id)->count(),
            'The refused posting must not leave a half-created invoice behind'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // (2) Incoterms quote → SO → invoice + printed
    // ─────────────────────────────────────────────────────────────────────

    public function test_incoterm_on_quotation_carries_to_sales_order_and_invoice_and_renders(): void
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'incoterm_code' => 'FOB',
            'incoterm_place' => 'Port Klang',
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => 'Issue333 quoted line',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ]);
        $create->assertCreated();

        $quotation = Quotation::findOrFail((int) $create->json('data.id'));
        $this->assertSame('FOB', $quotation->incoterm_code);
        $this->assertSame('Port Klang', $quotation->incoterm_place);

        // Quote → Sales Order (real confirm route).
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $quotation->refresh();
        $this->assertNotNull($quotation->sales_order_id);

        $salesOrder = SalesOrder::findOrFail($quotation->sales_order_id);
        $this->assertSame('FOB', $salesOrder->incoterm_code, 'Incoterm code must carry quote → sales order');
        $this->assertSame('Port Klang', $salesOrder->incoterm_place);

        // Quote → Invoice (real create-invoice route; the order carries it on).
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertCreated();

        $invoice = Invoice::query()->where('quotation_id', $quotation->id)->firstOrFail();
        $this->assertSame('FOB', $invoice->incoterm_code, 'Incoterm code must carry quote → invoice');
        $this->assertSame('Port Klang', $invoice->incoterm_place);

        // Printed through the template data sources (the print pipeline reads
        // these payloads for every rendered document).
        $quotationPayload = (new QuotationDataSource)->load((string) $quotation->id)->toArray();
        $this->assertSame('FOB', $quotationPayload['quotation']['incoterm_code']);
        $this->assertSame('Port Klang', $quotationPayload['quotation']['incoterm_place']);
        $this->assertSame('FOB', $quotationPayload['invoice']['incoterm_code']);

        $orderPayload = (new SalesOrderDataSource)->load((string) $salesOrder->id)->toArray();
        $this->assertSame('FOB', $orderPayload['sales_order']['incoterm_code']);
        $this->assertSame('Port Klang', $orderPayload['sales_order']['incoterm_place']);

        $invoicePayload = (new InvoiceDataSource)->load((string) $invoice->id)->toArray();
        $this->assertSame('FOB', $invoicePayload['invoice']['incoterm_code']);
        $this->assertSame('Port Klang', $invoicePayload['invoice']['incoterm_place']);

        // Field catalogs must offer the new tokens to template authors.
        $tokens = fn (array $catalog): array => collect($catalog)
            ->flatMap(fn (array $group) => array_column($group['fields'], 'token'))
            ->all();
        $this->assertContains('invoice.incoterm_code', $tokens((new InvoiceDataSource)->fieldCatalog()));
        $this->assertContains('invoice.incoterm_place', $tokens((new InvoiceDataSource)->fieldCatalog()));
        $this->assertContains('sales_order.incoterm_code', $tokens((new SalesOrderDataSource)->fieldCatalog()));
        $this->assertContains('invoice.incoterm_code', $tokens((new QuotationDataSource)->fieldCatalog()));

        // Printed on the quotation PDF (Blade render, the DomPDF view).
        $html = view('sales.quotation-pdf', [
            'quotation' => $quotation->fresh(['customer', 'lines.product']),
            'company' => ['name' => 'Issue333 Co'],
        ])->render();

        $this->assertStringContainsString('FOB', $html);
        $this->assertStringContainsString('Port Klang', $html);
    }
}
