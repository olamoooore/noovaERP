<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue (regression) — Delivery order list endpoint sorts by
 * COALESCE(created_at, updated_at) DESC NULLS LAST, id DESC.
 *
 * Background: production data contained ~200 imported delivery_orders
 * rows with `created_at IS NULL`. The original `orderBy('created_at',
 * 'desc')` then `orderBy('id', 'desc')` put those NULL rows FIRST in
 * PostgreSQL (NULL is "smaller than any value" when DESC), so the
 * user's newly-created DO-2026-0001 (id=1, created_at=2026-07-14)
 * sank to the very bottom of page 11.
 *
 * Tests below exercise the real HTTP path through the controller and
 * assert the freshly-created row appears first even when bulk-imported
 * NULL-timestamp rows share the dataset.
 */
class HR260DeliveryOrderListSortTest extends TenantTestCase
{
    protected User $user;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected Product $product;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            [
                'name' => 'HR260 DO Sort Tenant',
                'email' => 'hr260-'.substr(md5($this->tenantId), 0, 8).'@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Create test fixtures in the bound tenant context.
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create();
        // Bypass Product::created model events — the audit hook requires
        // a tenant user to be bound in the request context, which is set
        // up by EnsureTenantSearchPath on real HTTP traffic but not in
        // this lightweight test. Insert directly.
        $this->product = Product::withoutEvents(fn () => Product::factory()->create());

        $this->user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $this->user->email],
            [
                'name' => $this->user->name,
                'password' => $this->user->password,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $centralId = DB::table('public.users')->where('email', $this->user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            [
                'role' => 'admin',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $this->user->assignRole('Admin');
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => $isEntitled,
                'source' => $isEntitled ? 'tier_included' : null,
                'name' => strtoupper($key),
            ]);

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    private function makeDeliveryOrder(array $overrides = []): array
    {
        $now = now();

        $defaults = [
            'delivery_order_number' => 'DO-TEST-'.Str::random(8),
            'sales_order_id' => null,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'delivery_date' => $now->toDateString(),
            'expected_date' => null,
            'created_by' => $this->user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $id = DB::table('delivery_orders')->insertGetId(array_merge($defaults, $overrides));

        return ['id' => $id] + array_merge($defaults, $overrides);
    }

    public function test_newest_record_appears_first_regardless_of_null_timestamps(): void
    {
        // Simulate the legacy import scenario: ~200 rows with NULL
        // created_at that previously floated to the top of the list.
        // Use raw inserts so the Eloquent timestamp hook doesn't fire.
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = [
                'delivery_order_number' => 'DO-NULL-'.$i,
                'sales_order_id' => null,
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'status' => 'draft',
                'delivery_date' => '2024-01-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'created_by' => $this->user->id,
                'created_at' => null,
                'updated_at' => null,
            ];
        }
        DB::table('delivery_orders')->insert($rows);

        // The fresh record the user just created — its created_at is
        // NOW, but its id is the highest (most recent insert).
        $fresh = $this->makeDeliveryOrder([
            'delivery_order_number' => 'DO-FRESH-001',
            'delivery_date' => $now = now()->toDateString(),
        ]);

        // A much older record with a valid timestamp.
        $older = $this->makeDeliveryOrder([
            'delivery_order_number' => 'DO-OLDER-001',
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/sales/delivery-orders?per_page=50'
        );

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        $firstId = (int) $data[0]['id'];
        $this->assertSame(
            $fresh['id'],
            $firstId,
            'The freshest delivery order must be the first row even when 20 imported rows have NULL created_at.'
        );

        // The NULL-timestamp rows must come AFTER every row with a
        // valid created_at — even one that is 30 days old.
        $olderPosition = array_search($older['id'], array_column($data, 'id'), true);
        $nullPositions = array_filter(
            array_keys($data),
            fn ($idx) => str_starts_with((string) $data[$idx]['delivery_order_number'], 'DO-NULL-')
        );

        $this->assertNotFalse($olderPosition, 'Older record should appear in the first page.');
        $this->assertNotEmpty($nullPositions, 'NULL-timestamp rows should appear in the first page.');
        foreach ($nullPositions as $np) {
            $this->assertGreaterThan(
                $olderPosition,
                $np,
                "NULL-timestamp row at index {$np} must come after the timestamped row at index {$olderPosition}."
            );
        }
    }

    public function test_id_desc_used_as_tiebreaker_when_timestamps_are_equal(): void
    {
        $sameTime = now();

        $first = $this->makeDeliveryOrder([
            'delivery_order_number' => 'DO-EQUAL-A',
            'created_at' => $sameTime,
            'updated_at' => $sameTime,
        ]);
        $second = $this->makeDeliveryOrder([
            'delivery_order_number' => 'DO-EQUAL-B',
            'created_at' => $sameTime,
            'updated_at' => $sameTime,
        ]);
        $third = $this->makeDeliveryOrder([
            'delivery_order_number' => 'DO-EQUAL-C',
            'created_at' => $sameTime,
            'updated_at' => $sameTime,
        ]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/sales/delivery-orders?per_page=10'
        );

        $response->assertOk();
        $data = $response->json('data');

        $idsInOrder = array_map(fn ($r) => (int) $r['id'], $data);
        $this->assertSame(
            [$third['id'], $second['id'], $first['id']],
            array_slice($idsInOrder, 0, 3),
            'When timestamps tie, higher id (more recent insert) must come first.'
        );
    }
}
