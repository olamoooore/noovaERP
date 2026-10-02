<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\DomainPostingLine;
use App\Accounting\DTOs\DomainPostingRequest;
use App\Accounting\PostingEngine;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PaymentRefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\Sales\Services\DeliveryOrderService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #875 (P0, SA1): Sales journal entries were posted with no legal
 * entity. InvoiceService::postInvoice() built its DomainPostingRequest with
 * entityId: '' / accountingBookId: '', and the payment, credit-note, refund
 * and delivery-order COGS posting services built PostingRequest without
 * legalEntityId — so journal_entries.legal_entity_id was NULL for the whole
 * Sales channel. Entity-filtered reports and entity-scoped users (accessibleBy
 * fails closed on NULL) therefore never saw those postings.
 *
 * These tests go through the real HTTP routes (router -> middleware ->
 * controller) in a multi-entity tenant.
 */
class HR875SalesPostingLegalEntityTest extends TenantTestCase
{
    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private int $bookA;

    private int $bookB;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'HR875 Sales Entity Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'HR875 Group',
            'code' => 'HR875-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => '875-A-'.Str::upper(Str::random(5)),
            'country_code' => 'US',
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity B',
            'code' => '875-B-'.Str::upper(Str::random(5)),
            'country_code' => 'US',
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bookA = DB::table('entity_books')->insertGetId([
            'legal_entity_id' => $this->entityA,
            'name' => 'Primary Book A',
            'book_type' => 'general',
            'currency_code' => 'USD',
            'is_primary' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bookB = DB::table('entity_books')->insertGetId([
            'legal_entity_id' => $this->entityB,
            'name' => 'Primary Book B',
            'book_type' => 'general',
            'currency_code' => 'USD',
            'is_primary' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->accountant = $this->makeUser('accountant', $this->entityA);

        $suffix = Str::upper(Str::random(6));
        $cash = ChartOfAccount::factory()->create(['code' => "1000-{$suffix}", 'type' => 'asset', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => "1100-{$suffix}", 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => "4000-{$suffix}", 'type' => 'revenue', 'is_active' => true]);
        $cogs = ChartOfAccount::factory()->create(['code' => "5100-{$suffix}", 'type' => 'expense', 'is_active' => true]);
        $inventory = ChartOfAccount::factory()->create(['code' => "1200-{$suffix}", 'type' => 'asset', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'cash', 'account_id' => $cash->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'cogs', 'account_id' => $cogs->id]);
        AccountMapping::create(['mapping_key' => 'inventory', 'account_id' => $inventory->id]);
    }

    private function makeUser(string $role, ?int $defaultEntityId): User
    {
        $user = User::factory()->create();
        $user->assignRole(ucfirst($role));

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($defaultEntityId !== null) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $defaultEntityId,
                'role' => 'accountant',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function createInvoiceViaApi(int $entityId): Invoice
    {
        $customer = Customer::factory()->create([
            'legal_entity_id' => $entityId,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->accountant)
            ->withHeaders(['X-Entity-Id' => (string) $entityId])
            ->postJson('/api/v1/invoices', [
                'customer_id' => $customer->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'lines' => [[
                    'line_type' => 'product',
                    'description' => 'HR875 Widget',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ]],
            ]);

        $response->assertCreated($response->getContent());

        return Invoice::findOrFail($response->json('id'));
    }

    private function postInvoiceViaApi(Invoice $invoice, int $entityId): void
    {
        $response = $this->actingAs($this->accountant)
            ->withHeaders(['X-Entity-Id' => (string) $entityId])
            ->postJson("/api/v1/invoices/{$invoice->id}/post");

        $response->assertOk($response->getContent());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Invoice: document + journal entry carry the entity
    // ─────────────────────────────────────────────────────────────────────

    public function test_invoice_is_stamped_with_entity_and_book_at_creation(): void
    {
        $invoice = $this->createInvoiceViaApi($this->entityA);

        $this->assertSame(
            $this->entityA,
            (int) $invoice->legal_entity_id,
            'Invoice must be stamped with the sales order / entity context at creation'
        );
        $this->assertSame(
            $this->bookA,
            (int) $invoice->entity_book_id,
            'Invoice must carry the entity primary book'
        );
    }

    public function test_invoice_posting_writes_journal_entry_with_the_invoice_entity(): void
    {
        $invoice = $this->createInvoiceViaApi($this->entityA);
        $this->postInvoiceViaApi($invoice, $this->entityA);

        $invoice->refresh();
        $this->assertNotNull($invoice->journal_entry_id, 'Posting must create a journal entry');

        $entry = JournalEntry::findOrFail($invoice->journal_entry_id);

        $this->assertSame(
            $this->entityA,
            (int) $entry->legal_entity_id,
            'Sales invoice journal entry must carry the invoice legal entity, not NULL'
        );
        $this->assertSame(
            $this->bookA,
            (int) $entry->entity_book_id,
            'Sales invoice journal entry must carry the entity book'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Payment: document + journal entry carry the entity
    // ─────────────────────────────────────────────────────────────────────

    public function test_payment_posting_writes_journal_entry_with_the_entity(): void
    {
        $invoice = $this->createInvoiceViaApi($this->entityA);
        $this->postInvoiceViaApi($invoice, $this->entityA);

        $response = $this->actingAs($this->accountant)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'payment_date' => now()->toDateString(),
                'amount' => 100,
                'method' => 'cash',
            ]);

        $response->assertCreated($response->getContent());
        $paymentId = (int) $response->json('id');

        $payment = Payment::findOrFail($paymentId);
        $this->assertSame(
            $this->entityA,
            (int) $payment->legal_entity_id,
            'Payment must inherit the invoice entity at creation'
        );

        $postResponse = $this->actingAs($this->accountant)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->postJson("/api/v1/payments/{$paymentId}/post");
        $postResponse->assertOk($postResponse->getContent());

        $entry = JournalEntry::findOrFail($payment->fresh()->journal_entry_id);
        $this->assertSame(
            $this->entityA,
            (int) $entry->legal_entity_id,
            'Payment journal entry must carry the entity'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Customer credit note: document + journal entry carry the entity
    // ─────────────────────────────────────────────────────────────────────

    public function test_credit_note_posting_writes_journal_entry_with_the_entity(): void
    {
        $invoice = $this->createInvoiceViaApi($this->entityA);
        $this->postInvoiceViaApi($invoice, $this->entityA);

        $response = $this->actingAs($this->accountant)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->postJson('/api/v1/accounting/customers/credit-notes', [
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'credit_note_date' => now()->toDateString(),
                'lines' => [[
                    'description' => 'HR875 credit',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                ]],
            ]);

        $response->assertCreated($response->getContent());
        $creditNoteId = (int) $response->json('id');

        $creditNote = DB::table('customer_credit_notes')->where('id', $creditNoteId)->first();
        $this->assertSame(
            $this->entityA,
            (int) $creditNote->legal_entity_id,
            'Credit note must inherit the invoice entity at creation'
        );

        $postResponse = $this->actingAs($this->accountant)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/post");
        $postResponse->assertOk($postResponse->getContent());

        $creditNote = DB::table('customer_credit_notes')->where('id', $creditNoteId)->first();
        $entry = JournalEntry::findOrFail($creditNote->journal_entry_id);
        $this->assertSame(
            $this->entityA,
            (int) $entry->legal_entity_id,
            'Credit note journal entry must carry the entity'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Delivery order COGS: journal entry carries the entity
    // ─────────────────────────────────────────────────────────────────────

    public function test_delivery_order_cogs_posting_writes_journal_entry_with_the_entity(): void
    {
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
                'created_by' => $this->accountant->id,
            ]
        );

        $warehouse = Warehouse::create([
            'name' => 'HR875 Warehouse',
            'code' => '875-WH-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $product = Product::factory()->create([
            'track_inventory' => true,
            'price' => 20,
            'cost' => 5.00,
        ]);

        StockLevel::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        $customer = Customer::factory()->create([
            'legal_entity_id' => $this->entityA,
            'is_active' => true,
        ]);

        // DO COGS is a sales.write operation (PostingEngine document-type map).
        $admin = $this->makeUser('admin', $this->entityA);
        $this->actingAs($admin);

        $service = app(DeliveryOrderService::class);
        $deliveryOrder = $service->createStandaloneDeliveryOrder([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $this->accountant->id,
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'HR875 COGS line',
                'quantity_delivered' => 2,
                'unit' => 'pcs',
            ]],
        ]);
        $service->completeDeliveryOrder($deliveryOrder);

        $link = JournalEntryLink::where('source_document_type', 'delivery_order')
            ->where('source_document_id', $deliveryOrder->id)
            ->first();

        $this->assertNotNull($link, 'DO completion must post a COGS journal entry');

        $entry = JournalEntry::findOrFail($link->journal_entry_id);
        $this->assertSame(
            $this->entityA,
            (int) $entry->legal_entity_id,
            'Delivery order COGS journal entry must carry the entity'
        );
        $this->assertSame(
            $this->bookA,
            (int) $entry->entity_book_id,
            'Delivery order COGS journal entry must carry the entity book'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Standalone refund: journal entry + refund payment carry the entity
    // ─────────────────────────────────────────────────────────────────────

    public function test_refund_posting_writes_journal_entry_with_the_entity(): void
    {
        $invoice = $this->createInvoiceViaApi($this->entityA);

        $gateway = PaymentGateway::factory()->create(['gateway_name' => 'hitpay']);

        $transaction = PaymentTransaction::factory()->completed()->create([
            'payment_gateway_id' => $gateway->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount' => 100,
            'refunded_amount' => 0,
        ]);

        $result = app(PaymentRefundService::class)->processRefund(
            $transaction,
            100.0,
            'HR875 refund',
            $this->accountant->id,
        );

        $this->assertNotNull($result['refundEntry'], 'Refund must post a journal entry');
        $this->assertSame(
            $this->entityA,
            (int) $result['refundEntry']->legal_entity_id,
            'Refund journal entry must carry the invoice entity'
        );
        $this->assertSame(
            $this->entityA,
            (int) $result['refundPayment']->legal_entity_id,
            'Refund payment must carry the invoice entity'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Entity-filtered P&L includes the entity A posting (and excludes B)
    // ─────────────────────────────────────────────────────────────────────

    public function test_entity_filtered_profit_and_loss_includes_entity_a_postings(): void
    {
        $invoice = $this->createInvoiceViaApi($this->entityA);
        $this->postInvoiceViaApi($invoice, $this->entityA);

        $query = '?start_date='.now()->startOfMonth()->toDateString().'&end_date='.now()->toDateString();

        $entityAResponse = $this->actingAs($this->accountant)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->getJson('/api/v1/reports/financial/profit-loss'.$query.'&legal_entity_id='.$this->entityA);
        $entityAResponse->assertOk($entityAResponse->getContent());

        $entityBResponse = $this->actingAs($this->accountant)
            ->withHeaders(['X-Entity-Id' => (string) $this->entityA])
            ->getJson('/api/v1/reports/financial/profit-loss'.$query.'&legal_entity_id='.$this->entityB);
        $entityBResponse->assertOk($entityBResponse->getContent());

        $this->assertSame(
            100.0,
            (float) $entityAResponse->json('data.revenue.total'),
            'Entity A P&L must include the entity A sales invoice posting'
        );
        $this->assertSame(
            0.0,
            (float) $entityBResponse->json('data.revenue.total'),
            'Entity B P&L must NOT include the entity A posting'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Entity-scoped user sees the entity A journal entry
    // ─────────────────────────────────────────────────────────────────────

    public function test_entity_a_scoped_user_sees_the_posted_sales_journal_entry(): void
    {
        $invoice = $this->createInvoiceViaApi($this->entityA);
        $this->postInvoiceViaApi($invoice, $this->entityA);
        $invoice->refresh();

        $scopedUser = $this->makeUser('accountant', $this->entityA);

        $list = $this->actingAs($scopedUser)->getJson('/api/v1/accounting/journal-entries?per_page=100');
        $list->assertOk($list->getContent());

        $ids = collect($list->json('data'))->pluck('id');
        $this->assertTrue(
            $ids->contains($invoice->journal_entry_id),
            'An entity-A-scoped user must see the entity A sales invoice journal entry'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Engine: empty entity rejected when the tenant has entities
    // ─────────────────────────────────────────────────────────────────────

    public function test_domain_posting_rejects_an_empty_entity_when_the_tenant_has_entities(): void
    {
        /** @var PostingEngine $engine */
        $engine = app(PostingEngine::class);

        $result = $engine->domainPosting(new DomainPostingRequest(
            postingRequestId: DomainPostingRequest::generateRequestId(),
            idempotencyKey: 'hr875-empty-entity-'.uniqid(),
            tenantId: $this->tenantId,
            entityId: '',
            accountingBookId: '',
            sourceCapability: 'ACC-003',
            sourceRecordType: 'invoice',
            sourceRecordId: '98765001',
            sourceRecordVersion: 1,
            postingProfileCode: 'AR_INVOICE_POST',
            transactionDate: now()->toDateString(),
            postingDate: now()->toDateString(),
            lines: [
                new DomainPostingLine(accountRole: 'accounts_receivable', amount: 10.0),
                new DomainPostingLine(accountRole: 'sales_revenue', amount: 10.0),
            ],
            userId: $this->accountant->id,
        ));

        $this->assertFalse(
            $result->success,
            'A domain posting with no entity must be rejected when the tenant has legal entities'
        );
        $this->assertStringContainsString(
            'ENTITY_CONTEXT_REQUIRED',
            (string) ($result->error ?? $result->message ?? ''),
            'The rejection must name the missing entity context'
        );
    }
}
