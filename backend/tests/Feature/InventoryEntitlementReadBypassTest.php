<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Validates the EnsureInventoryAccess fix (docs/module-gap-review-2026-08-24/
 * MODULE-FRAMEWORK-GAP-ANALYSIS-2026-08-24.md, gap I1).
 *
 * Before the fix, EnsureInventoryAccess::handle() returned $next($request)
 * immediately for GET/HEAD/OPTIONS, before the tenant-entitlement check ran —
 * a tenant without the 'inventory' module entitled could still read all
 * inventory data. This test exercises the real router/middleware chain via
 * getJson (not source-string assertions or direct middleware instantiation)
 * to prove reads are now blocked exactly like writes when not entitled.
 */
class InventoryEntitlementReadBypassTest extends TenantTestCase
{
    protected User $user;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'Inventory Entitlement Test Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });

        $this->user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'owner',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_get_request_is_blocked_when_inventory_not_entitled(): void
    {
        $this->mockFeatureGate(false);

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock/low-stock');

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'The inventory module is not enabled for this account.');
    }

    public function test_get_request_passes_through_when_inventory_entitled(): void
    {
        $this->mockFeatureGate(true);

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock/low-stock');

        $this->assertNotEquals(403, $response->status());
    }

    public function test_post_request_is_still_blocked_when_inventory_not_entitled(): void
    {
        $this->mockFeatureGate(false);

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock/adjust', []);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'The inventory module is not enabled for this account.');
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(function (string $tenantId, string $key) use ($isEntitled) {
                return [
                    'key' => $key,
                    'is_entitled' => $isEntitled,
                    'source' => $isEntitled ? 'tier_included' : null,
                    'name' => strtoupper($key),
                ];
            });

        $mock->shouldReceive('all')
            ->andReturn($isEntitled ? [['key' => 'inventory', 'source' => 'tier_included', 'name' => 'INVENTORY']] : []);

        $mock->shouldReceive('invalidate')->andReturnNull();

        app()->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        \App\Models\Feature::query()->updateOrCreate(
            ['key' => 'inventory'],
            [
                'name' => 'Inventory',
                'description' => 'Inventory module feature gate for tests',
                'is_core' => false,
                'price' => 0,
            ]
        );
    }
}
