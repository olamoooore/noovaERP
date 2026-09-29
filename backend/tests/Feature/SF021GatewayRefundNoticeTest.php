<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\SecureWebhookAccess;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\RefundTransaction;
use App\Models\User;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-021 — P-04b gateway-initiated refunds (A-18 second direction): a
 * signature-valid HitPay `refunded` notice (merchant refunded in the gateway
 * dashboard) must record + reverse exactly once through the canonical
 * handler chain — never silently drop (the historic behavior). Legacy/web/POS
 * transactions keep their frozen ignore-behavior (SF005 boundary) and unknown
 * tenants stay fail-closed.
 */
class SF021GatewayRefundNoticeTest extends TenantTestCase
{
    private string $tenantId;

    private PaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        Queue::fake();

        DB::statement('SET search_path TO public');
        $this->bindTenantContext((string) Str::uuid());
        $this->seed(RolePermissionSeeder::class);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF021 Shop', 'email' => 'sf021-'.Str::random(6).'@example.test',
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

        // Posting/audit actor (P-01: the lookup is written by the model hook
        // from this tenant context so the webhook resolves the right schema).
        $owner = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $owner->email],
            ['name' => $owner->name, 'password' => $owner->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $owner->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'owner', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $owner->assignRole('Owner');
        $this->bindTenantContext($this->tenantId);

        $this->gateway = PaymentGateway::create([
            'gateway_name' => 'hitpay',
            'publishable_key' => 'pk_sf21_'.Str::random(6),
            'api_key' => 'sk_sf21_'.Str::random(6),
            'api_secret' => 'as_sf21_'.Str::random(12),
            'is_active' => true,
            'is_test_mode' => true,
        ]);

        $this->artisan('storefront:templates:seed')->assertSuccessful();
    }

    public function test_signed_refunded_notice_refunds_the_order_once_with_reversal_and_mail(): void
    {
        [$order, $transaction] = $this->paidOrder(40.0);

        $res = $this->deliverRefundNotice($transaction, '40.00');
        $res->assertStatus(200)->assertJson(['status' => 'success']);

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::REFUNDED->value, $fresh->status);
        $this->assertSame(PaymentStatus::REFUNDED->value, $fresh->payment_status);

        $transaction->refresh();
        $this->assertSame(40.0, round((float) $transaction->refunded_amount, 2));
        $this->assertSame('refunded', $transaction->status);

        $this->assertCount(1, JournalEntryLink::query()->where('source_document_type', 'storefront_refund')->get(),
            'the notice posts exactly one reversal (A-18)');
        $this->assertSame(1, RefundTransaction::query()->where('status', 'completed')->count());
        $this->assertSame(1, \App\Models\Payment::query()->where('method', 'refund')->count());

        $msg = CommunicationMessage::query()->where('subtype', 'storefront.order.refunded')->sole();
        $this->assertStringContainsString($order->order_number, $msg->subject);
        $this->assertStringContainsString('Gateway refund event', $msg->body);

        // the console history shows a GATEWAY-attributed move with the notice reason
        $history = $fresh->statusHistory()->latest('id')->get();
        $noticeRow = $history->first(fn ($h) => str_contains((string) $h->note, 'Gateway refund event'));
        $this->assertNotNull($noticeRow);
        $this->assertSame('gateway', $noticeRow->actor_type);
    }

    public function test_retry_delivery_of_the_same_event_dedupes_at_the_gate(): void
    {
        [$order, $transaction] = $this->paidOrder(30.0);

        $payload = $this->noticePayload($transaction, '30.00');
        $eventId = 'evt_sf21_retry_'.Str::random(12);
        $body = $payload + ['id' => $eventId];

        $first = $this->postJson('/api/v1/webhooks/hitpay', $body + ['hmac' => $this->sign($body)]);
        $first->assertStatus(200);

        // HitPay retries the SAME event (fresh per-delivery hmac): the
        // event-id dedupe short-circuits it with 200 'already processed' —
        // never a second controller entry, hence never a second refund.
        $second = $this->postJson('/api/v1/webhooks/hitpay', $body + ['hmac' => 'retried-'.substr(md5($eventId), 0, 56)]);
        $second->assertStatus(200)->assertJson(['status' => 'already processed']);

        $this->assertSame(30.0, round((float) $transaction->fresh()->refunded_amount, 2));
        $this->assertCount(1, JournalEntryLink::query()->where('source_document_type', 'storefront_refund')->get());
        $this->assertSame(1, CommunicationMessage::query()->where('subtype', 'storefront.order.refunded')->count(),
            'a gateway retry must not re-refund or re-notify');
    }

    public function test_notice_for_a_legacy_transaction_keeps_the_frozen_ignore_behavior(): void
    {
        // SF005 boundary: the refund notice path may ONLY touch storefront
        // transactions. A legacy web_order transaction's notice is logged
        // and dropped with zero side effects (historic behavior preserved).
        $legacyTxn = PaymentTransaction::create([
            'payment_gateway_id' => $this->gateway->id,
            'transaction_id' => 'pi_sf21_legacy_'.Str::random(8),
            'amount' => 12.00,
            'currency' => 'SGD',
            'status' => 'completed',
            'metadata' => ['type' => 'web_order', 'order_id' => 999999],
        ]);

        $this->deliverRefundNotice($legacyTxn, '12.00')
            ->assertStatus(200)->assertJson(['status' => 'success']);

        $this->assertSame(0.0, round((float) ($legacyTxn->fresh()->refunded_amount ?? 0), 2),
            'legacy transactions are untouched by the new notice path');
        $this->assertSame(0, JournalEntryLink::query()->where('source_document_type', 'storefront_refund')->count());
        $this->assertSame(0, RefundTransaction::query()->where('source_type', PaymentTransaction::class)
            ->where('source_id', $legacyTxn->id)->count());
    }

    public function test_notice_amount_below_the_payment_records_a_partial(): void
    {
        [$order, $transaction] = $this->paidOrder(50.0);

        $this->deliverRefundNotice($transaction, '20.00')->assertStatus(200);

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PARTIALLY_REFUNDED->value, $fresh->payment_status);
        $this->assertSame(OrderStatus::PAID->value, $fresh->status);
        $this->assertSame(20.0, round((float) $transaction->fresh()->refunded_amount, 2));
    }

    public function test_tampered_notice_is_rejected_without_any_state_change(): void
    {
        [$order, $transaction] = $this->paidOrder(22.0);

        $payload = $this->noticePayload($transaction, '22.00');
        $res = $this->postJson('/api/v1/webhooks/hitpay', $payload + [
            'id' => 'evt_sf21_bad_'.Str::random(8),
            'hmac' => str_repeat('0', 64),
        ]);
        $res->assertStatus(400);

        $this->assertSame(PaymentStatus::PAID->value, $order->fresh()->payment_status);
        $this->assertSame(0, JournalEntryLink::query()->where('source_document_type', 'storefront_refund')->count());
    }

    // ─── machinery ────────────────────────────────────────────────────

    private function deliverRefundNotice(PaymentTransaction $transaction, string $amount)
    {
        $payload = $this->noticePayload($transaction, $amount);
        $eventId = 'evt_sf21_'.Str::random(16);

        // each delivery is a fresh event id (HitPay regenerates per retry;
        // dedup-by-event-id must not mask the handler's own idempotency)
        $body = $payload + ['id' => $eventId];
        $response = $this->postJson('/api/v1/webhooks/hitpay', $body + ['hmac' => $this->sign($body)]);

        Cache::forget(SecureWebhookAccess::replayKey('hitpay', $eventId));

        return $response;
    }

    private function noticePayload(PaymentTransaction $transaction, string $amount): array
    {
        return [
            'payment_request_id' => $transaction->transaction_id,
            'status' => 'refunded',
            'amount' => $amount,
            'currency' => 'SGD',
            'reference_number' => 'sf21-ref-'.Str::random(6),
        ];
    }

    private function sign(array $payload): string
    {
        $data = $payload;
        unset($data['hmac']);
        ksort($data);

        return hash_hmac('sha256', http_build_query($data), (string) $this->gateway->api_secret);
    }

    /** @return array{0: StorefrontOrder, 1: PaymentTransaction} */
    private function paidOrder(float $price): array
    {
        $product = $this->makeProduct(['price' => $price]);
        [$guestToken] = $this->cartWith($product->id, 1);
        $res = $this->checkout($guestToken);
        $this->assertSame(201, $res->status(), $res->getContent());
        $order = StorefrontOrder::query()->findOrFail((int) $res->json('data.id'));

        $transaction = PaymentTransaction::create([
            'payment_gateway_id' => $this->gateway->id,
            'transaction_id' => 'sfpay_'.Str::random(12),
            'amount' => $price,
            'currency' => 'SGD',
            'status' => 'completed',
            'metadata' => ['type' => 'storefront_order', 'order_id' => (int) $order->id],
        ]);
        app(PaymentTransactionCompletionService::class)->complete($transaction, 'duitnow');

        return [$order->fresh(), $transaction->fresh()];
    }

    private function cartWith(int $productId, int $qty): array
    {
        $token = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $token), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, $productId, null, $qty);

        return [$token, $cart];
    }

    private function checkout(string $guestToken)
    {
        return $this->withUnencryptedCookie(CartService::COOKIE, $guestToken)->postJson('/api/v1/storefront/checkout', [
            'email' => 'sf21-'.Str::random(6).'@example.test',
            'idempotency_key' => 'sf21-'.Str::random(24),
            'shipping_address' => ['name' => 'W H', 'address1' => '21 Rd', 'city' => 'Town', 'postal_code' => '54321', 'country_code' => 'SG'],
        ]);
    }

    private function makeProduct(array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'SF21 Product '.Str::random(4);

        return Product::factory()->create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name),
            'show_on_website' => true,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'stock_quantity' => 100,
            'price' => 10,
            'tax_rate' => 0,
        ], $overrides));
    }
}
