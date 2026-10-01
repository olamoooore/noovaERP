<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * SF-035 — P5 / FR-ARC-06 (P2 stop-serving): legacy `/store` backend surface
 * retirement.
 *
 * Appendix A §A5: "/store/* routes redirect to the tenant's site (or 410);
 * legacy public APIs return 410 except read-only order lookup if
 * contractually required; queue/waiting-room endpoints retired."
 *
 * Proves over real HTTP through the router:
 *   1. every retired legacy public endpoint answers the canonical 410
 *      (`legacy_storefront_retired`) — checkout, helpers, waiting-room
 *      queue, auth, customer mutations, tickets, loyalty;
 *   2. the CONTRACTUAL exceptions still work: guest order lookup by token
 *      (200 with a real order, 404 for a bogus token — never 410) and the
 *      customer-authenticated read-only order history (401 without a
 *      bearer token — the auth middleware still runs, so still not 410);
 *   3. the catch-all cannot shadow the retained routes (proven by #2).
 */
class SF035LegacyStorefrontRetirementTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext('sf035-retirement');
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_retired_legacy_public_endpoints_answer_canonical_410(): void
    {
        $retired = [
            ['POST', '/api/v1/web-shop/checkout/guest'],
            ['POST', '/api/v1/web-shop/checkout/confirm-payment'],
            ['GET', '/api/v1/web-shop/shipping-rates'],
            ['GET', '/api/v1/web-shop/payment-gateways'],
            ['POST', '/api/v1/web-shop/queue/join'],
            ['POST', '/api/v1/web-shop/queue/status'],
            ['POST', '/api/v1/web-shop/queue/heartbeat'],
            ['POST', '/api/v1/web-shop/queue/leave'],
            ['POST', '/api/v1/web-shop/auth/register'],
            ['POST', '/api/v1/web-shop/auth/login'],
            ['POST', '/api/v1/web-shop/auth/logout'],
            ['GET', '/api/v1/web-shop/customer/profile'],
            ['PUT', '/api/v1/web-shop/customer/profile'],
            ['GET', '/api/v1/web-shop/customer/addresses'],
            ['POST', '/api/v1/web-shop/customer/addresses'],
            ['POST', '/api/v1/web-shop/orders/1/cancel'],
            ['POST', '/api/v1/web-shop/orders/1/retry-payment'],
            ['GET', '/api/v1/web-shop/tickets'],
            ['GET', '/api/v1/web-shop/loyalty/balance'],
            ['POST', '/api/v1/web-shop/loyalty/calculate'],
        ];

        foreach ($retired as [$method, $uri]) {
            $res = $this->json($method, $uri);

            $res->assertStatus(410);
            $this->assertSame(
                'legacy_storefront_retired',
                $res->json('code'),
                "{$method} {$uri} must answer the canonical retirement code"
            );
        }
    }

    public function test_guest_order_lookup_is_retained_and_really_works(): void
    {
        $token = 'sf035token'.Str::random(10);

        DB::table('web_orders')->insert([
            'order_number' => 'LEGACY-'.Str::upper(Str::random(6)),
            'email' => 'legacy-shopper@example.test',
            'subtotal' => 25.00,
            'total' => 25.00,
            'currency' => 'USD',
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'guest_token' => $token,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A real historical order still resolves for the shopper opening an
        // old confirmation link — this is the contract exception.
        $res = $this->getJson('/api/v1/web-shop/orders/guest/'.$token);
        $res->assertStatus(200);
        $this->assertSame('legacy-shopper@example.test', $res->json('order.email'));

        // A bogus token is a 404 from the CONTROLLER (never the 410 wall) —
        //  proof the retained route is matched, not shadowed by the catch-all.
        $this->getJson('/api/v1/web-shop/orders/guest/definitely-not-a-token')
            ->assertStatus(404)
            ->assertJsonMissingPath('code');
    }

    public function test_customer_read_only_order_history_is_retained_but_requires_auth(): void
    {
        // 401 from WebCustomerAuth — not 410: the read-only order history
        // routes survive with their real middleware chain.
        $this->getJson('/api/v1/web-shop/orders')
            ->assertStatus(401);

        $this->getJson('/api/v1/web-shop/orders/1')
            ->assertStatus(401);
    }

    public function test_retirement_does_not_touch_platform_owned_public_surfaces(): void
    {
        // Invoice acceptance links, e-sign, DO portal and the NEW storefront
        // prefix are platform surfaces on the SAME public group — the
        // retirement must be strictly scoped to /web-shop/*.
        $this->getJson('/api/v1/invoice-accept/no-such-token')->assertStatus(404);
        $storefront = $this->getJson('/api/v1/storefront/catalog/products');
        $this->assertNotSame(
            410,
            $storefront->status(),
            'the new storefront prefix must not be touched by the legacy 410 wall (its own entitlement gate answers)'
        );
    }
}