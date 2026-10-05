<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerStatementMail;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerStatement;
use App\Models\CustomerStatementBatch;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Tests\TenantTestCase;

/**
 * Issue #607 (RPT-F1) — Sales-side "Statements" tab.
 *
 * Router-level coverage for the new Sales statement surface: a SALES user can
 * generate/send one statement (history + send log) and run a batch, gated by
 * the existing customers.view permission (NOT reports.view) and scoped to the
 * user's accessible legal entities. Balances come from the canonical
 * CustomerStatementService, never a second computation.
 */
class Issue607SalesCustomerStatementsTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => $this->tenantId,
            'name' => 'Issue 607 Tenant',
        ]));

        $this->bindTenantContextWithPermissions($this->tenantId);

        $this->admin = $this->makeUser('admin');
        $this->customer = Customer::factory()->create(['name' => 'Statement Customer']);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            [
                'name' => $user->name,
                'password' => $user->password,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            [
                'role' => $role,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $user->assignRole(\App\Enums\UserRole::tryFrom($role)->spatieName());

        return $user;
    }

    private function invoiceFor(Customer $customer, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
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
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function paymentFor(Customer $customer, Invoice $invoice, float $amount): Payment
    {
        return Payment::factory()->create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => '2026-01-20',
            'amount' => $amount,
            'payment_status' => 'applied',
            'received_by' => $this->admin->id,
        ]);
    }

    private function creditNoteFor(Customer $customer, Invoice $invoice, float $amount): CustomerCreditNote
    {
        return CustomerCreditNote::create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => '2026-01-25',
            'status' => 'posted',
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'reason' => 'Issue 607 credit',
        ]);
    }

    private function activeWhatsAppKey(): WhatsAppApiKey
    {
        return WhatsAppApiKey::create([
            'key' => 'issue607-test-whatsapp-key',
            'name' => 'Issue 607 WhatsApp Key',
            'whatsapp_provider' => 'custom',
            'api_endpoint' => 'https://wa.example.test/send',
            'is_active' => true,
        ]);
    }

    // ── Single: generate + send ────────────────────────────────────────────

    public function test_sales_user_can_generate_and_send_a_statement_with_history_and_send_log(): void
    {
        Mail::fake();

        $sales = $this->makeUser('sales');
        $this->invoiceFor($this->customer);

        $response = $this->actingAs($sales)->postJson(
            '/api/v1/accounting/customer-statements/send',
            [
                'customer_id' => $this->customer->id,
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-31',
                'channel' => 'email',
            ]
        );

        $response->assertOk();

        $statementId = (int) $response->json('id');
        $this->assertGreaterThan(0, $statementId, 'The send endpoint must return the persisted statement.');

        Mail::assertSent(CustomerStatementMail::class, function (CustomerStatementMail $mail) {
            return (int) $mail->statement->customer_id === (int) $this->customer->id;
        });

        $statement = CustomerStatement::findOrFail($statementId);
        $this->assertTrue((bool) $statement->email_sent);
        $this->assertSame($this->customer->email, $statement->recipient_email);
        $this->assertSame(1000.0, (float) $statement->payload['closing_balance']);

        $log = DB::table('customer_statement_deliveries')
            ->where('customer_statement_id', $statementId)
            ->first();

        $this->assertNotNull($log, 'A send-log row must be recorded for the statement delivery.');
        $this->assertSame('email', $log->channel);
        $this->assertSame('sent', $log->status);
        $this->assertSame($this->customer->email, $log->recipient);

        // The statement is visible in the existing statement history surface.
        $history = $this->actingAs($sales)->getJson(
            '/api/v1/accounting/customers/statements/history?customer_id='.$this->customer->id
        );
        $history->assertOk();
        $this->assertContains(
            $statementId,
            collect($history->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_sales_user_can_send_a_statement_over_whatsapp_with_a_send_log(): void
    {
        Http::fake();

        $sales = $this->makeUser('sales');
        $this->activeWhatsAppKey();
        $this->customer->forceFill(['mobile' => '+60123456789', 'phone' => null])->save();
        $this->invoiceFor($this->customer);

        $response = $this->actingAs($sales)->postJson(
            '/api/v1/accounting/customer-statements/send',
            [
                'customer_id' => $this->customer->id,
                'as_of' => '2026-01-31',
                'channel' => 'whatsapp',
            ]
        );

        $response->assertOk();

        Http::assertSent(fn ($request) => str_contains((string) $request['message'], 'Statement of Account')
            && str_contains((string) $request['message'], (string) $this->customer->code));

        $log = DB::table('customer_statement_deliveries')
            ->where('customer_statement_id', (int) $response->json('id'))
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('whatsapp', $log->channel);
        $this->assertSame('sent', $log->status);
    }

    // ── Batch: preview ─────────────────────────────────────────────────────

    public function test_batch_preview_with_balance_lists_only_customers_owing(): void
    {
        $owing = Customer::factory()->create(['name' => 'Owing Customer']);
        $this->invoiceFor($owing, ['total' => 1000, 'balance' => 1000]);

        $settled = Customer::factory()->create(['name' => 'Settled Customer']);
        $settledInvoice = $this->invoiceFor($settled, ['total' => 800, 'balance' => 0, 'paid_amount' => 800, 'status' => 'paid']);
        $this->paymentFor($settled, $settledInvoice, 800);

        Customer::factory()->create(['name' => 'No Invoice Customer']);

        $response = $this->actingAs($this->admin)->postJson(
            '/api/v1/accounting/customer-statements/batch/preview',
            [
                'with_balance' => true,
                'as_of' => '2026-01-31',
            ]
        );

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $owing->id, $ids);
        $this->assertNotContains((int) $settled->id, $ids);
        $this->assertNotContains((int) $this->customer->id, $ids);

        $row = collect($response->json('data'))->firstWhere('id', $owing->id);
        $this->assertNotNull($row);
        $this->assertSame(1000.0, round((float) $row['closing_balance'], 2));
        $this->assertSame(0.0, round((float) $row['overdue_amount'], 2));
    }

    public function test_batch_preview_overdue_filter_uses_the_oldest_overdue_invoice(): void
    {
        $veryOverdue = Customer::factory()->create(['name' => 'Very Overdue']);
        $this->invoiceFor($veryOverdue, ['due_date' => '2025-12-15', 'total' => 500, 'balance' => 500]);

        $recentlyOverdue = Customer::factory()->create(['name' => 'Recently Overdue']);
        $this->invoiceFor($recentlyOverdue, ['due_date' => '2026-01-25', 'total' => 500, 'balance' => 500]);

        $response = $this->actingAs($this->admin)->postJson(
            '/api/v1/accounting/customer-statements/batch/preview',
            [
                'overdue_days' => 30,
                'as_of' => '2026-01-31',
            ]
        );

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $veryOverdue->id, $ids);
        $this->assertNotContains((int) $recentlyOverdue->id, $ids);

        $row = collect($response->json('data'))->firstWhere('id', $veryOverdue->id);
        $this->assertGreaterThan(30, (int) $row['oldest_overdue_days']);
        $this->assertSame(500.0, round((float) $row['overdue_amount'], 2));
    }

    // ── Batch: generate + send (queued job + progress) ─────────────────────

    public function test_batch_send_generates_one_statement_per_customer_with_progress_and_send_log(): void
    {
        Mail::fake();

        $first = Customer::factory()->create(['name' => 'Batch One']);
        $second = Customer::factory()->create(['name' => 'Batch Two']);
        $this->invoiceFor($first, ['total' => 1000, 'balance' => 1000]);
        $this->invoiceFor($second, ['total' => 600, 'balance' => 600, 'invoice_number' => 'INV-2026-6072']);

        // A zero-balance customer must not pollute the batch.
        Customer::factory()->create(['name' => 'Batch Zero']);

        $response = $this->actingAs($this->admin)->postJson(
            '/api/v1/accounting/customer-statements/batch',
            [
                'with_balance' => true,
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-31',
                'channel' => 'email',
            ]
        );

        $response->assertStatus(202);

        $batchId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $batchId);

        $batch = CustomerStatementBatch::findOrFail($batchId);
        $this->assertSame(CustomerStatementBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(2, (int) $batch->total_customers);
        $this->assertSame(2, (int) $batch->processed_customers);
        $this->assertSame(2, (int) $batch->generated_count);
        $this->assertSame(2, (int) $batch->sent_count);
        $this->assertSame(0, (int) $batch->failed_count);

        $this->assertSame(
            2,
            CustomerStatement::query()
                ->whereIn('customer_id', [$first->id, $second->id])
                ->count(),
            'One statement per customer must be recorded in history.'
        );

        $this->assertSame(
            2,
            DB::table('customer_statement_deliveries')
                ->where('customer_statement_batch_id', $batchId)
                ->where('status', 'sent')
                ->count(),
            'Each batch statement must have its send-log row.'
        );

        Mail::assertSent(CustomerStatementMail::class, 2);

        $progress = $this->actingAs($this->admin)->getJson(
            '/api/v1/accounting/customer-statements/batches/'.$batchId
        );
        $progress->assertOk();
        $progress->assertJsonPath('data.status', CustomerStatementBatch::STATUS_COMPLETED);
        $progress->assertJsonPath('data.processed_customers', 2);
    }

    public function test_fully_paid_invoices_are_excluded_by_default_and_included_when_requested(): void
    {
        Mail::fake();

        $paid = $this->invoiceFor($this->customer, [
            'total' => 500,
            'balance' => 0,
            'paid_amount' => 500,
            'status' => 'paid',
        ]);
        $this->paymentFor($this->customer, $paid, 500);
        $this->invoiceFor($this->customer, [
            'invoice_number' => 'INV-2026-6073',
            'total' => 700,
            'balance' => 700,
        ]);

        $excluded = $this->actingAs($this->admin)->postJson(
            '/api/v1/accounting/customer-statements/send',
            [
                'customer_id' => $this->customer->id,
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-31',
                'channel' => 'email',
                'include_paid' => false,
            ]
        );

        $excluded->assertOk();

        $excludedStatement = CustomerStatement::findOrFail((int) $excluded->json('id'));
        $excludedTypes = collect($excludedStatement->payload['transactions'])->pluck('type')->all();
        $this->assertContains('Invoice', $excludedTypes);
        $this->assertNotContains('Payment', $excludedTypes, 'A settled invoice and its payment must drop out together.');
        $this->assertSame(700.0, (float) $excludedStatement->payload['closing_balance']);

        $included = $this->actingAs($this->admin)->postJson(
            '/api/v1/accounting/customer-statements/send',
            [
                'customer_id' => $this->customer->id,
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-31',
                'channel' => 'email',
                'include_paid' => true,
            ]
        );

        $included->assertOk();

        $includedStatement = CustomerStatement::findOrFail((int) $included->json('id'));
        $includedTypes = collect($includedStatement->payload['transactions'])->pluck('type')->all();
        $this->assertContains('Payment', $includedTypes, 'include_paid=true must show the settled invoice and its payment.');
        $this->assertCount(2, collect($includedStatement->payload['transactions'])->where('type', 'Invoice'));
        $this->assertSame(700.0, (float) $includedStatement->payload['closing_balance']);
    }

    // ── Money correctness ──────────────────────────────────────────────────

    public function test_statement_closing_balance_equals_the_ar_subledger_balance(): void
    {
        Mail::fake();

        $invoice = $this->invoiceFor($this->customer, ['total' => 1000, 'balance' => 1000]);
        $this->paymentFor($this->customer, $invoice, 400);
        $this->creditNoteFor($this->customer, $invoice, 100);

        $response = $this->actingAs($this->admin)->postJson(
            '/api/v1/accounting/customer-statements/send',
            [
                'customer_id' => $this->customer->id,
                'as_of' => '2026-01-31',
                'channel' => 'email',
            ]
        );

        $response->assertOk();

        $statement = CustomerStatement::findOrFail((int) $response->json('id'));
        $this->assertSame(500.0, (float) $statement->payload['closing_balance']);

        $subledger = DB::table('ar_transactions')
            ->where('customer_id', $this->customer->id)
            ->whereIn('status', ['sent', 'posted', 'partial', 'overdue', 'paid'])
            ->where('transaction_date', '<=', '2026-01-31')
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as balance')
            ->value('balance');

        $this->assertSame(
            round((float) $subledger, 2),
            round((float) $statement->payload['closing_balance'], 2),
            'The statement closing balance must equal the AR subledger balance as of the same date.'
        );
    }

    // ── Permissions ────────────────────────────────────────────────────────

    public function test_user_without_customers_view_is_denied(): void
    {
        $cashier = $this->makeUser('cashier');
        $this->invoiceFor($this->customer);

        $this->actingAs($cashier)->postJson(
            '/api/v1/accounting/customer-statements/send',
            [
                'customer_id' => $this->customer->id,
                'as_of' => '2026-01-31',
                'channel' => 'email',
            ]
        )->assertForbidden();

        $this->actingAs($cashier)->postJson(
            '/api/v1/accounting/customer-statements/batch/preview',
            ['with_balance' => true]
        )->assertForbidden();

        $this->actingAs($cashier)->postJson(
            '/api/v1/accounting/customer-statements/batch',
            ['channel' => 'email', 'as_of' => '2026-01-31']
        )->assertForbidden();
    }

    // ── Entity scoping ─────────────────────────────────────────────────────

    public function test_batch_preview_and_single_send_are_scoped_to_the_users_entities(): void
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 607 Group',
            'code' => 'I607-'.Str::upper(Str::random(4)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entityA = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity A 607',
            'code' => 'A607-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ]);
        $entityB = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity B 607',
            'code' => 'B607-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ]);

        $sales = $this->makeUser('sales');

        DB::table('user_entity_access')->insert([
            'user_id' => $sales->id,
            'legal_entity_id' => $entityA->id,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inScope = Customer::factory()->create(['name' => 'In Scope', 'legal_entity_id' => $entityA->id]);
        $outOfScope = Customer::factory()->create(['name' => 'Out Of Scope', 'legal_entity_id' => $entityB->id]);
        $this->invoiceFor($inScope, ['invoice_number' => 'INV-2026-6074']);
        $this->invoiceFor($outOfScope, ['invoice_number' => 'INV-2026-6075']);

        $preview = $this->actingAs($sales)->postJson(
            '/api/v1/accounting/customer-statements/batch/preview',
            ['with_balance' => true, 'as_of' => '2026-01-31']
        );

        $preview->assertOk();
        $ids = collect($preview->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $inScope->id, $ids);
        $this->assertNotContains((int) $outOfScope->id, $ids, 'Out-of-entity customers must not appear.');

        // The out-of-scope customer's statement must not be generatable either.
        $this->actingAs($sales)->postJson(
            '/api/v1/accounting/customer-statements/send',
            [
                'customer_id' => $outOfScope->id,
                'as_of' => '2026-01-31',
                'channel' => 'email',
            ]
        )->assertNotFound();

        Mail::fake();
        $this->actingAs($sales)->postJson(
            '/api/v1/accounting/customer-statements/send',
            [
                'customer_id' => $inScope->id,
                'as_of' => '2026-01-31',
                'channel' => 'email',
            ]
        )->assertOk();
    }
}
