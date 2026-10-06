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
use App\Platform\EventBus\DomainEventDispatcher;
use App\Platform\Notifications\NotificationLog;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Sales\Notifications\SalesNotificationCatalogue;
use Modules\Sales\Subscriptions\SalesEventSubscription;
use Modules\Sales\Subscriptions\SalesNotificationSubscription;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #907 (PCU3) — the per-user notification preference matrix and the
 * per-document mute.
 *
 * #903 shipped the mute storage (`platform_notification_preferences`), the
 * event catalogue and the event-level preference API. This suite asserts the
 * #907 extension:
 *   - the matrix endpoint lists every catalogue event with its effective
 *     channel state and whether that state is still the default;
 *   - every event exposes an in-app, email and WhatsApp column; WhatsApp is a
 *     stored preference only (no transport), so its default is off;
 *   - toggling a cell persists and the dispatcher suppresses that channel;
 *   - a document-scoped mute suppresses only that document, only for that
 *     user, and un-muting restores delivery;
 *   - the inbox exposes the document-mute state and the API rejects unknown
 *     catalogue events / document types and unauthenticated callers.
 */
class Issue907SalesNotificationPreferenceMatrixTest extends TenantTestCase
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
            'name' => 'Issue 907 Preferences Tenant',
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
            'name' => 'Issue 907 Warehouse',
            'code' => 'I907-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Issue 907 Widget',
            'sku' => 'I907-'.uniqid(),
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

    private function overdueInvoice(string $number, float $balance = 100): Invoice
    {
        return Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'invoice_number' => $number,
            'invoice_date' => now()->subDays(40)->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'currency_code' => 'USD',
            'subtotal' => $balance,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $balance,
            'paid_amount' => 0,
            'balance' => $balance,
            'created_by' => $this->admin->id,
        ]);
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

    private function matrixRow(User $user, string $event): ?array
    {
        return collect(
            $this->actingAs($user)->getJson('/api/v1/notifications/preferences')->assertOk()->json('data')
        )->firstWhere('event', $event);
    }

    // ═════════════════════════════════════════════════════════════════════
    // The matrix endpoint
    // ═════════════════════════════════════════════════════════════════════

    public function test_the_preference_matrix_lists_every_catalogue_event_with_effective_defaults_and_a_whatsapp_column(): void
    {
        $accountant = $this->makeUser(UserRole::ACCOUNTANT->value);

        $response = $this->actingAs($accountant)->getJson('/api/v1/notifications/preferences')->assertOk();
        $events = collect($response->json('data'));

        $this->assertSame(
            array_keys(SalesNotificationCatalogue::preferenceEvents()),
            $events->pluck('event')->all(),
            'The matrix must list every catalogue event as a row.'
        );

        $invoicePosted = $events->firstWhere('event', SalesNotificationCatalogue::INVOICE_POSTED);
        $this->assertNotNull($invoicePosted, 'invoice.posted must be a matrix row.');

        foreach (['in_app', 'mail', 'whatsapp'] as $channel) {
            $this->assertArrayHasKey($channel, $invoicePosted['channels'], "The matrix must expose a {$channel} column.");
            $this->assertArrayHasKey($channel, $invoicePosted['is_default'], "Every {$channel} cell must say whether its value is the default.");
        }

        $this->assertTrue($invoicePosted['channels']['in_app']);
        $this->assertTrue($invoicePosted['channels']['mail']);
        $this->assertFalse(
            $invoicePosted['channels']['whatsapp'],
            'WhatsApp has no delivery transport yet, so its untouched default is off.'
        );

        $this->assertTrue($invoicePosted['is_default']['in_app'], 'A user with no explicit row sees the role/catalogue default.');
        $this->assertTrue($invoicePosted['is_default']['mail']);
        $this->assertTrue($invoicePosted['is_default']['whatsapp']);

        // Defaults are per event, not one global set: the e-invoice event is
        // mail-only in the #903 catalogue.
        $einvoice = $events->firstWhere('event', SalesNotificationCatalogue::EINVOICE_STATUS_CHANGED);
        $this->assertNotNull($einvoice);
        $this->assertFalse($einvoice['channels']['in_app']);
        $this->assertTrue($einvoice['channels']['mail']);
        $this->assertFalse($einvoice['channels']['whatsapp']);
        $this->assertTrue($einvoice['is_default']['mail']);

        $this->assertFalse(
            $einvoice['available']['in_app'],
            'A channel the event does not declare must not be togglable.'
        );
        $this->assertTrue($einvoice['available']['whatsapp'], 'WhatsApp is always a stored-preference column.');
    }

    public function test_toggling_a_matrix_cell_persists_and_suppresses_that_channel_for_that_user_only(): void
    {
        $accountant = $this->makeUser(UserRole::ACCOUNTANT->value);
        $colleague = $this->makeUser(UserRole::ACCOUNTANT->value);

        $this->actingAs($accountant)->putJson('/api/v1/notifications/preferences', [
            'event' => SalesNotificationCatalogue::INVOICE_POSTED,
            'channel' => 'mail',
            'enabled' => false,
        ])->assertOk();

        $row = $this->matrixRow($accountant, SalesNotificationCatalogue::INVOICE_POSTED);
        $this->assertFalse($row['channels']['mail'], 'The toggled cell must read back as off.');
        $this->assertFalse($row['is_default']['mail'], 'The cell is no longer the default once it has an explicit row.');
        $this->assertTrue($row['channels']['in_app'], 'Untouched cells keep their effective default.');
        $this->assertTrue($row['is_default']['in_app']);

        $invoice = $this->draftDirectInvoice(2, 10);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();

        $logs = $this->notificationsFor($accountant, SalesNotificationCatalogue::INVOICE_POSTED);
        $this->assertCount(1, $logs, 'The in-app channel stays on, so the notification is still written.');
        $this->assertSame('in_app', $logs->first()->channel);
        $this->assertSame('skipped', $logs->first()->status, 'The mail leg is suppressed by the preference.');

        $colleagueLogs = $this->notificationsFor($colleague, SalesNotificationCatalogue::INVOICE_POSTED);
        $this->assertCount(1, $colleagueLogs, 'The toggle is per user: a colleague still gets the default channels.');
        $this->assertSame('sent', $colleagueLogs->first()->status);
    }

    public function test_whatsapp_is_stored_as_a_preference_even_though_its_transport_is_a_stub(): void
    {
        $accountant = $this->makeUser(UserRole::ACCOUNTANT->value);

        $this->actingAs($accountant)->putJson('/api/v1/notifications/preferences', [
            'event' => SalesNotificationCatalogue::INVOICE_POSTED,
            'channel' => 'whatsapp',
            'enabled' => true,
        ])->assertOk();

        $this->assertDatabaseHas('platform_notification_preferences', [
            'user_id' => $accountant->id,
            'event' => SalesNotificationCatalogue::INVOICE_POSTED,
            'channel' => 'whatsapp',
            'mail_enabled' => true,
        ]);

        $row = $this->matrixRow($accountant, SalesNotificationCatalogue::INVOICE_POSTED);
        $this->assertTrue($row['channels']['whatsapp']);
        $this->assertFalse($row['is_default']['whatsapp']);

        // The stored preference never becomes a delivery channel: the
        // platform delivers in_app + mail only.
        $invoice = $this->draftDirectInvoice(1, 0);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();

        $logs = $this->notificationsFor($accountant, SalesNotificationCatalogue::INVOICE_POSTED);
        $this->assertCount(1, $logs);
        $this->assertNotSame('whatsapp', $logs->first()->channel);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Document mute
    // ═════════════════════════════════════════════════════════════════════

    public function test_a_document_mute_suppresses_only_that_document_for_that_user_and_unmute_restores(): void
    {
        $accountant = $this->makeUser(UserRole::ACCOUNTANT->value);
        $colleague = $this->makeUser(UserRole::ACCOUNTANT->value);

        $invoiceA = $this->overdueInvoice('INV-907-A');
        $invoiceB = $this->overdueInvoice('INV-907-B');

        $this->actingAs($accountant)->putJson('/api/v1/notifications/preferences/document', [
            'event' => SalesNotificationCatalogue::INVOICE_OVERDUE,
            'document_type' => 'invoice',
            'document_id' => $invoiceA->id,
            'muted' => true,
        ])->assertOk();

        $this->assertDatabaseHas('platform_notification_preferences', [
            'user_id' => $accountant->id,
            'event' => SalesNotificationCatalogue::INVOICE_OVERDUE,
            'document_type' => 'invoice',
            'document_id' => $invoiceA->id,
            'mail_enabled' => false,
        ]);

        $this->artisan('sales:scan-overdue-invoices', ['--tenant' => $this->tenantId])->assertExitCode(0);

        $accountantLogs = $this->notificationsFor($accountant, SalesNotificationCatalogue::INVOICE_OVERDUE);
        $this->assertCount(1, $accountantLogs, 'The muted document must be suppressed for that user.');
        $this->assertSame(
            (int) $invoiceB->id,
            (int) ($accountantLogs->first()->data['document_id'] ?? 0),
            'Only the unmuted document may reach the user.'
        );

        $colleagueLogs = $this->notificationsFor($colleague, SalesNotificationCatalogue::INVOICE_OVERDUE);
        $this->assertCount(2, $colleagueLogs, 'The document mute is per user: the colleague still gets both documents.');

        // Un-mute restores delivery for that user.
        $this->actingAs($accountant)->putJson('/api/v1/notifications/preferences/document', [
            'event' => SalesNotificationCatalogue::INVOICE_OVERDUE,
            'document_type' => 'invoice',
            'document_id' => $invoiceA->id,
            'muted' => false,
        ])->assertOk();

        $this->assertDatabaseMissing('platform_notification_preferences', [
            'user_id' => $accountant->id,
            'event' => SalesNotificationCatalogue::INVOICE_OVERDUE,
            'document_type' => 'invoice',
            'document_id' => $invoiceA->id,
        ]);

        $this->artisan('sales:scan-overdue-invoices', ['--tenant' => $this->tenantId])->assertExitCode(0);

        $accountantLogs = $this->notificationsFor($accountant, SalesNotificationCatalogue::INVOICE_OVERDUE);
        $this->assertCount(2, $accountantLogs, 'After un-muting, the document is delivered while the already-notified one is not repeated.');
        $this->assertEqualsCanonicalizing(
            [(int) $invoiceA->id, (int) $invoiceB->id],
            $accountantLogs->map(fn (NotificationLog $log) => (int) ($log->data['document_id'] ?? 0))->all(),
        );

        // The colleague was never affected by either toggle.
        $this->assertCount(2, $this->notificationsFor($colleague, SalesNotificationCatalogue::INVOICE_OVERDUE));
    }

    public function test_the_notification_inbox_exposes_the_document_mute_state(): void
    {
        $accountant = $this->makeUser(UserRole::ACCOUNTANT->value);

        $invoice = $this->draftDirectInvoice(2, 10);
        $this->apiPost("/api/v1/invoices/{$invoice->id}/post")->assertOk();

        $listItem = fn () => collect(
            $this->actingAs($accountant)->getJson('/api/v1/notifications')->assertOk()->json('data')
        )->firstWhere('event', SalesNotificationCatalogue::INVOICE_POSTED);

        $this->assertFalse(
            (bool) ($listItem()['document_muted'] ?? false),
            'An unmuted document must read as not muted in the inbox.'
        );

        $this->actingAs($accountant)->putJson('/api/v1/notifications/preferences/document', [
            'event' => SalesNotificationCatalogue::INVOICE_POSTED,
            'document_type' => 'invoice',
            'document_id' => $invoice->id,
            'muted' => true,
        ])->assertOk();

        $this->assertTrue(
            (bool) ($listItem()['document_muted'] ?? false),
            'The inbox must surface the document mute so the UI can offer un-mute.'
        );

        $this->actingAs($accountant)->putJson('/api/v1/notifications/preferences/document', [
            'event' => SalesNotificationCatalogue::INVOICE_POSTED,
            'document_type' => 'invoice',
            'document_id' => $invoice->id,
            'muted' => false,
        ])->assertOk();

        $this->assertFalse((bool) ($listItem()['document_muted'] ?? false));
    }

    public function test_the_document_mute_endpoint_validates_the_catalogue_and_requires_authentication(): void
    {
        $accountant = $this->makeUser(UserRole::ACCOUNTANT->value);

        $this->actingAs($accountant)->putJson('/api/v1/notifications/preferences/document', [
            'event' => 'made.up.event',
            'document_type' => 'invoice',
            'document_id' => 1,
            'muted' => true,
        ])->assertStatus(422);

        $this->actingAs($accountant)->putJson('/api/v1/notifications/preferences/document', [
            'event' => SalesNotificationCatalogue::INVOICE_POSTED,
            'document_type' => 'made_up_document',
            'document_id' => 1,
            'muted' => true,
        ])->assertStatus(422);

        $this->actingAs($accountant)->putJson('/api/v1/notifications/preferences/document', [
            'event' => SalesNotificationCatalogue::INVOICE_POSTED,
            'document_type' => 'invoice',
            'muted' => true,
        ])->assertStatus(422);

        $this->assertDatabaseCount('platform_notification_preferences', 0);

        // The preference surface is self-scoped but still authenticated.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/notifications/preferences')->assertUnauthorized();
        $this->putJson('/api/v1/notifications/preferences/document', [
            'event' => SalesNotificationCatalogue::INVOICE_POSTED,
            'document_type' => 'invoice',
            'document_id' => 1,
            'muted' => true,
        ])->assertUnauthorized();
    }
}
