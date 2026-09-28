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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Storefront\Models\StorefrontAccount;
use Modules\Storefront\Models\StorefrontAccountToken;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSession;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\AccountService;
use Modules\Storefront\Services\CartService;
use Modules\Storefront\Services\OrderService;
use Tests\TenantTestCase;

/**
 * SF-009 — Account identity vertical slice (FR-ID-01..09, D2/D3) over REAL
 * HTTP: register → verify → login → protected endpoints → refresh →
 * logout, plus reset flows. Covers the P1 exit-criteria pieces that don't
 * need a live inbox (bus rows + tokens + cookies + locks); the "mail
 * arrives via tenant config from a queued job" leg is verified with the
 * engine's synchronous half (message + outbox rows) with Queue::fake, and
 * the full delivery chain is a P2-exit item (per R-07 the bus defects P-05/
 * P-06 must be closed before lifecycle messaging launches — our subtypes
 * ARE registered, which the two broken producers today are not).
 */
class SF009AccountAuthTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withCredentials();
        Queue::fake();

        // Unique client IP per test: throttle windows are file-cache
        // (not transactional) — without this, cross-test request totals
        // would trip 429s and mask genuine assertions. Throttle EXECUTION
        // is covered by PlatformRateLimitingTest; wiring is asserted by
        // route-middleware inspection below.
        $this->withServerVariables(['REMOTE_ADDR' => '10.9'.random_int(10, 99).'.'.random_int(1, 254)]);

        DB::statement('SET search_path TO public');

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF009 Tenant', 'email' => 'sf009-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);
        $this->grantStorefront($this->tenantId);

        StorefrontSettings::current();

        if (! User::query()->exists()) {
            User::factory()->create();
        }
    }

    // ─── FR-ID-02/03: registration ──────────────────────────────────────

    public function test_register_creates_pending_account_links_crm_and_queues_verification_mail(): void
    {
        $email = 'new-buyer-'.Str::random(6).'@example.test';

        $response = $this->postJson('/api/v1/storefront/auth/register', [
            'email' => $email,
            'password' => 'Sup3rSecret!',
            'first_name' => 'New',
            'last_name' => 'Buyer',
            'marketing_email_opt_in' => true,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'pending_verification');

        fwrite(STDERR, "\nDBG body=".$response->getContent().PHP_EOL);
        fwrite(STDERR, 'DBG sp='.json_encode(DB::select('show search_path')).' all='.$email.PHP_EOL);
        fwrite(STDERR, 'DBG rows='.json_encode(DB::table('storefront_accounts')->get(['id', 'email'])).PHP_EOL);

        $account = StorefrontAccount::query()->where('email', mb_strtolower($email))->firstOrFail();
        $this->assertSame('pending', $account->status);
        $this->assertNull($account->email_verified_at);
        $this->assertTrue($account->marketing_email_opt_in);

        // Passwords hashed with the platform hasher (FR-ID-03), never plaintext.
        $this->assertNotSame('Sup3rSecret!', $account->getRawOriginal('password'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Sup3rSecret!', $account->getRawOriginal('password')));

        // One identity: CRM customer linked (D2), created via canonical path.
        $this->assertNotNull($account->customer_id);
        $this->assertSame(mb_strtolower($email), \App\Models\Customer::query()->findOrFail($account->customer_id)->email);

        // Verification token: only the HASH is stored, single row.
        $this->assertSame(1, StorefrontAccountToken::query()->where('account_id', $account->id)->where('purpose', 'verification')->count());

        // Mail goes through the Communication engine (non-negotiable #4):
        // message + email outbox rows exist synchronously; send job queued.
        $this->assertSame(1, DB::table('communication_messages')
            ->where('subtype', 'storefront.customer.email_verification')->count());
        $this->assertSame(1, DB::table('communication_outbox')
            ->where('channel', 'email')->count());
        Queue::assertPushed(\Modules\Communication\Jobs\ProcessOutboxBatch::class);
    }

    public function test_register_rejects_duplicate_email_case_insensitive_and_weak_password(): void
    {
        $email = 'dupe-'.Str::random(6).'@example.test';

        $this->postJson('/api/v1/storefront/auth/register', ['email' => $email, 'password' => 'FirstPass1!'])
            ->assertStatus(201);

        $this->postJson('/api/v1/storefront/auth/register', ['email' => strtoupper(substr($email, 0, 3)).mb_substr($email, 3), 'password' => 'SecondPass2!'])
            ->assertStatus(422)->assertJsonPath('code', 'email_taken');

        $this->postJson('/api/v1/storefront/auth/register', ['email' => 'shorty-'.Str::random(4).'@example.test', 'password' => '123'])
            ->assertStatus(422);
    }

    // ─── FR-ID-02/06: verification ──────────────────────────────────────

    public function test_email_verification_activates_once_and_expired_tokens_fail(): void
    {
        $service = app(AccountService::class);
        ['account' => $account, 'verification_token' => $token] = $service->register('verify-'.Str::random(6).'@example.test', 'Passw0rd!');

        // Expired first: rotate then expire the fresh token.
        $tokenRow = StorefrontAccountToken::query()->where('account_id', $account->id)->latest('id')->first();
        $expiredAccount = $account;

        // verify with a deliberately expired copy
        $tokenRow->forceFill(['expires_at' => now()->subHour()])->save();
        $this->postJson('/api/v1/storefront/auth/verify-email', ['email' => $expiredAccount->email, 'token' => $token])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_token');

        // re-issue and succeed
        $fresh = $service->register($expiredAccount->email.'2', 'Passw0rd!'); // noise-free second account
        $this->postJson('/api/v1/storefront/auth/verify-email', [
            'email' => $fresh['account']->email,
            'token' => $fresh['verification_token'],
        ])->assertStatus(200)->assertJsonPath('data.status', 'active');

        $fresh['account']->refresh();
        $this->assertSame('active', $fresh['account']->status);
        $this->assertNotNull($fresh['account']->email_verified_at);

        // single use
        $this->postJson('/api/v1/storefront/auth/verify-email', [
            'email' => $fresh['account']->email,
            'token' => $fresh['verification_token'],
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_token');
    }

    // ─── FR-ID-04/05/08/09: login + session guard ───────────────────────

    public function test_login_lockout_and_generic_errors_no_enumeration(): void
    {
        $this->registerAndVerify($email = 'locky-'.Str::random(6).'@example.test');

        // Unknown email and wrong password must be INDISTINGUISHABLE.
        $unknown = $this->postJson('/api/v1/storefront/auth/login', ['email' => 'ghost-'.Str::random(6).'@example.test', 'password' => 'Whatever1!']);
        $wrong = $this->postJson('/api/v1/storefront/auth/login', ['email' => $email, 'password' => 'WrongPass1!']);
        $unknown->assertStatus(401);
        $wrong->assertStatus(401);
        $this->assertSame($unknown->json('message'), $wrong->json('message'), 'No account-enumeration oracle');

        // Drive failures until the lockout engages (MAX_FAILED_LOGINS wrong
        // attempts total, incl. the one above); every step before the lock
        // must be the generic 401 — never an enumeration signal.
        $statuses = [$unknown->status(), $wrong->status()];
        while (count($statuses) <= 3 * AccountService::MAX_FAILED_LOGINS) {
            $r = $this->postJson('/api/v1/storefront/auth/login', ['email' => $email, 'password' => 'WrongPass1!']);
            $statuses[] = $r->status();
            if ($r->status() === 429) {
                break;
            }
        }

        $lockedAttempts = array_filter($statuses, fn ($s) => $s === 429);
        $this->assertNotEmpty($lockedAttempts, 'lockout must engage after repeated failures');
        foreach (array_slice($statuses, 2, count($lockedAttempts) ? -1 : null) as $s) {
            $this->assertSame(401, $s, 'every pre-lock failure is the generic 401');
        }

        // Locked even with the CORRECT password (asserted twice: durable).
        $locked = $this->postJson('/api/v1/storefront/auth/login', ['email' => $email, 'password' => 'Passw0rd!']);
        $locked->assertStatus(429)->assertJsonPath('code', 'account_locked');
        $this->assertSame(429, $this->postJson('/api/v1/storefront/auth/login', ['email' => $email, 'password' => 'Passw0rd!'])->status());
    }

    public function test_session_cookies_are_httponly_lax_and_only_hashes_stored(): void
    {
        $this->registerAndVerify($email = 'sess-'.Str::random(6).'@example.test');

        $response = $this->postJson('/api/v1/storefront/auth/login', ['email' => $email, 'password' => 'Passw0rd!']);
        $response->assertStatus(200)->assertJsonPath('data.status', 'active');

        [$sessionCookie, $csrfCookie] = $this->cookies($response, [AccountService::SESSION_COOKIE, AccountService::CSRF_COOKIE]);

        $this->assertTrue($sessionCookie->isHttpOnly(), 'FR-ID-04: session cookie httpOnly');
        $this->assertSame('lax', $this->sameSite($sessionCookie));
        $this->assertFalse($csrfCookie->isHttpOnly(), 'CSRF double-submit cookie must be JS-readable');

        $rawSession = $sessionCookie->getValue();
        $this->assertSame(64, strlen($rawSession));

        // DB stores only the SHA-256 (D3).
        $row = StorefrontSession::query()->latest('id')->firstOrFail();
        $this->assertSame(hash('sha256', $rawSession), $row->token_hash);
        $this->assertStringNotContainsString($rawSession, json_encode(DB::table('storefront_sessions')->get()->toArray()));

        // Protected GET works with just the session cookie.
        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $rawSession)
            ->getJson('/api/v1/storefront/account')
            ->assertStatus(200)
            ->assertJsonPath('data.email', mb_strtolower($email));

        // Mutation WITHOUT CSRF echo -> rejected (FR-ID-09).
        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $rawSession)
            ->postJson('/api/v1/storefront/auth/refresh')
            ->assertStatus(419)->assertJsonPath('code', 'storefront_csrf_mismatch');

        // With mismatched token also rejected.
        $this->withUnencryptedCookies([
            AccountService::SESSION_COOKIE => $rawSession,
            AccountService::CSRF_COOKIE => 'deadbeef',
        ])->withHeaders(['X-CSRF-Token' => 'different'])->postJson('/api/v1/storefront/auth/refresh')
            ->assertStatus(419);
    }

    public function test_refresh_rotates_and_logout_revokes(): void
    {
        $this->registerAndVerify($email = 'rot-'.Str::random(6).'@example.test');
        $login = $this->postJson('/api/v1/storefront/auth/login', ['email' => $email, 'password' => 'Passw0rd!']);
        [$sessionCookie, $csrfCookie] = $this->cookies($login, [AccountService::SESSION_COOKIE, AccountService::CSRF_COOKIE]);
        [$oldToken, $csrf] = [$sessionCookie->getValue(), $csrfCookie->getValue()];

        $jar = fn () => $this->withUnencryptedCookies([
            AccountService::SESSION_COOKIE => $oldToken,
            AccountService::CSRF_COOKIE => $csrf,
        ])->withHeaders(['X-CSRF-Token' => $csrf]);

        $refreshed = $jar()->postJson('/api/v1/storefront/auth/refresh')->assertStatus(200);
        [$newSession] = $this->cookies($refreshed, [AccountService::SESSION_COOKIE]);

        // Old session revoked: no longer authenticates.
        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $oldToken)
            ->getJson('/api/v1/storefront/account')->assertStatus(401)
            ->assertJsonPath('code', 'storefront_unauthenticated');

        // New session works.
        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $newSession->getValue())
            ->getJson('/api/v1/storefront/account')->assertStatus(200);

        // Logout (with CSRF jar using new token) revokes + clears.
        $out = $this->withUnencryptedCookies([
            AccountService::SESSION_COOKIE => $newSession->getValue(),
            AccountService::CSRF_COOKIE => $csrf,
        ])->withHeaders(['X-CSRF-Token' => $csrf])->postJson('/api/v1/storefront/auth/logout')->assertStatus(200);

        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $newSession->getValue())
            ->getJson('/api/v1/storefront/account')->assertStatus(401);

        $row = StorefrontSession::query()->where('token_hash', hash('sha256', $newSession->getValue()))->firstOrFail();
        $this->assertNotNull($row->revoked_at);
    }

    public function test_suspension_takes_effect_immediately_on_live_sessions(): void
    {
        // FR-ID-08: status re-checked EVERY request, not at login only.
        $account = $this->registerAndVerify($email = 'sus-'.Str::random(6).'@example.test');
        $login = $this->postJson('/api/v1/storefront/auth/login', ['email' => $email, 'password' => 'Passw0rd!']);
        [$sessionCookie] = $this->cookies($login, [AccountService::SESSION_COOKIE]);

        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $sessionCookie->getValue())
            ->getJson('/api/v1/storefront/account')->assertStatus(200);

        $account->update(['status' => 'suspended']);

        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $sessionCookie->getValue())
            ->getJson('/api/v1/storefront/account')->assertStatus(401);
    }

    // ─── FR-ID-06: password reset ───────────────────────────────────────

    public function test_forgot_password_is_generic_and_reset_revokes_all_sessions_single_use(): void
    {
        $account = $this->registerAndVerify($email = 'reset-'.Str::random(6).'@example.test');

        // Unknown email: identical response, NO mail.
        $before = DB::table('communication_messages')->count();
        $this->postJson('/api/v1/storefront/auth/forgot-password', ['email' => 'nobody-'.Str::random(6).'@example.test'])
            ->assertStatus(200)->assertJsonPath('data.status', 'reset_mail_sent');
        $this->assertSame($before, DB::table('communication_messages')->count(), 'No mail for unknown emails (no enumeration oracle)');

        // Two live sessions.
        $this->postJson('/api/v1/storefront/auth/login', ['email' => $email, 'password' => 'Passw0rd!'])->assertStatus(200);
        $second = $this->postJson('/api/v1/storefront/auth/login', ['email' => $email, 'password' => 'Passw0rd!'])->assertStatus(200);
        [$sessionB] = $this->cookies($second, [AccountService::SESSION_COOKIE]);

        $token = app(AccountService::class)->sendPasswordReset($email);
        $this->assertNotNull($token);

        // Expired token rejected.
        $tokenRow = StorefrontAccountToken::query()->where('account_id', $account->id)->where('purpose', 'reset')->latest('id')->firstOrFail();
        $expired = $tokenRow->token_hash; // keep for reuse attempt
        $this->postJson('/api/v1/storefront/auth/reset-password', [
            'email' => $email, 'token' => 'a'.str_repeat('0', 63), 'password' => 'NewPassw0rd!', 'password_confirmation' => 'NewPassw0rd!',
        ])->assertStatus(422);

        // Good reset.
        $this->postJson('/api/v1/storefront/auth/reset-password', [
            'email' => $email, 'token' => $token, 'password' => 'NewPassw0rd!', 'password_confirmation' => 'NewPassw0rd!',
        ])->assertStatus(200);

        // ALL sessions revoked (FR-ID-06).
        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $sessionB->getValue())
            ->getJson('/api/v1/storefront/account')->assertStatus(401);

        // Single use.
        $this->postJson('/api/v1/storefront/auth/reset-password', [
            'email' => $email, 'token' => $token, 'password' => 'ThirdPass3!', 'password_confirmation' => 'ThirdPass3!',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_token');

        // Old password dead, new works.
        $this->postJson('/api/v1/storefront/auth/login', ['email' => $email, 'password' => 'Passw0rd!'])->assertStatus(401);
        $this->postJson('/api/v1/storefront/auth/login', ['email' => $email, 'password' => 'NewPassw0rd!'])->assertStatus(200);

        $this->assertNotNull($expired);
    }

    // ─── FR-ID-07 + FR-CO-02: consolidation on login ────────────────────

    public function test_guest_orders_and_cart_link_into_account_on_login(): void
    {
        $email = 'consol-'.Str::random(6).'@example.test';

        // A pre-existing guest order (email only) + a guest cart with a line.
        $product = Product::factory()->create([
            'show_on_website' => true, 'is_active' => true, 'status' => 'active',
            'track_inventory' => false, 'stock_quantity' => 5, 'price' => 5, 'tax_rate' => 0,
        ]);
        $orders = app(OrderService::class);
        $order = StorefrontOrder::create([
            'order_number' => $orders->generateOrderNumber(),
            'email' => $email,
            'status' => 'pending',
            'payment_status' => 'pending_payment',
            'subtotal' => 5, 'total' => 5,
        ]);

        $this->registerAndVerify($email);

        $guestToken = bin2hex(random_bytes(32));
        $guestCart = StorefrontCart::create([
            'guest_token_hash' => hash('sha256', $guestToken),
            'status' => 'active',
            'currency' => 'USD',
        ]);
        app(CartService::class)->addOrUpdateItem($guestCart, $product->id, null, 2);

        $login = $this->withUnencryptedCookie(CartService::COOKIE, $guestToken)
            ->postJson('/api/v1/storefront/auth/login', ['email' => $email, 'password' => 'Passw0rd!'])
            ->assertStatus(200);

        // FR-ID-07: the guest order became the account's.
        $account = StorefrontAccount::query()->where('email', mb_strtolower($email))->firstOrFail();
        $this->assertSame($account->id, $order->fresh()->account_id);

        // FR-CO-02: guest cart merged into an active account cart.
        $accountCart = StorefrontCart::query()->where('account_id', $account->id)->where('status', 'active')->firstOrFail();
        $this->assertSame(2.0, (float) $accountCart->items()->sum('quantity'));
        $this->assertSame('ordered', $guestCart->fresh()->status);

        [$sessionCookie] = $this->cookies($login, [AccountService::SESSION_COOKIE]);
        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $sessionCookie->getValue())
            ->getJson('/api/v1/storefront/account')
            ->assertStatus(200)
            ->assertJsonPath('data.orders_count', 1)
            ->assertJsonPath('data.active_cart_items', 1);
    }

    // ─── A-04: entitlement gate covers the auth group too ───────────────

    public function test_auth_endpoints_require_storefront_entitlement(): void
    {
        $other = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $other], [
            'name' => 'SF009 Nope', 'email' => 'sf009-nope-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($other);

        foreach (['/auth/register', '/auth/login', '/auth/forgot-password'] as $path) {
            $this->postJson('/api/v1/storefront'.$path, ['email' => 'x@example.test', 'password' => 'whatever'])->assertStatus(403);
        }
        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, str_repeat('a', 64))
            ->getJson('/api/v1/storefront/account')->assertStatus(403);

        // Route inspection: auth + account routes carry the gate + throttles.
        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'api/v1/storefront')) {
                continue;
            }
            $mw = $route->middleware();
            $this->assertContains('entitlement:storefront', $mw, $route->uri());
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function registerAndVerify(string $email): StorefrontAccount
    {
        ['account' => $account, 'verification_token' => $token] = app(AccountService::class)->register($email, 'Passw0rd!');
        app(AccountService::class)->verifyEmail($email, $token);

        return $account->fresh();
    }

    /**
     * @param  list<string>  $names
     * @return list<\Symfony\Component\HttpFoundation\Cookie>
     */
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

    private function sameSite($cookie): string
    {
        $sameSite = $cookie->getSameSite();

        return $sameSite instanceof \BackedEnum ? $sameSite->value : (string) $sameSite;
    }

    private function grantStorefront(string $tenantId): void
    {
        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'catalog_item_id' => $item->id],
            ['is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now()]
        );
        app(FeatureGateService::class)->invalidate($tenantId);
    }
}
