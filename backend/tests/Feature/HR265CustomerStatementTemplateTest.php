<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerStatement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\GeneratedDocument;
use Tests\TenantTestCase;

/**
 * Customer Account Statement — template integration + fallback redesign.
 *
 * Production report (2026-09-24): the statement PDF for a month where the
 * invoice was already settled rendered through the legacy Blade fallback and
 * looked wrong because:
 *   1. `statement()` filtered `status='posted' AND balance > 0`, so a paid
 *      invoice AND its payment were both missing — the statement for the
 *      month was empty/incoherent.
 *   2. The tenant had no published `customer_statement` template (defaults
 *      are seeded only by a job that is never dispatched), so every render
 *      threw into the fallback.
 *   3. The fallback Blade declared 8 table headers but emitted 6 body cells,
 *      shifting every column and printing running totals as debits.
 *
 * Fixes under test:
 *   - statement() builds its payload through CustomerStatementService with
 *     include_paid=true for range statements (invoices + payments + credits
 *     + opening balance + per-row running balance).
 *   - PdfGenerationService lazily seeds the system-default templates when a
 *     tenant has none, then renders through the Template Platform.
 *   - The fallback Blade is a complete 8-column statement layout.
 */
class HR265CustomerStatementTemplateTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '33333333-4444-5555-6666-777777777777',
        ]));
        $this->bindTenantContext($this->tenant->id);

        $this->user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
    }

    private function settledInvoiceWithPayment(): array
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'paid',
            'lifecycle_status' => 'posted',
            'settlement_status' => 'paid',
            'invoice_number' => '2025/03/021',
            'invoice_date' => '2025-03-10',
            'due_date' => '2025-04-09',
            'subtotal' => 1744,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 1744,
            'paid_amount' => 1744,
            'balance' => 0,
            'created_by' => $this->user->id,
        ]);

        $payment = Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'payment_number' => 'PAY/2026/09/178',
            'payment_date' => '2025-03-28',
            'amount' => 1744,
            'payment_status' => 'applied',
            'received_by' => $this->user->id,
        ]);

        return [$invoice, $payment];
    }

    public function test_statement_returns_pdf_and_includes_settled_invoice_and_its_payment(): void
    {
        [$invoice, $payment] = $this->settledInvoiceWithPayment();

        $response = $this->actingAs($this->user)->get(
            '/api/v1/accounting/customers/'.$this->customer->id.'/statement'
            .'?start_date=2025-03-01&end_date=2025-03-31'
        );

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // The persisted statement payload must contain BOTH the settled
        // invoice and its payment — the previous implementation dropped both.
        $statement = CustomerStatement::query()->latest('id')->firstOrFail();
        $transactions = collect($statement->payload['transactions']);

        $this->assertTrue(
            $transactions->contains(fn ($t) => ($t['type'] ?? '') === 'Invoice'
                && ($t['document_number'] ?? '') === '2025/03/021'),
            'A settled invoice inside the period must still appear on the statement.'
        );
        $this->assertTrue(
            $transactions->contains(fn ($t) => ($t['type'] ?? '') === 'Payment'
                && ($t['document_number'] ?? '') === 'PAY/2026/09/178'),
            'The payment that settled the invoice must appear on the statement.'
        );

        $paymentRow = $transactions->firstWhere('type', 'Payment');
        $this->assertEquals(1744.0, (float) $paymentRow['credit']);

        // Closing balance = 1744 debit - 1744 credit = 0.
        $this->assertEquals(0.0, round((float) $statement->payload['closing_balance'], 2));
        $this->assertTrue((bool) $statement->payload['include_paid'], 'Range statements must include settled documents.');
    }

    public function test_first_statement_render_auto_provisions_the_default_template_and_uses_it(): void
    {
        $this->settledInvoiceWithPayment();

        // No template exists for the tenant yet.
        $this->assertSame(
            0,
            DocumentTemplate::forDocumentType('customer_statement')->count(),
            'Precondition: tenant starts with no statement template.'
        );

        $response = $this->actingAs($this->user)->get(
            '/api/v1/accounting/customers/'.$this->customer->id.'/statement'
            .'?start_date=2025-03-01&end_date=2025-03-31'
        );

        $response->assertOk();

        // The render lazily provisioned the default and used it (audit row).
        $template = DocumentTemplate::forDocumentType('customer_statement')
            ->where('is_system_default', true)
            ->first();

        $this->assertNotNull($template, 'The system-default statement template must be provisioned on first render.');
        $this->assertSame(DocumentTemplate::STATUS_PUBLISHED, $template->status);

        $statement = CustomerStatement::query()->latest('id')->firstOrFail();
        $this->assertTrue(
            GeneratedDocument::query()
                ->where('document_type', 'customer_statement')
                ->where('document_id', (string) $statement->id)
                ->where('template_id', $template->id)
                ->exists(),
            'The statement PDF must be produced by the Template Platform, not the legacy fallback.'
        );
    }

    public function test_fallback_blade_renders_all_columns_without_shifting(): void
    {
        // Canonical payload shape as produced by CustomerStatementService.
        $payload = [
            'company' => ['name' => 'Acme Pte Ltd', 'address' => 'Singapore', 'email' => 'info@example.test', 'phone' => '', 'registration_number' => '201626174G'],
            'customer' => ['code' => 'VC0013', 'name' => "ASCENSUS-BEH'S CLIINIC FOR WOMEN PTE.LTD.", 'email' => '', 'attention_to' => 'Accounts Payable', 'billing_address' => 'Singapore', 'payment_terms' => '30 Days from Invoice Date'],
            'period' => ['start_date' => '2025-03-01', 'end_date' => '2025-03-31', 'type' => 'custom'],
            'as_of_date' => '2025-03-31',
            'currency' => 'SGD',
            'opening_balance' => 0.0,
            'closing_balance' => 0.0,
            'total_amount_due' => 0.0,
            'total_debits' => 1744.0,
            'total_credits' => 1744.0,
            'transactions' => [
                ['date' => '2025-03-10', 'type' => 'Invoice', 'document_number' => '2025/03/021', 'description' => 'HyaRegen Gel', 'reference' => 'PO-1', 'due_date' => '2025-04-09', 'inv_amount' => 1744.0, 'debit' => 1744.0, 'credit' => 0.0, 'outstanding' => 0.0, 'status' => 'paid', 'balance' => 1744.0],
                ['date' => '2025-03-28', 'type' => 'Payment', 'document_number' => 'PAY/2026/09/178', 'description' => 'Bank transfer', 'reference' => '', 'due_date' => '', 'inv_amount' => 0.0, 'debit' => 0.0, 'credit' => 1744.0, 'outstanding' => null, 'status' => 'applied', 'balance' => 0.0],
            ],
            'aging' => ['current' => 0.0, 'days_1_30' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'days_90_plus' => 0.0, 'total' => 0.0],
            'bank' => ['bank_name' => 'DBS Bank', 'account_name' => 'Acme Pte Ltd', 'account_number' => '072-1', 'swift_code' => 'DBSSSGSG', 'bank_code' => '072'],
            'generated_at' => now()->toISOString(),
        ];

        $html = View::make('accounting.customer-statement-pdf', [
            'payload' => $payload,
            'statementId' => 42,
        ])->render();

        // Every header and every row value renders — the old fallback had 8
        // headers against 6 cells, shifting every column left.
        foreach (['Date', 'Type', 'Document #', 'Description', 'Debit', 'Credit', 'Balance'] as $header) {
            $this->assertStringContainsString($header, $html, "Header '{$header}' missing from the fallback statement.");
        }

        foreach (['2025/03/021', 'PAY/2026/09/178', 'ASCENSUS-BEH', 'S$1,744.00', 'ACCOUNT STATEMENT', 'Aging Summary'] as $needle) {
            $this->assertStringContainsString($needle, $html, "Fallback statement missing '{$needle}'.");
        }

        // No unresolved template variables / PHP errors leaked into the HTML.
        $this->assertStringNotContainsString('$currency', $html);
        $this->assertStringNotContainsString('$periodStart', $html);
        $this->assertStringNotContainsString('Your Company', $html);
    }

    public function test_fallback_blade_renders_empty_state_without_transactions(): void
    {
        $payload = [
            'company' => ['name' => 'Acme Pte Ltd'],
            'customer' => ['name' => 'Empty Statement Co', 'code' => 'VC9999'],
            'period' => ['start_date' => '2025-04-01', 'end_date' => '2025-04-30', 'type' => 'custom'],
            'as_of_date' => '2025-04-30',
            'currency' => 'SGD',
            'opening_balance' => 0.0,
            'closing_balance' => 0.0,
            'total_amount_due' => 0.0,
            'total_debits' => 0.0,
            'total_credits' => 0.0,
            'transactions' => [],
            'aging' => [],
            'bank' => [],
            'generated_at' => now()->toISOString(),
        ];

        $html = View::make('accounting.customer-statement-pdf', [
            'payload' => $payload,
            'statementId' => 7,
        ])->render();

        $this->assertStringContainsString('No transactions in this period', $html);
        $this->assertStringContainsString('Empty Statement Co', $html);
        $this->assertStringContainsString('S$0.00', $html);
    }
}
