<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Platform\EventBus\Contracts\DomainEventContract;
use App\Platform\EventBus\Contracts\EventHandlerContract;
use App\Platform\EventBus\Contracts\EventSubscriptionContract;
use App\Platform\EventBus\DomainEventDispatcher;
use App\Services\SalesApprovalGate;
use App\Services\SalesWorkflowService;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Sales\Events\SalesEventCatalogue;
use Modules\Sales\Models\SalesEventAuditLog;
use Modules\Sales\Models\SalesEventOutboxEvent;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Subscriptions\SalesEventSubscription;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #901 (PC7) — the Sales domain must publish versioned business events
 * through the platform Domain Event Bus, from the workflow transitions that
 * #900 centralized in SalesWorkflowService/WorkflowEngine.
 *
 * Emission uses the platform's transactional-outbox pattern: the event is
 * written atomically with the status change (so a rolled-back transition
 * leaves no event), and `sales:publish-outbox` dispatches it onto the bus
 * after commit, where subscriptions (AuditTrailHandler `*` and the Sales
 * audit consumer proof) process it. Consumers are idempotent by event id and
 * failures are dead-lettered through the platform EventDeadLetter store.
 */
class SalesDomainEventsTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Sales Domain Events Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => UserRole::ADMIN->value,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

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

        $this->seedPostingAccounts();

        $this->customer = Customer::factory()->create([
            'payment_terms' => 30,
            'credit_limit' => 100000,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Events Warehouse',
            'code' => 'EW-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Events Widget',
            'sku' => 'EW-'.uniqid(),
            'track_inventory' => true,
            'cost' => 10,
            'price' => 25,
            'stock_quantity' => 0,
        ]);

        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        // The Sales provider wires this subscription on tenancy initialization;
        // the test tenant is bound after app boot, so register it explicitly.
        app(DomainEventDispatcher::class)->subscribe(new SalesEventSubscription);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')->andReturnUsing(fn (string $tenantId, string $key) => [
            'key' => $key,
            'is_entitled' => $isEntitled,
            'source' => $isEntitled ? 'tier_included' : null,
            'name' => strtoupper($key),
        ]);

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    private function seedPostingAccounts(): void
    {
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
    }

    private function apiPost(string $uri, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson($uri, $payload);
    }

    private function draftDirectInvoice(float $quantity, float $taxRate = 0): Invoice
    {
        $response = $this->apiPost('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => $quantity,
                'unit_price' => 25,
                'tax_rate' => $taxRate,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        return Invoice::query()->findOrFail((int) $response->json('id'));
    }

    private function draftQuotation(): \App\Models\Quotation
    {
        $response = $this->apiPost('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 2,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        return \App\Models\Quotation::query()->findOrFail((int) $response->json('data.id'));
    }

    private function draftSalesOrder(): SalesOrder
    {
        $response = $this->apiPost('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 2,
                'unit_price' => 25,
                'tax_rate' => 0,
            ]],
        ]);
        $response->assertCreated();

        $order = SalesOrder::query()->findOrFail((int) $response->json('id'));
        $order->update(['warehouse_id' => $this->warehouse->id]);

        return $order;
    }

    private function publishOutbox(): void
    {
        $this->artisan('sales:publish-outbox')->assertSuccessful();
    }

    private function outboxRows(string $eventName)
    {
        return SalesEventOutboxEvent::query()->where('event_name', $eventName)->get();
    }

    private function outboxRowsForDocument(string $eventName, int|string $documentId)
    {
        return SalesEventOutboxEvent::query()
            ->where('event_name', $eventName)
            ->where('document_id', (string) $documentId)
            ->get();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Invoice posted — outbox + bus + proof consumer
    // ═════════════════════════════════════════════════════════════════════

    public function test_posting_an_invoice_records_one_invoice_posted_event_and_publishes_it_after_commit(): void
    {
        $invoice = $this->draftDirectInvoice(2, 10);

        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();

        // The outbox row is written inside the posting transaction — durable
        // before anything is published.
        $rows = $this->outboxRows(SalesEventCatalogue::INVOICE_POSTED);
        $this->assertCount(1, $rows, 'Exactly one invoice.posted.v1 outbox row per posted invoice.');
        $outbox = $rows->first();
        $this->assertSame((string) $invoice->id, (string) $outbox->document_id);
        $this->assertSame($invoice->invoice_number, $outbox->document_number);
        // Written inside the posting transaction, published after it commits.
        $this->assertNotNull($outbox->published_at, 'The outbox row must be published once the posting transaction commits.');

        $records = DB::table('domain_event_records')
            ->where('event_name', SalesEventCatalogue::INVOICE_POSTED)
            ->where('tenant_id', $this->tenantId)
            ->get();
        $this->assertCount(1, $records, 'The bus must hold exactly one invoice.posted.v1 record.');
        $record = $records->first();
        $this->assertSame('sales', $record->source_module);
        $this->assertSame('1.0.0', $record->version);

        $payload = json_decode($record->payload, true);
        $this->assertSame($outbox->id, $payload['event_id']);
        $this->assertSame('1.0.0', $payload['version']);
        $this->assertSame('invoice', $payload['document_type']);
        $this->assertSame((int) $invoice->id, (int) $payload['document_id']);
        $this->assertSame($invoice->invoice_number, $payload['document_number']);
        $this->assertEqualsWithDelta((float) $invoice->total, (float) $payload['total'], 0.001);
        $this->assertSame($invoice->currency_code, $payload['currency_code']);
        $this->assertSame($invoice->base_currency_code, $payload['base_currency_code']);
        $this->assertSame((int) $this->admin->id, (int) $payload['actor_user_id']);
        $this->assertSame('draft', $payload['status_before']);
        $this->assertSame('posted', $payload['status_after']);
        $this->assertNotEmpty($payload['occurred_at']);

        // Proof consumer: processed exactly once, keyed by the event id.
        $this->assertSame(1, SalesEventAuditLog::query()->where('event_id', $outbox->id)->count());

        // The scheduled sweep is idempotent: an already-published row is not
        // re-dispatched.
        $this->publishOutbox();
        $this->assertSame(1, DB::table('domain_event_records')
            ->where('event_name', SalesEventCatalogue::INVOICE_POSTED)
            ->count());
    }

    public function test_a_denied_transition_records_no_domain_event(): void
    {
        $invoice = $this->draftDirectInvoice(1);

        $this->assertSame(0, $this->outboxRows(SalesEventCatalogue::INVOICE_CANCELLED)->count());

        // A draft invoice cannot be cancelled — denied before the engine runs.
        $this->apiPost("/api/v1/invoices/{$invoice->id}/cancel")->assertStatus(403);

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame(0, $this->outboxRows(SalesEventCatalogue::INVOICE_CANCELLED)->count());
        $this->assertSame(0, DB::table('domain_event_records')
            ->where('event_name', SalesEventCatalogue::INVOICE_CANCELLED)
            ->count());
    }

    public function test_a_rolled_back_transition_leaves_no_outbox_row_or_bus_event(): void
    {
        $invoice = $this->draftDirectInvoice(1);

        DB::beginTransaction();

        try {
            $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();
            $this->assertSame(1, $this->outboxRows(SalesEventCatalogue::INVOICE_POSTED)->count(), 'The event is written with the posting transaction.');
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, $this->outboxRows(SalesEventCatalogue::INVOICE_POSTED)->count(), 'A rolled-back transition must leave no outbox row.');
        $this->assertSame(0, DB::table('domain_event_records')
            ->where('event_name', SalesEventCatalogue::INVOICE_POSTED)
            ->count());
        $this->assertSame('draft', $invoice->fresh()->status, 'The document write rolled back with its event.');
    }

    public function test_a_redelivered_event_is_not_reprocessed_by_the_idempotent_consumer(): void
    {
        $invoice = $this->draftDirectInvoice(1);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();
        $this->publishOutbox();

        $outbox = $this->outboxRows(SalesEventCatalogue::INVOICE_POSTED)->first();
        $this->assertNotNull($outbox);
        $this->assertSame(1, SalesEventAuditLog::query()->where('event_id', $outbox->id)->count());

        // Simulate at-least-once redelivery of the SAME event id: clear the
        // published marker and publish again.
        $outbox->update(['published_at' => null, 'publish_attempts' => 0, 'dead_lettered_at' => null]);
        $this->publishOutbox();

        $outbox->refresh();
        $this->assertNotNull($outbox->published_at);
        $this->assertSame(1, SalesEventAuditLog::query()->where('event_id', $outbox->id)->count(), 'The consumer must not process the same event id twice.');
        $this->assertSame(2, DB::table('domain_event_records')->where('event_name', SalesEventCatalogue::INVOICE_POSTED)->count(), 'Redelivery is visible on the bus but not reprocessed.');
    }

    public function test_a_failing_consumer_dead_letters_the_event(): void
    {
        app(DomainEventDispatcher::class)->subscribe(new class implements EventSubscriptionContract
        {
            public function getModuleKey(): string
            {
                return 'failing-sales-consumer';
            }

            public function getEventNames(): array
            {
                return [SalesEventCatalogue::INVOICE_POSTED];
            }

            public function getHandlerClass(): string
            {
                return FailingSalesEventHandler::class;
            }

            public function getPriority(): int
            {
                return 50;
            }

            public function getTenantFilter(): ?string
            {
                return null;
            }

            public function getPayloadFilter(): ?array
            {
                return null;
            }
        });

        $invoice = $this->draftDirectInvoice(1);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();
        $this->publishOutbox();

        $this->assertDatabaseHas('domain_event_dead_letters', [
            'event_name' => SalesEventCatalogue::INVOICE_POSTED,
            'module_key' => 'failing-sales-consumer',
            'handler_class' => FailingSalesEventHandler::class,
        ]);

        // The proof consumer still processed the event: one failing consumer
        // must not stop the rest.
        $outbox = $this->outboxRows(SalesEventCatalogue::INVOICE_POSTED)->first();
        $this->assertNotNull($outbox);
        $this->assertSame(1, SalesEventAuditLog::query()->where('event_id', $outbox->id)->count());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Catalogue + transition mapping
    // ═════════════════════════════════════════════════════════════════════

    public function test_the_sales_event_catalogue_documents_every_required_versioned_event(): void
    {
        $expected = [
            'quotation.sent.v1',
            'quotation.accepted.v1',
            'quotation.expired.v1',
            'sales_order.confirmed.v1',
            'sales_order.cancelled.v1',
            'delivery_order.completed.v1',
            'invoice.posted.v1',
            'invoice.sent.v1',
            'invoice.paid.v1',
            'invoice.overdue.v1',
            'invoice.cancelled.v1',
            'credit_note.posted.v1',
            'payment.received.v1',
            'payment.refunded.v1',
        ];

        $catalogue = SalesEventCatalogue::all();

        $this->assertSame($expected, array_keys($catalogue), 'The catalogue must define exactly the #901 sales event names.');

        foreach ($expected as $eventName) {
            $this->assertSame('1.0.0', $catalogue[$eventName]['version'], "{$eventName} must carry a version.");
            $this->assertNotEmpty($catalogue[$eventName]['description'] ?? null, "{$eventName} must be documented.");
            $this->assertNotEmpty($catalogue[$eventName]['payload'] ?? null, "{$eventName} must document its payload schema.");
        }

        // The transition map used at the choke point.
        $this->assertSame('quotation.accepted.v1', SalesEventCatalogue::eventForTransition('quotation', 'confirm'));
        $this->assertSame('sales_order.confirmed.v1', SalesEventCatalogue::eventForTransition('sales_order', 'confirm'));
        $this->assertSame('sales_order.cancelled.v1', SalesEventCatalogue::eventForTransition('sales_order', 'cancel'));
        $this->assertSame('delivery_order.completed.v1', SalesEventCatalogue::eventForTransition('delivery_order', 'complete'));
        $this->assertSame('invoice.posted.v1', SalesEventCatalogue::eventForTransition('invoice', 'post'));
        $this->assertSame('invoice.cancelled.v1', SalesEventCatalogue::eventForTransition('invoice', 'cancel'));
        $this->assertSame('invoice.sent.v1', SalesEventCatalogue::eventForTransition('invoice', 'sync_sent'));
        $this->assertSame('invoice.paid.v1', SalesEventCatalogue::eventForTransition('invoice', 'sync_paid'));
        $this->assertSame('invoice.overdue.v1', SalesEventCatalogue::eventForTransition('invoice', 'sync_overdue'));
        $this->assertNull(SalesEventCatalogue::eventForTransition('invoice', 'sync_partial'));
        $this->assertNull(SalesEventCatalogue::eventForTransition('quotation', 'reset_to_draft'));
    }

    public function test_quotation_sales_order_and_invoice_transitions_emit_their_catalogue_events(): void
    {
        $quotation = $this->draftQuotation();
        $this->apiPost("/api/v1/quotations/{$quotation->id}/confirm")->assertOk();
        $this->assertCount(1, $this->outboxRowsForDocument(SalesEventCatalogue::QUOTATION_ACCEPTED, $quotation->id));

        $order = $this->draftSalesOrder();
        $this->apiPost("/api/v1/sales/orders/{$order->id}/confirm")->assertOk();
        $this->assertCount(1, $this->outboxRowsForDocument(SalesEventCatalogue::SALES_ORDER_CONFIRMED, $order->id));

        $this->apiPost("/api/v1/sales/orders/{$order->id}/cancel", ['reason' => 'Event mapping test'])->assertOk();
        $this->assertCount(1, $this->outboxRowsForDocument(SalesEventCatalogue::SALES_ORDER_CANCELLED, $order->id));

        $invoice = $this->draftDirectInvoice(1);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();
        $this->apiPost("/api/v1/invoices/{$invoice->id}/cancel")->assertOk();
        $this->assertCount(1, $this->outboxRowsForDocument(SalesEventCatalogue::INVOICE_POSTED, $invoice->id));
        $this->assertCount(1, $this->outboxRowsForDocument(SalesEventCatalogue::INVOICE_CANCELLED, $invoice->id));
    }

    public function test_delivery_order_completion_emits_delivery_order_completed_v1(): void
    {
        $order = $this->draftSalesOrder();
        $this->apiPost("/api/v1/sales/orders/{$order->id}/confirm")->assertOk();

        $order->refresh();
        $lineId = (int) $order->lines()->firstOrFail()->id;

        $create = $this->apiPost('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'sales_order_line_id' => $lineId,
                'quantity_delivered' => 2,
            ]],
        ]);
        $create->assertCreated();
        $deliveryOrderId = (int) $create->json('data.id');

        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/confirm")->assertOk();
        $this->apiPost("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete")->assertOk();

        $rows = $this->outboxRows(SalesEventCatalogue::DELIVERY_ORDER_COMPLETED);
        $this->assertCount(1, $rows);
        $this->assertSame((string) $deliveryOrderId, (string) $rows->first()->document_id);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Credit note + payments
    // ═════════════════════════════════════════════════════════════════════

    public function test_credit_note_posting_emits_credit_note_posted_v1(): void
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100,
            'paid_amount' => 0,
            'balance' => 100,
            'created_by' => $this->admin->id,
        ]);

        $created = $this->apiPost('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'Event test',
            'lines' => [[
                'description' => 'Credit against '.$invoice->invoice_number,
                'quantity' => 1,
                'unit_price' => 40,
                'tax_rate' => 0,
            ]],
        ]);
        $created->assertCreated();
        $creditNoteId = (int) $created->json('id');

        $this->apiPost("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/post")->assertOk();

        $rows = $this->outboxRows(SalesEventCatalogue::CREDIT_NOTE_POSTED);
        $this->assertCount(1, $rows);
        $this->assertSame((string) $creditNoteId, (string) $rows->first()->document_id);
        $this->assertSame('credit_note', $rows->first()->payload['document_type']);

        $outbox = $rows->first();
        $this->publishOutbox();
        $this->assertSame(1, SalesEventAuditLog::query()->where('event_id', $outbox->id)->count());
    }

    public function test_payment_posting_emits_payment_received_v1(): void
    {
        $invoice = $this->draftDirectInvoice(2);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();

        $created = $this->apiPost('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 10,
            'method' => 'cash',
        ]);
        $created->assertCreated();
        $paymentId = (int) $created->json('id');

        $this->apiPost("/api/v1/payments/{$paymentId}/post")->assertOk();

        $rows = $this->outboxRows(SalesEventCatalogue::PAYMENT_RECEIVED);
        $this->assertCount(1, $rows);
        $this->assertSame((string) $paymentId, (string) $rows->first()->document_id);
        $this->assertSame('payment', $rows->first()->payload['document_type']);
        $this->assertEqualsWithDelta(10.0, (float) $rows->first()->payload['total'], 0.01);

        $outbox = $rows->first();
        $this->publishOutbox();
        $this->assertSame(1, SalesEventAuditLog::query()->where('event_id', $outbox->id)->count());
    }

    public function test_payment_refund_emits_payment_refunded_v1(): void
    {
        $invoice = $this->draftDirectInvoice(2);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();

        $created = $this->apiPost('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 10,
            'method' => 'cash',
        ]);
        $created->assertCreated();
        $paymentId = (int) $created->json('id');

        $this->apiPost("/api/v1/payments/{$paymentId}/post")->assertOk();

        $this->apiPost("/api/v1/corrections/payments/{$paymentId}/refund", [
            'reason_code' => 'DATA_ENTRY_ERROR',
            'reason_text' => 'Event refund test',
        ])->assertOk();

        $rows = $this->outboxRows(SalesEventCatalogue::PAYMENT_REFUNDED);
        $this->assertCount(1, $rows);
        $this->assertSame('payment', $rows->first()->payload['document_type']);

        $outbox = $rows->first();
        $this->publishOutbox();
        $this->assertSame(1, SalesEventAuditLog::query()->where('event_id', $outbox->id)->count());
    }
}

/**
 * Test double: a consumer that always fails, used to prove dead-lettering.
 */
class FailingSalesEventHandler implements EventHandlerContract
{
    public function handle(DomainEventContract $event): void
    {
        throw new \RuntimeException('Simulated sales consumer failure for #901');
    }
}
