<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Models\CommunicationOutbox;
use Modules\Communication\Services\Channels\SmsChannel;
use Modules\Communication\Services\CommunicationEngine;
use Modules\Communication\Support\EventSubtypeRegistry;
use Modules\Inventory\Models\StockLevel;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-018 — Platform bus fixes (P-05 subtype registry orphans + stub channels
 * faking success; P-06 tenant-context retry/digest) AND the Storefront
 * lifecycle messaging pilot (FR-MSG-02 placed/paid via the engine, FR-MSG-06
 * merchant toggles). These fixes were the R-07 gate for lifecycle mail.
 */
class SF018BusFixesAndLifecycleTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withCredentials();
        Queue::fake();
        DB::statement('SET search_path TO public');

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF018 Tenant', 'email' => 'sf018-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->create([
            'tenant_id' => $this->tenantId, 'catalog_item_id' => $item->id,
            'is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now(),
        ]);
        app(FeatureGateService::class)->invalidate($this->tenantId);

        StorefrontSettings::current();

        if (! User::query()->exists()) {
            $u = User::factory()->create();
            DB::table('public.users')->updateOrInsert(
                ['email' => $u->email],
                ['name' => $u->name, 'password' => $u->password, 'created_at' => now(), 'updated_at' => now()]
            );
            $centralId = DB::table('public.users')->where('email', $u->email)->value('id');
            DB::table('user_company_access')->updateOrInsert(
                ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
                ['role' => 'owner', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
            );
            $u->assignRole('Owner');
        }
    }

    // ─── P-05a: subtype registry ────────────────────────────────────────

    public function test_orphaned_platform_subtypes_are_registered_at_boot(): void
    {
        // These threw inside the QUEUED listener before (silent failed_jobs).
        EventSubtypeRegistry::assertValid('invoice.delivered');
        EventSubtypeRegistry::assertValid('invoice.approval_submitted');
        $this->assertTrue(true, 'assertValid must not throw');

        // Storefront lifecycle + customer subtypes registered by our module.
        EventSubtypeRegistry::assertValid('storefront.order.placed');
        EventSubtypeRegistry::assertValid('storefront.order.paid');
        EventSubtypeRegistry::assertValid('storefront.customer.password_reset');

        $this->expectException(\InvalidArgumentException::class);
        EventSubtypeRegistry::assertValid('definitely.not.registered');
    }

    // ─── P-05b: stub channels fail CLOSED, not fake-success ─────────────

    public function test_unimplemented_sms_driver_fails_closed(): void
    {
        config(['communication.channels.sms.enabled' => true, 'communication.channels.sms.driver' => 'twilio']);

        $message = CommunicationMessage::create([
            'tenant_id' => $this->tenantId,
            'message_type' => 'notification',
            'subtype' => 'test.stub',
            'subject' => 'stub',
            'body' => 'body',
            'status' => 'queued',
        ]);
        $outbox = CommunicationOutbox::create([
            'tenant_id' => $this->tenantId,
            'message_id' => $message->id,
            'channel' => 'sms',
            'recipient_phone' => '+10000000',
            'status' => CommunicationOutbox::STATUS_PENDING,
        ]);

        $result = (new SmsChannel)->send($outbox, $message);

        $this->assertFalse($result['success'], 'P-05: stubs must never report success');
        $this->assertSame('driver_not_implemented', $result['error']);
    }

    // ─── P-06: retry path respects tenant context ───────────────────────

    public function test_retry_failed_never_delivers_under_a_missing_tenant(): void
    {
        // Both shapes must be contained: a well-formed uuid that doesn't
        // exist, and a malformed id that would blow up the uuid cast.
        $this->retrySkipsUnknownTenant((string) \Illuminate\Support\Str::uuid());
        $this->retrySkipsUnknownTenant('ghost-tenant-'.Str::random(6));
    }

    private function retrySkipsUnknownTenant(string $tenantKey): void
    {
        $message = CommunicationMessage::create([
            'tenant_id' => $tenantKey,
            'message_type' => 'notification',
            'subtype' => null,
            'subject' => 'ghost',
            'body' => 'b',
            'status' => 'queued',
        ]);
        $outbox = CommunicationOutbox::create([
            'tenant_id' => $message->tenant_id,
            'message_id' => $message->id,
            'channel' => 'email',
            'recipient_email' => 'ghost@example.test',
            'status' => CommunicationOutbox::STATUS_FAILED,
            'retry_count' => 1,
            'max_retries' => 5,
            'next_retry_at' => now()->subMinute(),
        ]);

        $count = app(CommunicationEngine::class)->retryFailed();

        $this->assertSame(0, $count, 'unknown tenant must not be delivered in a possibly-wrong context');
        $outbox->refresh();
        // markAsFailed() RE-QUEUES (status pending + backoff) by platform
        // design — the point of the guard is that NOTHING was sent and the
        // operator-visible reason is recorded.
        $this->assertNotSame('sent', $outbox->status);
        $this->assertStringContainsString('not found', (string) $outbox->error_message);
    }

    public function test_retry_failed_delivers_inside_the_owning_tenant_context(): void
    {
        $message = CommunicationMessage::create([
            'tenant_id' => $this->tenantId,
            'message_type' => 'notification',
            'subject' => 'ok',
            'body' => 'b',
            'status' => 'queued',
        ]);
        $outbox = CommunicationOutbox::create([
            'tenant_id' => $this->tenantId,
            'message_id' => $message->id,
            'channel' => 'email',
            'recipient_email' => 'retry-ok@example.test',
            'status' => CommunicationOutbox::STATUS_FAILED,
            'retry_count' => 1,
            'max_retries' => 5,
            'next_retry_at' => now()->subMinute(),
        ]);

        $before = DB::selectOne('SELECT current_schema() AS s')->s;
        $count = app(CommunicationEngine::class)->retryFailed();
        $after = DB::selectOne('SELECT current_schema() AS s')->s;

        $this->assertSame(1, $count);
        $this->assertSame($before, $after, 'tenant context must be restored after retry (finally-end)');
        $this->assertSame('sent', $outbox->refresh()->status);
    }

    // ─── FR-MSG-02/06: storefront lifecycle pilot ───────────────────────

    public function test_checkout_dispatches_order_placed_through_the_engine(): void
    {
        $this->checkoutFlow();

        $msg = CommunicationMessage::query()->where('subtype', 'storefront.order.placed')->sole();
        $this->assertNotNull($msg->subject);
        $this->assertStringContainsString('sfconf', $msg->body, 'confirmation link travels to the buyer');

        $outbox = CommunicationOutbox::query()->where('message_id', $msg->id)->sole();
        $this->assertSame('email', $outbox->channel);
        $this->assertNotEmpty($outbox->recipient_email);
        $this->assertSame($this->tenantId, (string) $outbox->tenant_id);
    }

    public function test_completion_dispatches_order_paid(): void
    {
        $ctx = $this->completePaidOrder();
        $msg = CommunicationMessage::query()->where('subtype', 'storefront.order.paid')->sole();
        $this->assertStringContainsString($ctx['order_number'], $msg->subject);
        $this->assertSame($ctx['email'], CommunicationOutbox::query()->where('message_id', $msg->id)->value('recipient_email'));
    }

    public function test_merchant_toggle_disables_lifecycle_mail(): void
    {
        StorefrontSettings::current()->update(['notification_settings' => ['order_placed' => ['email' => false]]]);

        $this->checkoutFlow();
        $this->checkoutFlow();

        $this->assertSame(0, CommunicationMessage::query()->where('subtype', 'storefront.order.placed')->count());
    }

    // ─── Flows ──────────────────────────────────────────────────────────

    private function checkoutFlow(): array
    {
        $product = Product::factory()->create([
            'name' => 'S18 Mug '.Str::random(4), 'show_on_website' => true, 'is_active' => true,
            'status' => 'active', 'track_inventory' => false, 'stock_quantity' => 10, 'price' => 15, 'tax_rate' => 0,
        ]);

        $token = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $token), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, (int) $product->id, null, 1);

        $email = 's18-'.Str::random(6).'@example.test';
        $res = $this->withUnencryptedCookie(CartService::COOKIE, $token)->postJson('/api/v1/storefront/checkout', [
            'email' => $email,
            'idempotency_key' => 'idem-'.Str::random(24),
            'shipping_address' => ['name' => 'A', 'address1' => 'B', 'city' => 'C', 'postal_code' => 'D', 'country_code' => 'US'],
        ]);
        $res->assertStatus(201);

        return ['email' => $email, 'token' => $res->json('data.confirmation_token'), 'order_number' => $res->json('data.order_number')];
    }

    private function completePaidOrder(): array
    {
        $ctx = $this->checkoutFlow();
        $order = StorefrontOrder::query()->where('order_number', $ctx['order_number'])->sole();

        $gateway = PaymentGateway::query()->where('gateway_name', 'hitpay')->first()
            ?? PaymentGateway::create(['gateway_name' => 'hitpay', 'api_key' => 'k', 'is_active' => true, 'is_test_mode' => true]);
        $txn = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'pi_s18_'.Str::random(8),
            'amount' => (float) $order->total,
            'currency' => $order->currency ?? 'USD',
            'status' => 'pending',
            'metadata' => ['type' => 'storefront_order', 'order_id' => (int) $order->id],
        ]);

        app(PaymentTransactionCompletionService::class)->complete($txn, 'card');

        return $ctx;
    }
}
