<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerStatement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerStatementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/**
 * Statement of Account cluster (#191-#203).
 *
 * Covers the canonical ledger math, the PDF render path, credit-note and
 * payment inclusion rules, the frozen aging snapshot and the ar_transactions
 * draft-credit-note filter.
 */
class CustomerStatementTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '22222222-3333-4444-5555-666666666666',
        ]));
        $this->bindTenantContext($tenant->id);

        $this->user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
    }

    private function invoice(array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'settlement_status' => 'unpaid',
            'invoice_date' => '2026-01-10',
            'due_date' => '2026-02-10',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 1000,
            'paid_amount' => 0,
            'balance' => 1000,
            'created_by' => $this->user->id,
        ], $overrides));
    }

    private function payment(Invoice $invoice, float $amount, string $date = '2026-01-20', string $status = 'applied'): Payment
    {
        return Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => $date,
            'amount' => $amount,
            'payment_status' => $status,
            'received_by' => $this->user->id,
        ]);
    }

    private function creditNote(Invoice $invoice, float $amount, string $status = 'posted', string $date = '2026-01-25'): CustomerCreditNote
    {
        return CustomerCreditNote::create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => $date,
            'status' => $status,
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'reason' => 'Test credit',
        ]);
    }

    /** #191 — generate() must render the PDF instead of fataling, and the running balance must not double-count payments (#192). */
    public function test_generate_renders_pdf_and_payment_is_not_double_counted(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice, 400);

        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/customers/'.$this->customer->id.'/statement',
            [
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-31',
                'include_paid' => true,
            ]
        );

        $response->assertOk();
        $this->assertEquals(600.0, (float) $response->json('closing_balance'));
        $this->assertEquals(400.0, (float) $response->json('total_credits'));

        $statement = CustomerStatement::findOrFail($response->json('id'));
        $this->assertNotNull($statement->pdf_path);
        $this->assertTrue(
            Storage::disk('public')->exists($statement->pdf_path),
            'The generated statement PDF was not persisted.'
        );

        // The payment appears exactly once, as a credit.
        $transactions = $statement->payload['transactions'];
        $paymentRows = collect($transactions)->where('type', 'Payment');
        $this->assertCount(1, $paymentRows);
        $this->assertEquals(400.0, (float) $paymentRows->first()['credit']);
    }

    /**
     * The canonical payload is exposed as JSON so the SPA can render Excel/Word
     * exports client-side; the default format stays the PDF.
     */
    public function test_json_format_returns_the_canonical_payload_and_pdf_stays_default(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice, 400);

        $json = $this->actingAs($this->user)->getJson(
            '/api/v1/accounting/customers/'.$this->customer->id.'/statement?as_of=2026-01-31&format=json'
        );

        $json->assertOk();
        $this->assertNotEmpty($json->json('statement_id'));
        $this->assertSame('SOA_'.$this->customer->code.'_20260131', $json->json('filename'));
        $this->assertEquals(600.0, (float) $json->json('payload.closing_balance'));
        $this->assertEquals(400.0, (float) $json->json('payload.total_credits'));
        $this->assertNotEmpty($json->json('payload.transactions'));

        $pdf = $this->actingAs($this->user)->get(
            '/api/v1/accounting/customers/'.$this->customer->id.'/statement?as_of=2026-01-31'
        );

        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
    }

    /** #193 — as-of statement and range statement ending on the same date must agree. */
    public function test_as_of_and_range_statements_report_the_same_closing_balance(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice, 400);
        $this->creditNote($invoice, 100);

        $service = app(CustomerStatementService::class);

        $asOf = Carbon::parse('2026-01-31');
        $asOfPayload = $service->buildAsOfPayload($this->customer, $asOf);
        $rangePayload = $service->buildRangePayload(
            $this->customer,
            Carbon::parse('2026-01-01'),
            $asOf,
            true,
        );

        // 1000 invoice - 400 payment - 100 credit note = 500 outstanding.
        $this->assertEquals(500.0, $asOfPayload['closing_balance']);
        $this->assertEquals(500.0, $rangePayload['closing_balance']);
        $this->assertEquals($asOfPayload['closing_balance'], $rangePayload['closing_balance']);
    }

    /** #194 — credit notes surface as credit rows and populate total_credits. */
    public function test_credit_notes_appear_in_the_statement(): void
    {
        $invoice = $this->invoice(['balance' => 700, 'paid_amount' => 300, 'status' => 'partial']);
        $this->creditNote($invoice, 300);

        $service = app(CustomerStatementService::class);
        $payload = $service->buildAsOfPayload($this->customer, Carbon::parse('2026-01-31'));

        $creditRows = collect($payload['transactions'])->where('type', 'Credit Note');
        $this->assertCount(1, $creditRows);
        $this->assertEquals(300.0, (float) $creditRows->first()['credit']);
        $this->assertEquals(700.0, $payload['closing_balance']);
        $this->assertEquals(300.0, $payload['total_credits']);
    }

    /** #199 — include_paid=false must not emit payments whose invoice was dropped. */
    public function test_settled_invoice_and_its_payment_are_both_excluded(): void
    {
        $settled = $this->invoice(['balance' => 0, 'paid_amount' => 1000, 'status' => 'paid', 'settlement_status' => 'paid']);
        $this->payment($settled, 1000);

        $outstanding = $this->invoice(['invoice_number' => 'INV-2026-0002', 'balance' => 500, 'total' => 500]);

        $service = app(CustomerStatementService::class);
        $payload = $service->buildRangePayload(
            $this->customer,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
            false,
        );

        $transactions = collect($payload['transactions']);
        $this->assertCount(0, $transactions->where('type', 'Payment'), 'Orphan payment credit leaked into the statement.');
        $this->assertCount(1, $transactions->where('type', 'Invoice'));
        $this->assertEquals(500.0, $payload['closing_balance']);
    }

    /** #196 — accounting-posted invoices with an operational partial/overdue status must be included. */
    public function test_partial_operational_status_is_included_in_outstanding(): void
    {
        $invoice = $this->invoice([
            'status' => 'partial',
            'balance' => 250,
            'paid_amount' => 750,
            'settlement_status' => 'partially_paid',
        ]);
        $this->payment($invoice, 750);

        $service = app(CustomerStatementService::class);
        $payload = $service->buildAsOfPayload($this->customer, Carbon::parse('2026-01-31'));

        $this->assertCount(1, collect($payload['transactions'])->where('type', 'Invoice'));
        $this->assertEquals(250.0, $payload['closing_balance']);
    }

    /** #203 — a missing due date in a legacy frozen payload must bucket as current, never crash aging. */
    public function test_aging_handles_missing_due_date_in_frozen_payload(): void
    {
        $statement = CustomerStatement::create([
            'customer_id' => $this->customer->id,
            'start_date' => null,
            'end_date' => '2026-01-31',
            'period_type' => 'as_of',
            'include_paid' => false,
            'generated_by' => $this->user->id,
            'payload' => [
                'transactions' => [[
                    'date' => '2026-01-10',
                    'type' => 'Invoice',
                    'document_number' => 'INV-LEGACY-1',
                    'due_date' => '',
                    'debit' => 400,
                    'credit' => 0,
                    'balance' => 400,
                ]],
            ],
        ]);

        $data = (new \Modules\TemplateBuilder\Services\DataSource\CustomerStatementDataSource)
            ->load((string) $statement->id)
            ->toArray();

        $this->assertEquals(400.0, $data['aging']['current']);
        $this->assertEquals(400.0, $data['aging']['total']);
    }

    /** #198 — aging and the transaction list describe the same document set. */
    public function test_aging_total_matches_outstanding_invoice_rows(): void
    {
        $this->invoice(['due_date' => '2026-01-05', 'total' => 300, 'balance' => 300]);   // overdue
        $this->invoice([
            'invoice_number' => 'INV-2026-0002',
            'due_date' => '2026-03-01',
            'total' => 700,
            'balance' => 700,
        ]); // current

        $service = app(CustomerStatementService::class);
        $payload = $service->buildAsOfPayload($this->customer, Carbon::parse('2026-01-31'));

        $this->assertEquals(1000.0, $payload['aging']['total']);
        $this->assertEquals(1000.0, $payload['closing_balance']);
        $this->assertEquals(300.0, $payload['aging']['days_1_30']);
        $this->assertEquals(700.0, $payload['aging']['current']);
    }

    /** #200 — the AR view must not report draft credit notes as applied credits. */
    public function test_ar_transactions_view_excludes_draft_credit_notes(): void
    {
        $invoice = $this->invoice();
        $this->creditNote($invoice, 300, 'draft');
        $this->creditNote($invoice, 150, 'posted');

        $rows = DB::table('ar_transactions')
            ->where('customer_id', $this->customer->id)
            ->where('transaction_type', 'credit_note')
            ->pluck('credit')
            ->map(fn ($value) => (float) $value)
            ->all();

        $this->assertSame([150.0], $rows, 'The AR view must only expose posted credit notes.');
    }

    /** #193/#194/#202 — comparison endpoint uses canonical math and reports the tenant currency. */
    public function test_comparison_endpoint_reports_currency_and_canonical_totals(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice, 400);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/accounting/customers/'.$this->customer->id.'/statement/compare?dates=2026-01-15,2026-01-31'
        );

        $response->assertOk();
        $this->assertNotEmpty($response->json('currency'));
        $this->assertEquals(600.0, (float) $response->json('comparisons.1.total_outstanding'));
        $this->assertEquals(1, $response->json('comparisons.1.invoice_count'));
    }

    /** #195 — the statement reads must carry the entity middleware. */
    public function test_statement_routes_are_entity_scoped(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        foreach ([
            'api/v1/accounting/customers/{customer}/statement',
            'api/v1/accounting/customers/{customer}/statement/compare',
            'api/v1/accounting/customers/{customer}/statement/compare/pdf',
        ] as $uri) {
            $route = $routes->first(fn ($candidate) => $candidate->uri() === $uri && in_array('GET', $candidate->methods(), true));

            $this->assertNotNull($route, "Route {$uri} is not registered.");
            $this->assertContains(\App\Http\Middleware\ResolveEntityContext::class, $route->middleware(), "{$uri} lacks ResolveEntityContext.");
            $this->assertContains(\App\Http\Middleware\EnsureEntityAccess::class, $route->middleware(), "{$uri} lacks EnsureEntityAccess.");
        }
    }

    /** #204 — the opening balance is seeded into the running balance AND encoded in the synthetic row; it must count once. */
    public function test_opening_balance_is_not_double_counted_in_running_balance(): void
    {
        $this->invoice(['invoice_date' => '2025-12-01', 'due_date' => '2025-12-31']);
        $inRange = $this->invoice(['invoice_date' => '2026-01-10']);
        $this->payment($inRange, 300, '2026-01-20');

        $payload = app(CustomerStatementService::class)->buildRangePayload(
            $this->customer,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
            true,
        );

        $this->assertSame(1000.0, round($payload['opening_balance'], 2));
        $this->assertSame(1700.0, round($payload['closing_balance'], 2));

        $balances = collect($payload['transactions'])->pluck('balance', 'type');
        $this->assertSame(1000.0, round((float) $balances['Opening Balance'], 2), 'Opening row must show the opening balance once');
        $this->assertSame(2000.0, round((float) $balances['Invoice'], 2));
        $this->assertSame(1700.0, round((float) $balances['Payment'], 2));

        // #193 invariant: the range statement and the as-of snapshot ending on
        // the same date must agree, even with a pre-range invoice.
        $asOf = app(CustomerStatementService::class)->buildAsOfPayload($this->customer, Carbon::parse('2026-01-31'));
        $this->assertSame(1700.0, round($asOf['closing_balance'], 2));
    }

    /** #205 — a payment cancelled via unapplyPayment must not count as a credit. */
    public function test_unapplied_payments_do_not_count_as_credits(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice, 1000, '2026-01-20', 'unapplied');

        $payload = app(CustomerStatementService::class)->buildRangePayload(
            $this->customer,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
            true,
        );

        $this->assertSame(1000.0, round($payload['closing_balance'], 2));
        $this->assertSame(0.0, round($payload['total_credits'], 2));
        $this->assertNotContains('Payment', collect($payload['transactions'])->pluck('type')->all());
    }

    /** #207 — draft ('recorded', still editable) payments are not real credits. */
    public function test_recorded_draft_payments_do_not_count_as_credits(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice, 1000, '2026-01-20', 'recorded');

        $payload = app(CustomerStatementService::class)->buildRangePayload(
            $this->customer,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
            true,
        );

        $this->assertSame(1000.0, round($payload['closing_balance'], 2));
        $this->assertSame(0.0, round($payload['total_credits'], 2));
    }

    /** #206 — a settlement of a pre-range invoice must still reduce the running balance. */
    public function test_in_period_settlement_of_a_pre_period_invoice_reduces_the_balance(): void
    {
        $old = $this->invoice(['invoice_date' => '2025-12-01', 'due_date' => '2025-12-31']);
        $this->payment($old, 1000, '2026-01-15');

        $payload = app(CustomerStatementService::class)->buildRangePayload(
            $this->customer,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
            false,
        );

        $this->assertSame(1000.0, round($payload['opening_balance'], 2));
        $this->assertSame(0.0, round($payload['closing_balance'], 2), 'The settlement must clear the opening balance');
        $this->assertContains('Payment', collect($payload['transactions'])->pluck('type')->all());
    }

    /** #206 — same rule for credit notes applied in-period against a pre-range invoice. */
    public function test_in_period_credit_note_against_a_pre_period_invoice_reduces_the_balance(): void
    {
        $old = $this->invoice(['invoice_date' => '2025-12-01', 'due_date' => '2025-12-31']);
        $this->creditNote($old, 1000, 'posted', '2026-01-15');

        $payload = app(CustomerStatementService::class)->buildRangePayload(
            $this->customer,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
            false,
        );

        $this->assertSame(1000.0, round($payload['opening_balance'], 2));
        $this->assertSame(0.0, round($payload['closing_balance'], 2));
        $this->assertContains('Credit Note', collect($payload['transactions'])->pluck('type')->all());
    }

    /** #208 — the shared company/customer/bank context must be computed once per comparison request, not once per date. */
    public function test_compare_computes_the_shared_context_once(): void
    {
        $this->invoice();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($this->user)->getJson(
            '/api/v1/accounting/customers/'.$this->customer->id.'/statement/compare?dates=2026-01-15,2026-01-31'
        )->assertOk();

        $contextQueries = collect($queries)
            ->filter(fn ($sql) => str_contains($sql, 'bank_accounts') || str_contains($sql, 'from "settings"'))
            ->count();

        $this->assertSame(2, $contextQueries, 'context() must run exactly once (settings + bank_accounts) for all comparison dates');
    }
}
