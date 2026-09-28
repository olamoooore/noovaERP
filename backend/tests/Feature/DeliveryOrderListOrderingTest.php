<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * The delivery order list must be ordered by CREATED date, newest first.
 *
 * Regression: the query sorted by COALESCE(created_at, updated_at), so legacy
 * documents touched by data repairs (updated_at = now) floated above this
 * week's documents and the list looked randomly mixed.
 */
class DeliveryOrderListOrderingTest extends TenantTestCase
{
    protected User $user;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected Product $product;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\EnsureInventoryAccess::class);

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'DO Ordering Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->user = User::factory()->create();
        $this->user->assignRole(UserRole::MANAGER->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'manager',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::create([
            'name' => 'Ordering Warehouse',
            'code' => 'ORD-'.uniqid(),
            'is_active' => true,
        ]);
        $this->product = Product::factory()->create([
            'name' => 'Ordering Widget',
            'sku' => 'ORD-'.uniqid(),
            'track_inventory' => false,
            'price' => 10,
            'cost' => 5,
        ]);
    }

    private function createDeliveryOrder(): int
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/sales/delivery-orders', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => 'Ordering line',
                'quantity_delivered' => 1,
                'unit_price' => 10,
            ]],
        ]);
        $response->assertStatus(201, $response->getContent());

        return (int) $response->json('data.id');
    }

    public function test_delivery_orders_are_listed_by_created_date_not_updated_date(): void
    {
        $oldDocument = $this->createDeliveryOrder();
        $newDocument = $this->createDeliveryOrder();

        // Simulate the production pattern: an old, imported document that was
        // recently touched by a data repair (updated_at = now).
        DB::table('delivery_orders')->where('id', $oldDocument)->update([
            'created_at' => now()->subDays(400),
            'updated_at' => now(),
        ]);
        DB::table('delivery_orders')->where('id', $newDocument)->update([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/sales/delivery-orders?per_page=10');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame($newDocument, $ids[0], 'The most recently CREATED document must come first.');
        $this->assertLessThan(
            array_search($oldDocument, $ids, true),
            array_search($newDocument, $ids, true),
            'A recently-updated old document must not outrank a newer document.',
        );
    }

    public function test_legacy_document_without_created_at_sorts_by_delivery_date(): void
    {
        $legacy = $this->createDeliveryOrder();
        $recent = $this->createDeliveryOrder();

        // Legacy rows may still arrive without created_at (until the backfill
        // migration runs on their schema) — delivery_date breaks the tie.
        DB::table('delivery_orders')->where('id', $legacy)->update([
            'created_at' => null,
            'delivery_date' => now()->subYear()->toDateString(),
        ]);
        DB::table('delivery_orders')->where('id', $recent)->update([
            'created_at' => now()->subDay(),
            'delivery_date' => now()->subDay()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/sales/delivery-orders?per_page=10');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame($recent, $ids[0]);
    }
}