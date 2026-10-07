<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #324 ([SALES-ODOO]) — invoices link to the payment-terms master
 * (instalments, end-of-month) instead of a bare day count.
 *
 * Acceptance criteria:
 *  - Invoices (and quotes and Sales Orders) store a `payment_term_id`; the
 *    due date comes from the master's due-date calculation; the customer's
 *    default term flows onto the document.
 *  - Instalment schedules are visible on the invoice and used in
 *    receivables ageing.
 *
 * Everything runs through the real HTTP routes (router → middleware →
 * controller → service).
 */
class Issue324InvoicePaymentTermMasterTest extends TenantTestCase
{
    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $tenantId = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $tenantId,
            'name' => 'Issue 324 Payment Terms Tenant',
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

        foreach ([
            'cash' => '1010',
            'bank' => '1020',
            'accounts_receivable' => '1100',
            'sales_tax_payable' => '2200',
            'sales_revenue' => '4100',
        ] as $mappingKey => $accountCode) {
            if (DB::table('account_mappings')->where('mapping_key', $mappingKey)->whereNull('document_type')->exists()) {
                continue;
            }

            DB::table('account_mappings')->insert([
                'mapping_key' => $mappingKey,
                'account_id' => DB::table('chart_of_accounts')->where('code', $accountCode)->value('id'),
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->product = Product::factory()->create([
            'name' => 'Issue 324 Service',
            'sku' => 'I324-'.uniqid(),
            'price' => 100,
            'cost' => 10,
            'track_inventory' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'legal_entity_id' => null,
            'payment_terms' => 30,
            'credit_limit' => null,
            'total_outstanding' => 0,
        ], $attributes));
    }

    private function line(float $unitPrice = 1000): array
    {
        return [
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ];
    }

    private function endOfMonthPlus15(): PaymentTerm
    {
        return PaymentTerm::create([
            'name' => 'EOM + 15',
            'code' => 'EOM15-'.substr(uniqid(), -6),
            'type' => 'end_of_month',
            'days' => 15,
            'is_active' => true,
        ]);
    }

    /** Creates a two-instalment term through the master's own route. */
    private function createInstalmentTerm(): int
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/accounting/payment-terms', [
            'name' => '50% now, balance in 60 days',
            'code' => 'INST-'.substr(uniqid(), -6),
            'type' => 'net_days',
            'days' => 60,
            'lines' => [
                ['value_type' => 'percent', 'value' => 50, 'days' => 0, 'delay_type' => 'net_days'],
                ['value_type' => 'balance', 'days' => 60, 'delay_type' => 'net_days'],
            ],
        ]);
        $response->assertCreated();

        return (int) $response->json('term.id');
    }

    public function test_invoice_stores_master_term_and_due_date_comes_from_the_term(): void
    {
        $customer = $this->customer();
        $term = $this->endOfMonthPlus15();
        $invoiceDate = now()->toDateString();

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => $invoiceDate,
            'payment_term_id' => $term->id,
            'lines' => [$this->line()],
        ]);
        $response->assertCreated();

        $invoice = Invoice::query()->findOrFail((int) $response->json('id'));
        $this->assertSame($term->id, (int) $invoice->payment_term_id, 'The picked master term must be stored on the invoice');

        $expected = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/accounting/payment-terms/calculate-due-date', [
                'payment_term_id' => $term->id,
                'invoice_date' => $invoiceDate,
            ])->assertOk()->json('due_date');

        $this->assertSame(
            Carbon::parse($invoiceDate)->endOfMonth()->addDays(15)->toDateString(),
            $expected,
            'End-of-month + 15 must be computed from the end of the invoice month'
        );
        $this->assertSame($expected, $invoice->due_date->toDateString(), 'The invoice due date must come from calculate-due-date, not the customer day count');
    }

    public function test_changing_the_term_on_a_draft_recomputes_the_due_date(): void
    {
        $customer = $this->customer();
        $term = $this->endOfMonthPlus15();

        $invoiceId = (int) $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [$this->line()],
        ])->assertCreated()->json('id');

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/invoices/{$invoiceId}", [
            'payment_term_id' => $term->id,
        ])->assertOk();

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertSame($term->id, (int) $invoice->payment_term_id);
        $this->assertSame(
            $term->calculateDueDate($invoice->invoice_date->toDateString())->toDateString(),
            $invoice->due_date->toDateString()
        );
    }

    public function test_customer_default_term_flows_onto_invoice_quote_and_sales_order(): void
    {
        $term = $this->endOfMonthPlus15();
        $customer = $this->customer();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/customers/{$customer->id}", [
            'name' => $customer->name,
            'payment_term_id' => $term->id,
        ])->assertOk();
        $this->assertSame($term->id, (int) $customer->fresh()->payment_term_id, 'The customer stores a default master term');

        $invoiceId = (int) $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [$this->line()],
        ])->assertCreated()->json('id');

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertSame($term->id, (int) $invoice->payment_term_id, 'Customer default term → invoice');
        $this->assertSame(
            now()->endOfMonth()->addDays(15)->toDateString(),
            $invoice->due_date->toDateString(),
            'The due date follows the customer\'s master term, not its 30-day count'
        );

        $quoteResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/quotations', [
            'customer_id' => $customer->id,
            'quotation_date' => now()->toDateString(),
            'lines' => [array_merge($this->line(), ['line_type' => 'product'])],
        ])->assertCreated();
        $quotation = Quotation::query()->findOrFail((int) $quoteResponse->json('data.id'));
        $this->assertSame($term->id, (int) $quotation->payment_term_id, 'Customer default term → quotation');

        $orderResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sales/orders', [
            'customer_id' => $customer->id,
            'lines' => [$this->line()],
        ])->assertCreated();
        $order = SalesOrder::query()->findOrFail((int) $orderResponse->json('id'));
        $this->assertSame($term->id, (int) $order->payment_term_id, 'Customer default term → sales order');
    }

    public function test_sales_order_accepts_an_explicit_master_term(): void
    {
        $term = $this->endOfMonthPlus15();
        $customer = $this->customer();

        $orderResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sales/orders', [
            'customer_id' => $customer->id,
            'payment_term_id' => $term->id,
            'lines' => [$this->line()],
        ])->assertCreated();

        $order = SalesOrder::query()->findOrFail((int) $orderResponse->json('id'));
        $this->assertSame($term->id, (int) $order->payment_term_id);
    }

    public function test_instalment_schedule_is_visible_on_the_invoice(): void
    {
        $termId = $this->createInstalmentTerm();
        $customer = $this->customer();
        $invoiceDate = now()->toDateString();

        $calc = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/accounting/payment-terms/calculate-due-date', [
                'payment_term_id' => $termId,
                'invoice_date' => $invoiceDate,
            ])->assertOk();
        $this->assertSame(Carbon::parse($invoiceDate)->addDays(60)->toDateString(), $calc->json('due_date'), 'The final due date is the last instalment');
        $this->assertCount(2, $calc->json('instalments'));

        $invoiceId = (int) $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => $invoiceDate,
            'payment_term_id' => $termId,
            'lines' => [$this->line(1000)],
        ])->assertCreated()->json('id');

        $show = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/invoices/{$invoiceId}")->assertOk();

        $instalments = $show->json('instalments');
        $this->assertIsArray($instalments, 'The invoice exposes its instalment schedule');
        $this->assertCount(2, $instalments);

        $this->assertSame($invoiceDate, $instalments[0]['due_date']);
        $this->assertEqualsWithDelta(500.0, (float) $instalments[0]['amount'], 0.01);
        $this->assertSame(Carbon::parse($invoiceDate)->addDays(60)->toDateString(), $instalments[1]['due_date']);
        $this->assertEqualsWithDelta(500.0, (float) $instalments[1]['amount'], 0.01);

        $this->assertEqualsWithDelta(
            (float) $show->json('total'),
            array_sum(array_map(fn ($row) => (float) $row['amount'], $instalments)),
            0.001,
            'Instalments add up to the invoice total'
        );
        $this->assertSame(Carbon::parse($invoiceDate)->addDays(60)->toDateString(), Invoice::query()->findOrFail($invoiceId)->due_date->toDateString());
    }

    public function test_receivables_ageing_buckets_each_instalment_by_its_own_due_date(): void
    {
        $termId = $this->createInstalmentTerm();
        $customer = $this->customer();

        $invoiceId = (int) $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'payment_term_id' => $termId,
            'lines' => [$this->line(1000)],
        ])->assertCreated()->json('id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoiceId}/post")
            ->assertOk();

        $this->assertEqualsWithDelta(1000.0, (float) Invoice::query()->findOrFail($invoiceId)->balance, 0.01);

        // 45 days later: the first instalment (due on the invoice date) is
        // 45 days overdue; the balance instalment (due day 60) is not due yet.
        Carbon::setTestNow(now()->addDays(45));

        $report = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/accounting/reports/aged-receivables?customer_id='.$customer->id)
            ->assertOk();

        $row = collect($report->json('data.data'))->firstWhere('customer_id', $customer->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(500.0, (float) $row['days_31_60'], 0.01, 'The overdue instalment ages in 31–60');
        $this->assertEqualsWithDelta(500.0, (float) $row['current'], 0.01, 'The not-yet-due instalment stays current');
        $this->assertEqualsWithDelta(1000.0, (float) $row['total'], 0.01);

        $matrix = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/customers/{$customer->id}/aging")
            ->assertOk();
        $this->assertEqualsWithDelta(500.0, (float) $matrix->json('buckets.not_due.amount'), 0.01, 'Only the not-yet-due instalment is not due');
        $this->assertEqualsWithDelta(1000.0, (float) $matrix->json('total_outstanding'), 0.01);
    }
}
