<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenantFromWhatsAppWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Facades\Tenancy as TenancyFacade;
use Tests\TestCase;

class ResolveTenantFromWhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_resolves_tenant_from_shared_lookup_table(): void
    {
        $tenantId = '1f62326d-6ad7-4da8-9dfe-4d8bff5c2ab1';

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'WhatsApp Test Tenant',
            'email' => 'whatsapp-test@example.com',
            'plan' => 'trial',
            'trial_ends_at' => null,
            'subscription_ends_at' => null,
            'data' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $apiKey = 'wapibiz_test_lookup_key';
        $keyHash = hash('sha256', $apiKey);

        DB::table('whatsapp_webhook_tenant_lookups')->insert([
            'key_hash' => $keyHash,
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenancyFacade::shouldReceive('run')
            ->once()
            ->withArgs(function ($resolvedTenant, $callback) use ($tenantId) {
                return $resolvedTenant->id === $tenantId && is_callable($callback);
            })
            ->andReturnUsing(function ($resolvedTenant, $callback) {
                return $callback();
            });

        $request = Request::create('/api/v1/whatsapp/webhooks/order-created', 'POST');
        $request->headers->set('x-api-key', $apiKey);

        $middleware = new ResolveTenantFromWhatsAppWebhook();

        $response = $middleware->handle($request, function (Request $request) use ($tenantId) {
            $this->assertSame($tenantId, $request->attributes->get('tenant_id'));
            $this->assertSame($tenantId, $request->attributes->get('tenant')->id);

            return new Response('ok', 200);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($tenantId, Cache::get("whatsapp_webhook_tenant:{$keyHash}"));
    }

    /** @test */
    public function it_rejects_unknown_api_keys_without_running_tenancy(): void
    {
        TenancyFacade::shouldReceive('run')->never();

        $request = Request::create('/api/v1/whatsapp/webhooks/order-created', 'POST');
        $request->headers->set('x-api-key', 'wapibiz_unknown_key');

        $middleware = new ResolveTenantFromWhatsAppWebhook();
        $response = $middleware->handle($request, fn () => new Response('unexpected', 200));

        $this->assertSame(401, $response->getStatusCode());
    }
}
