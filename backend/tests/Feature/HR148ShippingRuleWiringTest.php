<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Fulfillment\Contracts\RateRequest;
use Modules\Fulfillment\Models\FulfillmentProvider;
use Modules\Fulfillment\Models\ShippingRule;
use Modules\Fulfillment\Services\CarrierConfigService;
use Modules\Fulfillment\Services\RateShopperService;
use Modules\Fulfillment\Services\ShippingRuleEngine;
use Tests\TenantTestCase;

/**
 * Issue #148 — ShippingRuleEngine (rule-based carrier selection + rule CRUD)
 * was entirely dead code: no route exposed it and RateShopperService never
 * consulted it. Now:
 *  - /fulfillment/shipping-rules CRUD is reachable (view/settings gated),
 *  - resolveCarrier() chooses the configured provider by rule/default,
 *  - shopRates() shops only the resolved provider (falls back to all when
 *    nothing resolves).
 */
class HR148ShippingRuleWiringTest extends TenantTestCase
{
    private string $tenantId;

    private FulfillmentProvider $providerA;

    private FulfillmentProvider $providerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR148 Tenant', 'email' => 'hr148-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        $this->providerA = FulfillmentProvider::create([
            'tenant_id' => $this->tenantId, 'carrier_code' => 'flat_a', 'name' => 'Default Carrier',
            'is_active' => true, 'is_default' => true, 'sort_order' => 1,
        ]);
        $this->providerB = FulfillmentProvider::create([
            'tenant_id' => $this->tenantId, 'carrier_code' => 'flat_b', 'name' => 'Premium Carrier',
            'is_active' => true, 'is_default' => false, 'sort_order' => 2,
        ]);
    }

    private function actingAsRole(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();
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

        return $user;
    }

    public function test_rule_crud_is_reachable_and_settings_gated(): void
    {
        $admin = $this->actingAsRole('admin', 'Admin');

        $create = $this->actingAs($admin)->postJson('/api/v1/fulfillment/shipping-rules', [
            'name' => 'High value orders',
            'priority' => 1,
            'is_active' => true,
            'conditions' => ['order_total_min' => 1000],
            'actions' => ['selected_provider' => ['provider_id' => $this->providerB->id, 'carrier_code' => 'flat_b']],
        ]);
        $create->assertCreated();

        $list = $this->actingAs($admin)->getJson('/api/v1/fulfillment/shipping-rules');
        $list->assertOk();
        $this->assertSame('High value orders', $list->json('0.name'));

        // Manager holds fulfillment.write but NOT fulfillment.settings.
        $manager = $this->actingAsRole('manager', 'Manager');
        $this->actingAs($manager)->postJson('/api/v1/fulfillment/shipping-rules', [
            'name' => 'Nope',
            'actions' => ['selected_provider' => ['provider_id' => $this->providerB->id]],
        ])->assertStatus(403);
    }

    public function test_resolve_carrier_prefers_the_matching_rule_then_default(): void
    {
        $engine = app(ShippingRuleEngine::class);

        ShippingRule::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Premium for high value',
            'priority' => 1,
            'is_active' => true,
            'conditions' => ['order_total_min' => 1000],
            'actions' => ['selected_provider' => ['provider_id' => $this->providerB->id, 'carrier_code' => 'flat_b']],
        ]);

        $matching = $engine->resolveCarrier($this->tenantId, [
            'order_total' => 1500, 'weight' => 1, 'destination_country' => 'MY',
        ]);
        $this->assertSame($this->providerB->id, $matching['provider_id'],
            'Matching rule must select its provider');

        $fallback = $engine->resolveCarrier($this->tenantId, [
            'order_total' => 50, 'weight' => 1, 'destination_country' => 'MY',
        ]);
        $this->assertSame($this->providerA->id, $fallback['provider_id'],
            'No matching rule must fall back to the default provider');
    }

    public function test_shop_rates_consults_the_rule_engine_first(): void
    {
        $requested = [];

        // Rule resolves to provider B; the carrier config service records which
        // providers were actually shopped (and returns no carrier for either).
        $engine = \Mockery::mock(ShippingRuleEngine::class);
        $engine->shouldReceive('resolveCarrier')->once()->andReturn([
            'provider_id' => $this->providerB->id,
            'carrier_code' => 'flat_b',
            'service_type' => null,
        ]);
        $this->app->instance(ShippingRuleEngine::class, $engine);

        $config = \Mockery::mock(CarrierConfigService::class);
        $config->shouldReceive('getCarrier')->andReturnUsing(function ($provider) use (&$requested) {
            $requested[] = $provider->id;
            throw new \Exception('no carrier configured');
        });
        $this->app->instance(CarrierConfigService::class, $config);

        $rates = app(RateShopperService::class)->shopRates(new RateRequest(
            weight: 1.0,
            destinationCountry: 'MY',
            orderTotal: 1500.0,
            tenantId: $this->tenantId,
        ));

        $this->assertCount(0, $rates, 'No carrier configured -> empty rates');
        $this->assertSame([$this->providerB->id], $requested,
            'Only the rule-selected provider may be shopped; the default provider must be skipped');
    }
}
