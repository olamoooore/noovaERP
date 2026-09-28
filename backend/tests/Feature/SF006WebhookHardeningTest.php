<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\SecureWebhookAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PayPalGateway;
use Tests\TenantTestCase;

/**
 * SF-006 — Webhook platform hardening (MRD Appendix A §A4 / Appendix C).
 *
 * Covers the P0 payment-correctness defects the Storefront module depends on:
 *
 *  P-01 (PAY-R7): the central tenant lookup is now WRITTEN at intent
 *       creation from tenant context, so gateway webhooks resolve tenants
 *       deterministically. Verified end-to-end through the REAL webhook
 *       route chain (signature-less): before the fix every delivery died
 *       with 404 at tenant resolution; now it reaches signature
 *       verification and is rejected there — with NO state change before
 *       the signature check (PAY-R8/C6 rule 4).
 *
 *  P-02 (PAY-R8, C6 rule 8): replay lifecycle = keyed on the GATEWAY EVENT
 *       id (not per-delivery headers), in-flight dedup, "done" written only
 *       on 2xx outcomes, failures release the marker so legitimate retries
 *       re-run.
 *
 *  P-03 (PAY-R9): PayPal verification reads the TENANT gateway row's
 *       config.webhook_id and sends the raw event body (byte-exact) to the
 *       verify endpoint — no more undefined global config making all
 *       PayPal webhooks fail.
 */
class SF006WebhookHardeningTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF006 Tenant', 'email' => 'sf006-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);
    }

    // ═══ P-01 + P-02 interaction: real webhook route chain ═══════════════

    public function test_webhook_resolves_tenant_from_lookup_written_at_intent_creation(): void
    {
        $gateway = $this->makeGateway('hitpay');

        // Intent created through the MODEL (browser/checkout path) — the
        // created hook must have written the central lookup (P-01).
        $transaction = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'hitpay_chain_'.Str::random(8),
            'amount' => 100.00,
            'currency' => 'SGD',
            'status' => 'pending',
            'metadata' => ['type' => 'storefront_order', 'order_id' => 1],
        ]);

        $this->assertSame(1, DB::table('public.payment_webhook_tenant_lookups')
            ->where('gateway_name', 'hitpay')
            ->where('transaction_id', $transaction->transaction_id)
            ->count(), 'P-01: lookup row must exist immediately after intent creation');

        // Deliver an UNSIGNED webhook. Pre-P-01 this 404'd at tenant
        // resolution ("Transaction not found") and money never landed from
        // webhooks. Now resolution SUCCEEDS and the request reaches
        // signature verification, which must reject it (400).
        $response = $this->postJson('/api/v1/webhooks/hitpay', [
            'id' => 'evt_chain_'.Str::random(8),
            'payment_request_id' => $transaction->transaction_id,
            'status' => 'completed',
        ]);

        $this->assertSame(400, $response->getStatusCode(),
            'Resolution must succeed (not 404); rejection must happen at SIGNATURE stage (not earlier): '.$response->getContent());

        // And the rejection happened BEFORE any state change (PAY-R8/C6-4).
        $transaction->refresh();
        $this->assertSame('pending', $transaction->status, 'No state change may occur before signature verification');
        $this->assertNull($transaction->processed_at);
    }

    public function test_tenant_resolution_still_fails_closed_for_unknown_transactions(): void
    {
        $eventId = 'evt_unknown_'.Str::random(8);

        $response = $this->postJson('/api/v1/webhooks/hitpay', [
            'id' => $eventId,
            'payment_request_id' => 'pi_never_created',
            'status' => 'completed',
        ]);

        // Unknown transaction → 404, never a default tenant (FR-PLAT-01).
        $response->assertStatus(404);

        // P-02: the failed delivery RELEASED its replay marker (pre-fix the
        // 'processing' mark lingered 300s and swallowed genuine retries).
        $this->assertNull(Cache::get(SecureWebhookAccess::replayKey('hitpay', $eventId)),
            'Failed webhook delivery must leave no blocking replay marker behind');
    }

    // ═══ P-02: replay lifecycle through the real router ══════════════════

    public function test_replay_marker_is_keyed_on_gateway_event_id_not_per_delivery_header(): void
    {
        $eventId = 'evt_shared_key_'.Str::random(8);
        $key = SecureWebhookAccess::replayKey('hitpay', $eventId);

        Cache::forget($key);
        Cache::put($key, 'done', 3600);

        // Two RETRY deliveries of the SAME event: different hmac/signature
        // header values (gateways regenerate these per delivery). Old code
        // keyed on the hmac → the second delivery would have processed the
        // event twice; new code short-circuits both on the event id.
        $first = $this->postJson('/api/v1/webhooks/hitpay', [
            'id' => $eventId, 'hmac' => 'signature-attempt-AAA', 'payment_request_id' => 'pi_x',
        ]);
        $second = $this->postJson('/api/v1/webhooks/hitpay', [
            'id' => $eventId, 'hmac' => 'signature-attempt-BBB', 'payment_request_id' => 'pi_x',
        ]);

        $first->assertStatus(200)->assertJson(['status' => 'already processed']);
        $second->assertStatus(200)->assertJson(['status' => 'already processed'],
            'Retry with a different per-delivery signature header must still map to the same event-id dedupe key');

        Cache::forget($key);
    }

    public function test_in_flight_marker_blocks_concurrent_duplicate_without_processing(): void
    {
        $eventId = 'evt_inflight_'.Str::random(8);
        $key = SecureWebhookAccess::replayKey('hitpay', $eventId);

        Cache::forget($key);
        Cache::put($key, 'processing', 300);

        $this->postJson('/api/v1/webhooks/hitpay', [
            'id' => $eventId, 'payment_request_id' => 'pi_inflight', 'status' => 'completed',
        ])->assertStatus(200)->assertJson(['status' => 'already processed']);

        // In-flight state must NOT be promoted/destroyed by the short circuit.
        $this->assertSame('processing', Cache::get($key));

        Cache::forget($key);
    }

    public function test_failed_delivery_releases_marker_and_success_marks_done(): void
    {
        // A temporary route exercising the REAL middleware chain over HTTP
        // (not a class instantiation) with controlled outcomes.
        Route::middleware([\App\Http\Middleware\SecureWebhookAccess::class.':testgw'])
            ->group(function () {
                Route::post('/hook-ok', fn (\Illuminate\Http\Request $r) => response()->json(['status' => 'success']));
                Route::post('/hook-fail', fn (\Illuminate\Http\Request $r) => response()->json(['error' => 'boom'], 500));
            });

        // 2xx → promoted to 'done' (long window), so later replays are
        // permanently short-circuited at the front door.
        $okEvent = 'evt_ok_'.Str::random(8);
        $okKey = SecureWebhookAccess::replayKey('testgw', $okEvent);
        Cache::forget($okKey);

        $this->postJson('/hook-ok', ['id' => $okEvent])->assertStatus(200);
        $this->assertSame('done', Cache::get($okKey), 'Success must mark the event done (C6 rule 8)');

        // replayed success → short circuit
        $this->postJson('/hook-ok', ['id' => $okEvent])->assertStatus(200)->assertJson(['status' => 'already processed']);

        // non-2xx → marker released, retry re-runs (no swallowed retries).
        $failEvent = 'evt_fail_'.Str::random(8);
        $failKey = SecureWebhookAccess::replayKey('testgw', $failEvent);
        Cache::forget($failKey);

        $this->postJson('/hook-fail', ['id' => $failEvent])->assertStatus(500);
        $this->assertNull(Cache::get($failKey), 'Failure must release the marker so gateway retries are not swallowed');

        // Second attempt of the same failed event runs the handler again
        // (proves it was NOT short-circuited): flip to a 200 by hitting ok-route.
        $this->postJson('/hook-ok', ['id' => $failEvent])->assertStatus(200)->assertJsonMissing(['status' => 'already processed']);

        Cache::forget($okKey);
        Cache::forget($failKey);
    }

    // ═══ P-03: PayPal webhook id source + raw body ═══════════════════════

    public function test_paypal_verify_uses_tenant_gateway_config_and_raw_body(): void
    {
        $recorded = [];

        Http::fake(function ($request) use (&$recorded) {
            $decoded = json_decode($request->body(), true);
            if (is_array($decoded)) {
                $recorded[] = $decoded;
            }

            if (str_contains($request->url(), 'oauth2/token')) {
                return Http::response(['access_token' => 'TEST-TOKEN'], 200);
            }

            return Http::response(['verification_status' => 'SUCCESS'], 200);
        });

        $gateway = $this->makeGateway('paypal', config: ['webhook_id' => 'WH-SF006-123']);

        $service = new PayPalGateway($gateway);

        $rawBody = '{"id":"evt-pp-1","event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"xx":"va-lue"}}';

        $request = \Illuminate\Http\Request::create(
            '/api/v1/webhooks/paypal',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PAYPAL-TRANSMISSION-ID' => 'TID-1',
                'HTTP_PAYPAL-TRANSMISSION-TIME' => '2026-09-28T00:00:00Z',
                'HTTP_PAYPAL-TRANSMISSION-SIG' => 'SIG-1',
                'HTTP_PAYPAL-CERT-URL' => 'https://paypal-cert.example/1',
                'HTTP_PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            ],
            $rawBody,
        );

        $this->assertTrue($service->verifyWebhook($request), 'Verification must succeed when the tenant has a configured webhook id');

        $verifyCall = collect($recorded)->last();
        $this->assertSame('WH-SF006-123', $verifyCall['webhook_id'], 'PAY-R9: webhook id must come from payment_gateways.config, not a global constant');
        $this->assertSame(json_decode($rawBody, true), $verifyCall['webhook_event'], 'The byte-exact raw event body must be verified, not a re-parsed array');
    }

    public function test_paypal_verify_fails_loudly_when_gateway_has_no_webhook_id(): void
    {
        Http::fake(fn () => Http::response(['access_token' => 'TEST-TOKEN'], 200));

        $gateway = $this->makeGateway('paypal'); // no config.webhook_id

        $service = new PayPalGateway($gateway);
        $request = \Illuminate\Http\Request::create('/api/v1/webhooks/paypal', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertFalse($service->verifyWebhook($request), 'Unconfigured webhook id must fail closed (and log the actionable hint)');
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>|null  $config
     */
    private function makeGateway(string $name, ?array $config = null): PaymentGateway
    {
        return PaymentGateway::create([
            'gateway_name' => $name,
            'publishable_key' => 'pk_test_'.Str::random(6),
            'api_key' => 'sk_test_'.Str::random(6),
            'api_secret' => 'as_test_'.Str::random(6),
            'webhook_secret' => $name === 'hitpay' ? null : 'whsec_'.Str::random(6),
            'is_active' => true,
            'is_test_mode' => true,
            'config' => $config,
        ]);
    }
}
