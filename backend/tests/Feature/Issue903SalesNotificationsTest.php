<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceSubmission;
use App\Models\Accounting\CountryConfiguration;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Platform\EventBus\DomainEventDispatcher;
use App\Platform\Notifications\NotificationLog;
use App\Platform\Notifications\NotificationPreference;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Notifications\SalesNotificationCatalogue;
use Modules\Sales\Subscriptions\SalesEventSubscription;
use Modules\Sales\Subscriptions\SalesNotificationSubscription;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #903 (PC9) — Sales notifications beyond approvals.
 *
 * #901 publishes versioned sales domain events; this suite asserts the #903
 * consumer fan-out: the right recipients (by role/relationship) get an in-app
 * + mail notification through the platform NotificationService, each carrying
 * a document link; per-user/per-channel preferences mute an event; the
 * time-based scans (overdue invoices, expiring quotations) and the non-event
 * paths (credit-limit rejection) notify; and the pre-existing #666 e-invoice
 * rejection path honours the same mute storage.
 */
class Issue903SalesNotificationsTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContextWithPermissions($this->tenantId);
        $this->mockFeatureGate(true);

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue 903 Notifications Tenant',
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
            'name' => 'Issue 903 Warehouse',
            'code' => 'I903-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Issue 903 Widget',
            'sku' => 'I903-'.uniqid(),
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

        // The Sales provider wires both subscriptions on tenancy initialization;
        // the test tenant is bound after app boot, so register them explicitly.
        app(DomainEventDispatcher::class)->subscribe(new SalesEventSubscription);
        app(DomainEventDispatcher::class)->subscribe(new SalesNotificationSubscription);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fixtures / helpers
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

    /**
     * A tenant user with a central tenant role (drives TenantRoleService) and
     * the matching Spatie role.
     */
    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $spatieRole = UserRole::tryFrom($role)?->spatieName();

        if ($spatieRole !== null) {
            Role::firstOrCreate(['name' => $spatieRole, 'guard_name' => 'web']);
            $user->assignRole($spatieRole);
        }

        return $user;
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

    private function draftQuotation(): Quotation
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

        return Quotation::query()->findOrFail((int) $response->json('data.id'));
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

    /**
     * @return \Illuminate\Support\Collection<int, NotificationLog>
     */
    private function notificationsFor(User $user, string $event)
    {
        return NotificationLog::query()
            ->where('user_id', $user->id)
            ->where('event', $event)
            ->orderBy('id')
            ->get();
    }

    private function mute(int $userId, string $event, string $channel): void
    {
        NotificationPreference::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $userId,
            'event' => $event,
            'channel' => $channel,
            'mail_enabled' => false,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Bus-driven events → recipients, links, role defaults
    // ═════════════════════════════════════════════════════════════════════

    public function test_posting_an_invoice_notifies_the_accounts_role_with_a_document_link_and_excludes_the_actor(): void
    {
        $accountant = $this->makeUser(UserRole::ACCOUNTANT->value);

        $invoice = $this->draftDirectInvoice(2, 10);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();

        $logs = $this->notificationsFor($accountant, SalesNotificationCatalogue::INVOICE_POSTED);

        $this->assertCount(1, $logs, 'The accounts role must get exactly one invoice.posted notification.');
        $data = $logs->first()->data;

        $this->assertSame('invoice', $data['document_type'] ?? null);
        $this->assertSame($invoice->invoice_number, $data['document_number'] ?? null);
        $this->assertSame($invoice->id, $data['primary']['id'] ?? null);
        $this->assertSame('invoice', $data['primary']['type'] ?? null);
        $this->assertStringContainsString("/invoices/{$invoice->id}", $data['url'] ?? '');
        $this->assertSame('sent', $logs->first()->status, 'the mail leg must have been delivered (Mail::fake).');

        // The actor (admin, who also holds an accounts role) must not be notified
        // about their own action.
        $this->assertCount(0, $this->notificationsFor($this->admin, SalesNotificationCatalogue::INVOICE_POSTED));
    }

    public function test_quotation_acceptance_notifies_the_customer_salesperson_with_a_link(): void
    {
        $seller = $this->makeUser(UserRole::SALES->value, ['name' => 'Quota Seller']);
        $this->customer->update(['salesperson_id' => $seller->id]);

        $quotation = $this->draftQuotation();
        $this->apiPost("/api/v1/quotations/{$quotation->id}/confirm")->assertOk();

        $logs = $this->notificationsFor($seller, SalesNotificationCatalogue::QUOTATION_ACCEPTED);

        $this->assertCount(1, $logs, 'The customer salesperson must get the quotation.accepted notification.');
        $this->assertSame('quotation', $logs->first()->data['document_type'] ?? null);
        $this->assertSame($quotation->quotation_number, $logs->first()->data['document_number'] ?? null);
        $this->assertStringContainsString("/quotations/{$quotation->id}", $logs->first()->data['url'] ?? '');
    }

    public function test_sales_order_confirmation_notifies_fulfilment_users_but_not_viewers(): void
    {
        $manager = $this->makeUser(UserRole::MANAGER->value);
        $viewer = $this->makeUser(UserRole::VIEWER->value);

        $order = $this->draftSalesOrder();
        $this->apiPost("/api/v1/sales/orders/{$order->id}/confirm")->assertOk();

        $logs = $this->notificationsFor($manager, SalesNotificationCatalogue::SALES_ORDER_CONFIRMED);
        $this->assertCount(1, $logs, 'A fulfilment-permission user must get the sales_order.confirmed notification.');
        $this->assertSame('sales_order', $logs->first()->data['document_type'] ?? null);
        $this->assertSame($order->sales_order_number, $logs->first()->data['document_number'] ?? null);

        $this->assertCount(
            0,
            $this->notificationsFor($viewer, SalesNotificationCatalogue::SALES_ORDER_CONFIRMED),
            'A user without fulfilment.write must not get the warehouse notification.'
        );
    }

    public function test_delivery_order_completion_notifies_the_salesperson(): void
    {
        $seller = $this->makeUser(UserRole::SALES->value, ['name' => 'DO Seller']);
        $this->customer->update(['salesperson_id' => $seller->id]);

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

        $logs = $this->notificationsFor($seller, SalesNotificationCatalogue::DELIVERY_ORDER_COMPLETED);

        $this->assertCount(1, $logs, 'The salesperson must get the delivery_order.completed notification.');
        $this->assertSame('delivery_order', $logs->first()->data['document_type'] ?? null);
        $this->assertSame($deliveryOrderId, (int) ($logs->first()->data['document_id'] ?? 0));
        $this->assertStringContainsString("/delivery-orders/{$deliveryOrderId}", $logs->first()->data['url'] ?? '');
    }

    public function test_payment_received_notifies_the_salesperson_and_accounts_with_an_invoice_link(): void
    {
        $seller = $this->makeUser(UserRole::SALES->value, ['name' => 'Pay Seller']);
        $accountant = $this->makeUser(UserRole::ACCOUNTANT->value);
        $this->customer->update(['salesperson_id' => $seller->id]);

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

        foreach ([$seller, $accountant] as $recipient) {
            $logs = $this->notificationsFor($recipient, SalesNotificationCatalogue::PAYMENT_RECEIVED);
            $this->assertCount(1, $logs, 'payment.received must reach the salesperson and accounts.');
            $this->assertSame('payment', $logs->first()->data['document_type'] ?? null);

            $links = collect($logs->first()->data['links'] ?? []);
            $this->assertTrue(
                $links->contains(fn ($ref) => ($ref['type'] ?? null) === 'invoice' && (int) ($ref['id'] ?? 0) === (int) $invoice->id),
                'A payment notification must link to the invoice it settles.'
            );
        }
    }

    public function test_credit_note_posting_notifies_the_salesperson_and_links_the_invoice(): void
    {
        $seller = $this->makeUser(UserRole::SALES->value, ['name' => 'Credit Seller']);
        $this->customer->update(['salesperson_id' => $seller->id]);

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
            'reason' => 'Issue 903 test',
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

        $logs = $this->notificationsFor($seller, SalesNotificationCatalogue::CREDIT_NOTE_POSTED);
        $this->assertCount(1, $logs, 'The salesperson must get the credit_note.posted notification.');

        $links = collect($logs->first()->data['links'] ?? []);
        $this->assertTrue(
            $links->contains(fn ($ref) => ($ref['type'] ?? null) === 'invoice' && (int) ($ref['id'] ?? 0) === (int) $invoice->id),
            'A credit note notification must link to the invoice it credits.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Preferences: per user, per channel
    // ═════════════════════════════════════════════════════════════════════

    public function test_a_muted_event_writes_no_notification_for_that_user_while_others_still_get_the_defaults(): void
    {
        $muted = $this->makeUser(UserRole::ACCOUNTANT->value);
        $other = $this->makeUser(UserRole::ACCOUNTANT->value);

        $this->mute($muted->id, SalesNotificationCatalogue::INVOICE_POSTED, 'in_app');
        $this->mute($muted->id, SalesNotificationCatalogue::INVOICE_POSTED, 'mail');

        $invoice = $this->draftDirectInvoice(1, 10);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();

        $this->assertCount(
            0,
            $this->notificationsFor($muted, SalesNotificationCatalogue::INVOICE_POSTED),
            'A fully muted event must write no notification for that user.'
        );
        $this->assertCount(
            1,
            $this->notificationsFor($other, SalesNotificationCatalogue::INVOICE_POSTED),
            'The mute is per user: an unmuted colleague still gets the default channels.'
        );
    }

    public function test_muting_only_the_mail_channel_keeps_the_in_app_notification(): void
    {
        $inAppOnly = $this->makeUser(UserRole::ACCOUNTANT->value);
        $this->mute($inAppOnly->id, SalesNotificationCatalogue::INVOICE_POSTED, 'mail');

        $invoice = $this->draftDirectInvoice(1, 10);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();

        $logs = $this->notificationsFor($inAppOnly, SalesNotificationCatalogue::INVOICE_POSTED);
        $this->assertCount(1, $logs, 'Muting mail must not remove the in-app notification.');
        $this->assertSame('in_app', $logs->first()->channel);
        $this->assertSame('skipped', $logs->first()->status, 'the mail leg reports skipped, not sent.');
    }

    public function test_the_preference_api_lists_the_sales_events_and_saves_per_channel_mutes(): void
    {
        $accountant = $this->makeUser(UserRole::ACCOUNTANT->value);

        $index = $this->actingAs($accountant)->getJson('/api/v1/notifications/preferences')->assertOk();
        $events = collect($index->json('data'));

        $invoicePosted = $events->firstWhere('event', SalesNotificationCatalogue::INVOICE_POSTED);
        $this->assertNotNull($invoicePosted, 'The preference surface must list the sales notification events.');
        $this->assertTrue($invoicePosted['channels']['in_app']);
        $this->assertTrue($invoicePosted['channels']['mail']);

        $this->actingAs($accountant)->putJson('/api/v1/notifications/preferences', [
            'event' => SalesNotificationCatalogue::INVOICE_POSTED,
            'channel' => 'mail',
            'enabled' => false,
        ])->assertOk();

        $this->assertDatabaseHas('platform_notification_preferences', [
            'user_id' => $accountant->id,
            'event' => SalesNotificationCatalogue::INVOICE_POSTED,
            'channel' => 'mail',
            'mail_enabled' => false,
        ]);

        // Unknown events and channels the event does not declare are refused.
        $this->actingAs($accountant)->putJson('/api/v1/notifications/preferences', [
            'event' => 'made.up.event',
            'channel' => 'mail',
            'enabled' => false,
        ])->assertStatus(422);

        $this->actingAs($accountant)->putJson('/api/v1/notifications/preferences', [
            'event' => SalesNotificationCatalogue::EINVOICE_STATUS_CHANGED,
            'channel' => 'in_app',
            'enabled' => false,
        ])->assertStatus(422);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Time-based scans
    // ═════════════════════════════════════════════════════════════════════

    public function test_the_overdue_scan_notifies_the_salesperson_and_accounts_once_per_invoice(): void
    {
        $seller = $this->makeUser(UserRole::SALES->value, ['name' => 'Overdue Seller']);
        $accountant = $this->makeUser(UserRole::ACCOUNTANT->value);
        $this->customer->update(['salesperson_id' => $seller->id]);

        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-OD-903',
            'invoice_date' => now()->subDays(40)->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'currency_code' => 'USD',
            'subtotal' => 250,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 250,
            'paid_amount' => 0,
            'balance' => 250,
            'created_by' => $this->admin->id,
        ]);

        $this->artisan('sales:scan-overdue-invoices', ['--tenant' => $this->tenantId])->assertExitCode(0);

        foreach ([$seller, $accountant] as $recipient) {
            $logs = $this->notificationsFor($recipient, SalesNotificationCatalogue::INVOICE_OVERDUE);
            $this->assertCount(1, $logs, 'An overdue invoice must notify the salesperson and accounts.');
            $this->assertSame('invoice', $logs->first()->data['primary']['type'] ?? null);
            $this->assertSame((int) $invoice->id, (int) ($logs->first()->data['primary']['id'] ?? 0));
            $this->assertStringContainsString('250.00', (string) ($logs->first()->body));
        }

        // Idempotent: a rerun does not notify the same document again.
        $this->artisan('sales:scan-overdue-invoices', ['--tenant' => $this->tenantId])->assertExitCode(0);
        $this->assertCount(1, $this->notificationsFor($seller, SalesNotificationCatalogue::INVOICE_OVERDUE));
        $this->assertCount(1, $this->notificationsFor($accountant, SalesNotificationCatalogue::INVOICE_OVERDUE));
    }

    public function test_the_expiring_quotation_scan_notifies_the_owner_within_the_window_once(): void
    {
        $seller = $this->makeUser(UserRole::SALES->value, ['name' => 'Expiring Seller']);
        $this->customer->update(['salesperson_id' => $seller->id]);

        $expiring = $this->draftQuotation();
        $expiring->update([
            'valid_until' => now()->addDays(2)->toDateString(),
            'status' => 'sent',
        ]);

        $outside = $this->draftQuotation();
        $outside->update([
            'valid_until' => now()->addDays(10)->toDateString(),
            'status' => 'sent',
        ]);

        $this->artisan('sales:scan-expiring-quotations', ['--tenant' => $this->tenantId])->assertExitCode(0);

        // Creator (admin) + customer salesperson both get it; the scan has no
        // actor, so the creator is not excluded.
        foreach ([$this->admin, $seller] as $recipient) {
            $logs = $this->notificationsFor($recipient, SalesNotificationCatalogue::QUOTATION_EXPIRING);
            $this->assertCount(1, $logs, 'Both the owner and the salesperson get the expiry warning.');
            $this->assertSame('quotation', $logs->first()->data['document_type'] ?? null);
            $this->assertSame((int) $expiring->id, (int) ($logs->first()->data['document_id'] ?? 0));
        }

        $this->assertCount(
            0,
            NotificationLog::query()
                ->where('event', SalesNotificationCatalogue::QUOTATION_EXPIRING)
                ->where('data->document_id', (string) $outside->id)
                ->get(),
            'A quotation outside the window must not be warned.'
        );

        // Idempotent rerun.
        $this->artisan('sales:scan-expiring-quotations', ['--tenant' => $this->tenantId])->assertExitCode(0);
        $this->assertCount(1, $this->notificationsFor($seller, SalesNotificationCatalogue::QUOTATION_EXPIRING));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Non-event paths
    // ═════════════════════════════════════════════════════════════════════

    public function test_credit_limit_rejection_notifies_the_account_owner_and_accounts(): void
    {
        $seller = $this->makeUser(UserRole::SALES->value, ['name' => 'Credit Seller']);
        $accountant = $this->makeUser(UserRole::ACCOUNTANT->value);
        $this->customer->update([
            'salesperson_id' => $seller->id,
            'credit_limit' => 1,
            'total_outstanding' => 0,
        ]);

        $order = $this->draftSalesOrder();

        $response = $this->apiPost("/api/v1/sales/orders/{$order->id}/confirm")->assertStatus(422);
        $this->assertStringContainsString('Credit check failed', (string) $response->json('message'));
        $this->assertSame('draft', $order->fresh()->status);

        foreach ([$seller, $accountant] as $recipient) {
            $logs = $this->notificationsFor($recipient, SalesNotificationCatalogue::CREDIT_LIMIT_EXCEEDED);
            $this->assertCount(1, $logs, 'A credit-limit rejection must notify the salesperson and accounts.');
            $this->assertSame('sales_order', $logs->first()->data['primary']['type'] ?? null);
            $this->assertSame((int) $order->id, (int) ($logs->first()->data['primary']['id'] ?? 0));
            $this->assertStringContainsString('Credit limit exceeded', (string) $logs->first()->subject);
        }
    }

    public function test_einvoice_rejection_notification_respects_the_preference_mute(): void
    {
        Queue::fake();
        Cache::flush();

        $owner = $this->admin;
        $finance = $this->makeUser(UserRole::ACCOUNTANT->value);

        $customer = Customer::factory()->create();
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-EI-903',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'currency_code' => 'MYR',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $owner->id,
        ]);

        CountryConfiguration::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'provider_name' => 'myinvoice',
            'is_enabled' => true,
            'test_mode' => true,
            'api_credentials' => [
                'myinvoice_client_id' => 'test-client-id',
                'myinvoice_client_secret' => 'test-client-secret',
                'supplier_tin' => 'C1234567890',
                'supplier_name' => 'NoovaERP Test Sdn Bhd',
            ],
            'settings' => ['auto_submit' => true, 'environment' => 'preprod'],
        ]);

        $document = ComplianceDocument::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'document_type' => 'invoice',
            'document_id' => $invoice->id,
            'document_number' => $invoice->invoice_number,
            'issued_date' => $invoice->invoice_date,
            'total_amount' => $invoice->total,
            'currency_code' => 'MYR',
            'compliance_status' => 'pending',
            'created_by' => $owner->id,
        ]);

        ComplianceSubmission::create([
            'tenant_id' => $this->tenantId,
            'document_id' => $document->id,
            'country_code' => 'MY',
            'submission_status' => 'ack',
            'provider_reference' => 'REF-MUTE-903',
            'external_document_uuid' => null,
            'submitted_at' => now(),
            'submitted_by' => $owner->id,
            'last_status_check' => null,
        ]);

        // The invoice owner mutes the e-invoice event (its only channel is mail).
        $this->mute($owner->id, SalesNotificationCatalogue::EINVOICE_STATUS_CHANGED, 'mail');

        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'test-token',
                'expires_in' => 3600,
                'scope' => 'InvoicingAPI',
            ])),
            new Response(200, [], json_encode([
                'overallStatus' => 'Invalid',
                'documentSummary' => [['uuid' => 'b1b2c3d4-0000-4000-8000-0000000000aa', 'longId' => 'LHDN-1']],
            ])),
            new Response(200, [], json_encode([
                'status' => 'Invalid',
                'longId' => 'LHDN-1',
                'errors' => [['message' => 'Buyer TIN is not registered.']],
            ])),
        ]);
        $this->app->instance(Client::class, new Client(['handler' => HandlerStack::create($mock)]));

        \Illuminate\Support\Facades\Artisan::call('compliance:sync-einvoice-status');

        $this->assertCount(
            0,
            $this->notificationsFor($owner, SalesNotificationCatalogue::EINVOICE_STATUS_CHANGED),
            'A muted e-invoice event must not notify the owner.'
        );
        $this->assertCount(
            1,
            $this->notificationsFor($finance, SalesNotificationCatalogue::EINVOICE_STATUS_CHANGED),
            'Unmuted finance still gets the e-invoice rejection notification.'
        );
    }

    public function test_a_muted_in_app_channel_hides_the_event_from_the_notification_inbox(): void
    {
        $accountant = $this->makeUser(UserRole::ACCOUNTANT->value);

        $invoice = $this->draftDirectInvoice(1, 10);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();

        $this->actingAs($accountant)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonFragment(['event' => SalesNotificationCatalogue::INVOICE_POSTED]);

        $this->mute($accountant->id, SalesNotificationCatalogue::INVOICE_POSTED, 'in_app');

        $response = $this->actingAs($accountant)->getJson('/api/v1/notifications')->assertOk();
        $events = collect($response->json('data'))->pluck('event');

        $this->assertFalse(
            $events->contains(SalesNotificationCatalogue::INVOICE_POSTED),
            'A user who muted the in-app channel must not see the event in the inbox.'
        );
    }
}
