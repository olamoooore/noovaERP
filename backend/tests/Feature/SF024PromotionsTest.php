<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
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
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontPromotion;
use Modules\Storefront\Models\StorefrontPromotionRedemption;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\AccountService;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-024 — P3 promotions (FR-CO-09): server-validated discount codes,
 * single-use per order. Pinned semantics:
 *  - the browser submits ONLY a code; totals recompute server-side (a
 *    hostile 'discount'/'total' field in the payload is structurally unread);
 *  - whole-order discounts are applied PRO-RATA per line so the canonical
 *    JE tax determination (which reads line tax) equals the discounted order
 *    tax — no second tax path;
 *  - window / active / min_subtotal / usage_limit / per_account_limit gates
 *    answer 422 with stable codes; replay never double-redeems.
 */
class SF024PromotionsTest extends TenantTestCase
{
    private string $tenantId;

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
            'name' => 'SF024 Shop', 'email' => 'sf024-'.Str::random(6).'@example.test',
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
    }

    public function test_percent_coupon_validates_server_side_and_survives_hostile_fields(): void
    {
        $this->makePromotion(['code' => 'TENOFF', 'type' => 'percent', 'value' => 10]);

        // hostile client payload: amounts are not even read by the service
        $res = $this->checkoutWithCoupon('TENOFF', extra: [
            'discount' => '999.00', 'discount_amount' => '999.00', 'total' => '0.01',
        ]);
        $res->assertStatus(201)
            ->assertJsonPath('data.totals.subtotal', '50.00')
            ->assertJsonPath('data.totals.discount', '5.00')
            ->assertJsonPath('data.totals.tax', '4.50')
            ->assertJsonPath('data.totals.total', '49.50')
            ->assertJsonPath('data.applied_promotions.0.code', 'TENOFF');

        $order = StorefrontOrder::query()->findOrFail((int) $res->json('data.id'));
        $this->assertSame('50.00', (string) $order->subtotal);
        $this->assertSame('5.00', (string) $order->discount_amount);
        $this->assertSame('4.50', (string) $order->tax_amount);
        $this->assertSame('49.50', (string) $order->total);

        $line = $order->items()->sole();
        $this->assertSame('5.00', (string) $line->discount_amount);
        $this->assertSame('4.50', (string) $line->tax_amount);
        $this->assertSame('49.50', (string) $line->line_total);

        $this->assertSame(1, StorefrontPromotionRedemption::query()->count());
    }

    public function test_guards_answer_stable_422_codes(): void
    {
        $this->makePromotion(['code' => 'FIXED5', 'type' => 'fixed', 'value' => 5]);
        $this->makePromotion(['code' => 'NEEDS100', 'type' => 'percent', 'value' => 10, 'min_subtotal' => 100]);
        $this->makePromotion(['code' => 'EXPIRED', 'type' => 'percent', 'value' => 10, 'ends_at' => now()->subDay()]);
        $this->makePromotion(['code' => 'OFF', 'type' => 'percent', 'value' => 10, 'is_active' => false]);

        $this->checkoutWithCoupon('FIXED5')->assertStatus(201)
            ->assertJsonPath('data.totals.discount', '5.00')
            ->assertJsonPath('data.totals.total', '49.50');

        $this->checkoutWithCoupon('WRONG-CODE')->assertStatus(422)->assertJsonPath('code', 'promotion_not_found');
        $this->checkoutWithCoupon('NEEDS100')->assertStatus(422)->assertJsonPath('code', 'promotion_min_subtotal');
        $this->checkoutWithCoupon('EXPIRED')->assertStatus(422)->assertJsonPath('code', 'promotion_expired');
        $this->checkoutWithCoupon('OFF')->assertStatus(422)->assertJsonPath('code', 'promotion_not_found');
    }

    public function test_usage_limit_and_replay_convergence(): void
    {
        $this->makePromotion(['code' => 'TWICE', 'type' => 'percent', 'value' => 5, 'usage_limit' => 2]);

        $first = $this->checkoutWithCoupon('TWICE')->assertStatus(201);
        $key = 'sf24-replay-'.Str::random(16);
        $second = $this->checkoutWithCoupon('TWICE', $key)->assertStatus(201);
        $this->checkoutWithCoupon('TWICE')->assertStatus(422)->assertJsonPath('code', 'promotion_exhausted');

        // exact replay of the second call: same order, no second redemption
        $replay = $this->checkoutWithCoupon('TWICE', $key)->assertStatus(200);
        $this->assertSame($second->json('data.id'), $replay->json('data.id'));
        $this->assertTrue((bool) $replay->json('data.replay'));
        $this->assertSame(2, StorefrontPromotionRedemption::query()->count(), 'replay must not double-redeem');
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_per_account_limit_enforced_for_authenticated_buyers(): void
    {
        $this->makePromotion(['code' => 'ONCEEA', 'type' => 'percent', 'value' => 10, 'per_account_limit' => 1]);

        $email = 'sf024-acct-'.Str::random(6).'@example.test';
        ['account' => $account, 'verification_token' => $token] = app(AccountService::class)->register($email, 'Passw0rd!');
        app(AccountService::class)->verifyEmail($account->fresh()->email, $token);

        $login = $this->postJson('/api/v1/storefront/auth/login', [
            'email' => $account->fresh()->email, 'password' => 'Passw0rd!',
        ])->assertStatus(200);

        $cookies = [];
        foreach ($login->headers->getCookies() as $c) {
            $cookies[$c->getName()] = $c->getValue();
        }
        $csrf = $cookies[AccountService::CSRF_COOKIE];

        $checkout = function (string $tag) use ($cookies, $csrf, $account) {
            // authenticated buyers order through their ACCOUNT cart
            $product = Product::query()->orderBy('id')->firstOrFail();
            $cart = app(CartService::class)->activeAccountCart((int) $account->fresh()->id);
            app(CartService::class)->addOrUpdateItem($cart, $product->id, null, 1);

            return $this->withUnencryptedCookies([
                AccountService::SESSION_COOKIE => $cookies[AccountService::SESSION_COOKIE],
                AccountService::CSRF_COOKIE => $csrf,
            ])->withHeaders(['X-CSRF-Token' => $csrf])->postJson('/api/v1/storefront/checkout', [
                'email' => $account->fresh()->email,
                'idempotency_key' => 'sf24-acct-'.$tag.'-'.Str::random(12),
                'coupon_code' => 'ONCEEA',
                'shipping_address' => ['name' => 'A B', 'address1' => '24 Rd', 'city' => 'Town', 'postal_code' => '54321', 'country_code' => 'SG'],
            ]);
        };

        // a guest does not consume the per-account cap
        $this->checkoutWithCoupon('ONCEEA')->assertStatus(201);

        $checkout('one')->assertStatus(201)->assertJsonPath('data.totals.discount', '5.00');
        $checkout('two')->assertStatus(422)->assertJsonPath('code', 'promotion_per_account_limit');
    }

    public function test_completion_posts_the_discounted_tax_to_the_engine_and_stays_balanced(): void
    {
        $this->makePromotion(['code' => 'TENOFF', 'type' => 'percent', 'value' => 10]);
        $res = $this->checkoutWithCoupon('TENOFF')->assertStatus(201);
        $order = StorefrontOrder::query()->findOrFail((int) $res->json('data.id'));

        $gateway = PaymentGateway::create(['gateway_name' => 'hitpay', 'is_active' => true, 'is_test_mode' => true]);
        $transaction = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'sf24_'.Str::random(10),
            'amount' => 49.50,
            'currency' => 'SGD',
            'status' => 'completed',
            'metadata' => ['type' => 'storefront_order', 'order_id' => (int) $order->id],
        ]);
        app(PaymentTransactionCompletionService::class)->complete($transaction, 'duitnow');

        $link = \App\Models\JournalEntryLink::query()
            ->where('source_document_type', 'storefront_order')
            ->where('source_document_id', (int) $order->id)
            ->where('relation_type', 'post')
            ->firstOrFail();
        $je = \App\Models\JournalEntry::query()->with('lines')->findOrFail($link->journal_entry_id);

        $debits = round((float) $je->lines->sum('debit'), 2);
        $credits = round((float) $je->lines->sum('credit'), 2);
        $this->assertSame($debits, $credits, 'entry balanced');
        // cash 49.50 + discount 5 = revenue 50 + tax 4.5
        $this->assertSame(54.50, $debits);
        $this->assertTrue($je->lines->contains(fn ($l) => (float) $l->credit === 4.50), 'discounted tax booked, not pre-discount 5.00');
        $this->assertSame(1, StorefrontPromotionRedemption::query()->count());
    }

    public function test_admin_promotion_crud_and_redemptions_and_rbac(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        $viewer = $this->makeStaff('viewer', 'Viewer');

        $this->actingAs($viewer)->postJson('/api/v1/admin/storefront/promotions', [
            'code' => 'X', 'type' => 'percent', 'value' => 1,
        ])->assertStatus(403);

        $created = $this->actingAs($manager)->postJson('/api/v1/admin/storefront/promotions', [
            'code' => 'launch10', 'type' => 'percent', 'value' => 10,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addWeek(),
        ])->assertStatus(201)->assertJsonPath('data.code', 'LAUNCH10');

        $id = (int) $created->json('data.id');

        $this->actingAs($manager)->postJson('/api/v1/admin/storefront/promotions', [
            'code' => 'LAUNCH10', 'type' => 'percent', 'value' => 5,
        ])->assertStatus(409)->assertJsonPath('code', 'promotion_exists');

        $this->actingAs($manager)->putJson("/api/v1/admin/storefront/promotions/{$id}", [
            'code' => 'launch10', 'type' => 'percent', 'value' => 10, 'is_active' => false,
        ])->assertStatus(200)->assertJsonPath('data.is_active', false);

        // a real order consumes it (before deactivation) — redemption list
        $this->makePromotion(['code' => 'FORDISPLAY', 'type' => 'fixed', 'value' => 2]);
        $this->checkoutWithCoupon('FORDISPLAY')->assertStatus(201);
        $promo = StorefrontPromotion::query()->where('code', 'FORDISPLAY')->sole();
        $this->actingAs($viewer)->getJson("/api/v1/admin/storefront/promotions/{$promo->id}/redemptions")
            ->assertStatus(200)->assertJsonCount(1, 'data');

        $all = $this->actingAs($manager)->getJson('/api/v1/admin/storefront/promotions')->assertStatus(200);
        $codes = collect($all->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('LAUNCH10'));
        $this->assertTrue($codes->contains('FORDISPLAY'));
    }

    // ─── fixtures ─────────────────────────────────────────────────────

    private function makePromotion(array $attrs): StorefrontPromotion
    {
        $attrs['code'] = StorefrontPromotion::normalizeCode($attrs['code']);
        $attrs['is_active'] ??= true;

        return StorefrontPromotion::create($attrs);
    }

    private $productCache = null;

    private function checkoutWithCoupon(string $coupon, ?string $idempotencyKey = null, array $extra = [])
    {
        // Each checkout needs its own fresh cart (carts are consumed on order).
        $product = $this->productCache ??= $this->makeProduct();
        $guestToken = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $guestToken), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, $product->id, null, 1);

        $body = array_filter([
            'email' => 'sf24-'.Str::random(6).'@example.test',
            'idempotency_key' => $idempotencyKey ?? ('sf24-'.Str::random(24)),
            'coupon_code' => $coupon,
            'shipping_address' => ['name' => 'P Q', 'address1' => '24 Rd', 'city' => 'Town', 'postal_code' => '54321', 'country_code' => 'SG'],
        ], fn ($v) => $v !== null);
        $body = array_merge($body, $extra);

        return $this->withUnencryptedCookie(CartService::COOKIE, $guestToken)->postJson('/api/v1/storefront/checkout', $body);
    }

    private function makeProduct(array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'SF24 Product '.Str::random(4);

        return Product::factory()->create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name),
            'show_on_website' => true,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'stock_quantity' => 100,
            'price' => 50,
            'tax_rate' => 10,
        ], $overrides));
    }

    private function makeStaff(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create(['name' => $spatieRole]);
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);
        $this->bindTenantContext($this->tenantId);

        return $user;
    }
}
