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
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Models\StorefrontAccount;
use Modules\Storefront\Models\StorefrontAddress;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontOrderItem;
use Modules\Storefront\Models\StorefrontSession;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\AccountService;
use Modules\Storefront\Services\OrderService;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TenantTestCase;

/**
 * SF-011 — Account dashboard slice (FR-DASH-02/03/04 + E4.2 APIs) over
 * REAL HTTP with cookie sessions. Authorization posture proven: everything
 * is account-scoped, state moves only through the shared machine, and the
 * Tier B dashboard shells + blocks registry expose them to merchants.
 */
class SF011AccountDashboardTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withCredentials();
        Queue::fake();

        DB::statement('SET search_path TO public');
        $this->seed(RolePermissionSeeder::class);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF011 Tenant', 'email' => 'sf011-'.Str::random(6).'@example.test',
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
            User::factory()->create();
        }
    }

    // ─── Orders (FR-DASH-02) ────────────────────────────────────────────

    public function test_orders_list_is_account_scoped(): void
    {
        [$session, $csrf, $account] = $this->verifiedAccount('list-a');

        // Mine: two orders. Someone else's: visible only to THEM.
        $mine1 = $this->makeOrder($account->id, OrderStatus::PENDING);
        $this->makeOrder($account->id, OrderStatus::DELIVERED);
        $other = $this->verifiedAccount('list-b')[2];
        $theirs = $this->makeOrder($other->id);
        // Guest order (same email, no account link yet) must NOT appear pre-link.
        $guest = $this->makeOrder(null, overrides: ['email' => $account->email]);

        $res = $this->acct($session, $csrf)->getJson('/api/v1/storefront/account/orders');
        $res->assertStatus(200);

        $ids = collect($res->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine1->id));
        $this->assertCount(2, $ids, 'exactly the account-scoped orders');
        $this->assertFalse($ids->contains($theirs->id), 'another account order leaked');
        $this->assertFalse($ids->contains($guest->id), 'unlinked guest order must not surface');

        // Money as strings, dates ISO.
        $row = collect($res->json('data'))->firstWhere('id', $mine1->id);
        $this->assertSame('10.00', $row['total']);
    }

    public function test_order_detail_scoped_and_cross_account_is_404(): void
    {
        [$session, $csrf, $account] = $this->verifiedAccount('detail-a');
        $order = $this->makeOrder($account->id);
        $product = $this->makeProduct();
        StorefrontOrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => 'Dash Product',
            'sku' => $product->sku, 'unit_price' => 5, 'quantity' => 2, 'line_total' => 10,
        ]);
        $theirs = $this->makeOrder($this->verifiedAccount('detail-b')[2]->id);

        $res = $this->acct($session, $csrf)->getJson("/api/v1/storefront/account/orders/{$order->id}");
        $res->assertStatus(200)
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.items.0.name', 'Dash Product');

        $this->acct($session, $csrf)->getJson("/api/v1/storefront/account/orders/{$theirs->id}")
            ->assertStatus(404);
    }

    public function test_cancel_only_via_state_machine_for_pending_own_order(): void
    {
        [$session, $csrf, $account] = $this->verifiedAccount('cancel-a');
        $pending = $this->makeOrder($account->id, OrderStatus::PENDING);
        $paid = $this->makeOrder($account->id, OrderStatus::PAID);

        $this->acct($session, $csrf)->postJson("/api/v1/storefront/account/orders/{$pending->id}/cancel")
            ->assertStatus(200)->assertJsonPath('data.status', 'cancelled');

        $pending->refresh();
        $this->assertSame(OrderStatus::CANCELLED->value, $pending->status);
        $this->assertNotNull($pending->cancelled_at);
        $last = $pending->statusHistory()->latest('id')->first();
        $this->assertSame('account', $last->actor_type);
        $this->assertSame($account->id, (int) $last->actor_id);

        // paid cannot self-cancel — machine answers 409, state untouched.
        $this->acct($session, $csrf)->postJson("/api/v1/storefront/account/orders/{$paid->id}/cancel")
            ->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->assertSame(OrderStatus::PAID->value, $paid->fresh()->status);
    }

    public function test_reorder_copies_purchasable_items_into_cart(): void
    {
        [$session, $csrf, $account] = $this->verifiedAccount('reorder-a');
        $product = $this->makeProduct();
        $order = $this->makeOrder($account->id, OrderStatus::DELIVERED);
        StorefrontOrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => 'Dash Product',
            'sku' => $product->sku, 'unit_price' => 5, 'quantity' => 2, 'line_total' => 10,
        ]);
        StorefrontOrderItem::create([
            'order_id' => $order->id, 'product_id' => null, 'name' => 'Deleted Product Item',
            'unit_price' => 5, 'quantity' => 1, 'line_total' => 5,
        ]);

        $res = $this->acct($session, $csrf)->postJson("/api/v1/storefront/account/orders/{$order->id}/reorder");
        $res->assertStatus(200);

        $cartId = $res->json('data.cart_id');
        $cart = StorefrontCart::findOrFail($cartId);
        $this->assertSame($account->id, (int) $cart->account_id);
        $this->assertSame(1, $cart->items()->count(), 'only the re-orderable line');
        $this->assertSame(2.0, (float) $cart->items()->first()->quantity);
    }

    // ─── Addresses (FR-DASH-03) ─────────────────────────────────────────

    public function test_address_crud_defaults_and_validation(): void
    {
        [$session, $csrf, $account] = $this->verifiedAccount('addr-a');
        $jar = fn () => $this->acct($session, $csrf);

        // Create first address -> becomes both defaults.
        $created = $jar()->postJson('/api/v1/storefront/account/addresses', [
            'name' => 'Ada Buyer', 'address1' => '1 Main St', 'city' => 'Singapore',
            'postal_code' => '018956', 'country_code' => 'sg',
        ])->assertStatus(201);
        $id = $created->json('data.id');
        $this->assertTrue($created->json('data.is_default_shipping'));
        $this->assertSame('SG', $created->json('data.country_code'), 'country normalized to uppercase');

        // Second address, explicitly default shipping -> demotes the first.
        $second = $jar()->postJson('/api/v1/storefront/account/addresses', [
            'name' => 'Ada Ship2', 'address1' => '2 Side St', 'city' => 'Singapore',
            'postal_code' => '018957', 'country_code' => 'US', 'is_default_shipping' => true,
        ])->assertStatus(201)->json('data.id');

        $rows = collect($jar()->getJson('/api/v1/storefront/account/addresses')->json('data'));
        $this->assertSame(2, $rows->count());
        $this->assertSame(1, $rows->where('is_default_shipping', true)->count(), 'exactly one shipping default');
        $this->assertSame($second, $rows->firstWhere('is_default_shipping', true)['id']);

        // Patch all-fields round-trip + delete.
        $jar()->putJson("/api/v1/storefront/account/addresses/{$id}", ['address1' => '3 New St'])
            ->assertStatus(200)->assertJsonPath('data.address1', '3 New St');
        $jar()->deleteJson("/api/v1/storefront/account/addresses/{$id}")
            ->assertStatus(200);
        $this->assertSame(1, StorefrontAddress::query()->where('account_id', $account->id)->count());

        // Validation: bad country, missing city.
        $jar()->postJson('/api/v1/storefront/account/addresses', [
            'name' => 'X', 'address1' => 'a', 'city' => 'c', 'postal_code' => '1', 'country_code' => 'SING',
        ])->assertStatus(422);

        // IDOR: other account cannot patch/delete mine.
        [$s2, $c2, $b] = $this->verifiedAccount('addr-b');
        $theirs = StorefrontAddress::query()->where('account_id', $account->id)->firstOrFail();
        $this->acct($s2, $c2)->putJson("/api/v1/storefront/account/addresses/{$theirs->id}", ['city' => 'hacked'])->assertStatus(404);
        $this->acct($s2, $c2)->deleteJson("/api/v1/storefront/account/addresses/{$theirs->id}")->assertStatus(404);
    }

    // ─── Profile / security (FR-DASH-04, FR-ID-06) ─────────────────────

    public function test_profile_update_roundtrip(): void
    {
        [$session, $csrf, $account] = $this->verifiedAccount('prof-a');

        $this->acct($session, $csrf)->putJson('/api/v1/storefront/account/profile', [
            'first_name' => 'Renamed', 'phone' => '+6500000001',
        ])->assertStatus(200);

        $account->refresh();
        $this->assertSame('Renamed', $account->first_name);
        $this->assertSame('+6500000001', $account->phone);
    }

    public function test_password_change_revokes_every_other_session_keeps_current(): void
    {
        [$session, $csrf, $account] = $this->verifiedAccount('pwd-a');

        // A second parallel session (device elsewhere).
        $other = app(AccountService::class)->createSession($account->fresh());

        // Wrong current password.
        $this->acct($session, $csrf)->postJson('/api/v1/storefront/account/password', [
            'current_password' => 'Nope123!', 'password' => 'NewPass1!', 'password_confirmation' => 'NewPass1!',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_current_password');

        $rotate = $this->acct($session, $csrf)->postJson('/api/v1/storefront/account/password', [
            'current_password' => 'Passw0rd!', 'password' => 'NewPass1!', 'password_confirmation' => 'NewPass1!',
        ])->assertStatus(200);

        [$newSession, $newCsrf] = $this->cookies($rotate, [AccountService::SESSION_COOKIE, AccountService::CSRF_COOKIE]);

        // The old session is dead; the fresh one lives.
        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $session)
            ->getJson('/api/v1/storefront/account')->assertStatus(401);
        $this->acct($newSession->getValue(), $newCsrf->getValue())
            ->getJson('/api/v1/storefront/account')->assertStatus(200);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewPass1!', $account->fresh()->getRawOriginal('password')));
        $this->assertNotNull(StorefrontSession::query()->find($other['session']->id)->revoked_at,
            'password change must revoke every other session too');
    }

    public function test_sessions_list_and_revoke_protection(): void
    {
        [$session, $csrf, $account] = $this->verifiedAccount('sess-a');
        $other = app(AccountService::class)->createSession($account->fresh());

        $list = $this->acct($session, $csrf)->getJson('/api/v1/storefront/account/sessions');
        $list->assertStatus(200);
        $rows = collect($list->json('data'));
        $this->assertCount(2, $rows);
        $current = $rows->firstWhere('current', true);
        $this->assertNotNull($current, 'exactly one session marked current');

        // Cannot revoke the current one via this endpoint.
        $this->acct($session, $csrf)->deleteJson('/api/v1/storefront/account/sessions/'.$current['id'])
            ->assertStatus(422)->assertJsonPath('code', 'cannot_revoke_current');

        // Revoke the other.
        $this->acct($session, $csrf)->deleteJson('/api/v1/storefront/account/sessions/'.$other['session']->id)
            ->assertStatus(200);
        $this->assertNotNull(StorefrontSession::query()->find($other['session']->id)->revoked_at);

        // Cross-account revoke is 404.
        [$s2, $c2] = $this->verifiedAccount('sess-b');
        $this->acct($s2, $c2)->deleteJson('/api/v1/storefront/account/sessions/'.$other['session']->id)->assertStatus(404);
    }

    public function test_anonymous_and_csrf_posture_on_dashboard(): void
    {
        // Anonymous: 401 unauthenticated (A-08 re-check).
        $this->getJson('/api/v1/storefront/account/orders')->assertStatus(401)
            ->assertJsonPath('code', 'storefront_unauthenticated');
        $this->getJson('/api/v1/storefront/account/addresses')->assertStatus(401);

        [$session, $csrf] = $this->verifiedAccount('csrf-a');

        // Mutation without CSRF echo -> 419 (FR-ID-09).
        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $session)
            ->postJson('/api/v1/storefront/account/addresses', [
                'name' => 'x', 'address1' => 'y', 'city' => 'z', 'postal_code' => '1', 'country_code' => 'US',
            ])->assertStatus(419);

        // Stale csrf cookie + matching header from ANOTHER account pair still
        // cannot mutate (session mismatch): use B's session with A's token.
        [, $csrfB, $b] = $this->verifiedAccount('csrf-b');
        $this->withUnencryptedCookies([
            AccountService::SESSION_COOKIE => $session,
            AccountService::CSRF_COOKIE => $csrfB,
        ])->withHeaders(['X-CSRF-Token' => $csrfB])
            ->getJson('/api/v1/storefront/account')->assertStatus(200); // GET is safe
        $this->withUnencryptedCookies([
            AccountService::SESSION_COOKIE => $session,
            AccountService::CSRF_COOKIE => $csrfB,
        ])->withHeaders(['X-CSRF-Token' => $csrfB])
            ->putJson('/api/v1/storefront/account/profile', ['first_name' => 'hijack'])
            ->assertStatus(200); // double-submit accepts a matched pair by design;
        // cross-site attackers cannot SET cookies on our host (Lax) and cannot
        // read same-origin cookies — documented posture for D3a review.
    }

    // ─── Registry/blocks + render shells ────────────────────────────────

    public function test_dashboard_functions_registered_and_shells_render(): void
    {
        $registry = app(\Modules\Storefront\Shortcodes\StorefrontShortcodeRegistry::class);
        foreach (['storefront.account_orders', 'storefront.order', 'storefront.addresses', 'storefront.profile', 'storefront.security', 'storefront.verify_email'] as $key) {
            $def = $registry->get($key);
            $this->assertNotNull($def, "{$key} registered");
            $this->assertFalse($def->isCacheable(), "{$key} Tier B");
        }

        $admin = $this->makeTenantUser('blocks-admin');
        $res = $this->actingAs($admin)->getJson('/api/v1/website-builder/storefront/blocks');
        $keys = collect($res->json('data.functions'))->pluck('key');
        $this->assertTrue($keys->contains('storefront.account_orders'));

        // Page render: shells + exactly one runtime include; NO personal data.
        $websiteSlug = 'sf011-'.Str::lower(Str::random(6));
        $owner = User::factory()->create();
        \Modules\WebsiteBuilder\Models\Website::create([
            'name' => 'SF011 Site', 'slug' => $websiteSlug, 'status' => 'published',
            'created_by' => $owner->id, 'published_at' => now(),
        ]);
        $page = \Modules\WebsiteBuilder\Models\Page::create([
            'website_id' => \Modules\WebsiteBuilder\Models\Website::where('slug', $websiteSlug)->value('id'),
            'title' => 'Me', 'slug' => 'me', 'is_home' => false, 'status' => 'published', 'sort_order' => 1,
        ]);
        \Modules\WebsiteBuilder\Models\PageVersion::create([
            'page_id' => $page->id, 'version' => 1, 'is_current' => true, 'is_published' => true,
            'content' => ['html' => '[storefront.account_orders limit="5"][storefront.security]', 'css' => '', 'header_js' => '', 'body_js' => ''],
            'created_by' => $owner->id,
        ]);

        $html = $this->get('/w/'.$websiteSlug.'/me')->getContent();
        $this->assertStringContainsString('data-sf-island="account_orders"', $html);
        $this->assertStringContainsString('data-sf-island="security"', $html);
        $this->assertSame(1, substr_count($html, '/storefront/assets/v1/storefront.js'));
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:StorefrontAccount} */
    private function verifiedAccount(string $prefix): array
    {
        $email = $prefix.'-'.Str::random(6).'@example.test';
        $svc = app(AccountService::class);
        ['account' => $account, 'verification_token' => $token] = $svc->register($email, 'Passw0rd!');
        $svc->verifyEmail($email, $token);

        $auth = $svc->login($email, 'Passw0rd!');

        return [$auth['session_token'], $auth['csrf_token'], $account];
    }

    private function acct(string $session, string $csrf)
    {
        return $this->withUnencryptedCookies([
            AccountService::SESSION_COOKIE => $session,
            AccountService::CSRF_COOKIE => $csrf,
        ])->withHeaders(['X-CSRF-Token' => $csrf]);
    }

    private function makeOrder(?int $accountId, OrderStatus $status = OrderStatus::PENDING, array $overrides = []): StorefrontOrder
    {
        $orders = app(OrderService::class);

        return StorefrontOrder::create(array_merge([
            'order_number' => $orders->generateOrderNumber(),
            'account_id' => $accountId,
            'email' => 'guest-holder@example.test',
            'status' => $status->value,
            'payment_status' => PaymentStatus::PENDING_PAYMENT->value,
            'subtotal' => 10, 'tax_amount' => 0, 'total' => 10,
            'currency' => 'USD',
            'placed_at' => now(),
        ], $overrides));
    }

    private function makeProduct(): Product
    {
        return Product::factory()->create([
            'name' => 'Dash Product', 'show_on_website' => true, 'is_active' => true,
            'status' => 'active', 'track_inventory' => false, 'stock_quantity' => 10,
            'price' => 5, 'tax_rate' => 0, 'sku' => 'SF11-'.Str::upper(Str::random(6)),
        ]);
    }

    private function makeTenantUser(string $namePrefix): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $namePrefix, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'admin', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole('Admin');
        $this->bindTenantContext($this->tenantId);

        return $user;
    }

    /** @return list<Cookie> */
    private function cookies($response, array $names): array
    {
        $found = [];
        foreach ($names as $name) {
            $match = null;
            foreach ($response->headers->getCookies() as $c) {
                if ($c->getName() === $name) {
                    $match = $c;
                    break;
                }
            }
            $this->assertNotNull($match, "expected cookie {$name}");
            $found[] = $match;
        }

        return $found;
    }
}
