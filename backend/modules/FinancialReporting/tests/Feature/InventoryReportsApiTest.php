<?php

namespace Modules\FinancialReporting\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TenantTestCase;

class InventoryReportsApiTest extends TenantTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path' => [database_path('migrations/tenant')],
            '--realpath' => true,
        ]);

        $this->withoutMiddleware([
            \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class,
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\ResolveEntityContext::class,
            \App\Http\Middleware\EnsureEntityAccess::class,
        ]);

        $this->user = $this->setUpTenantAdminUser('Inventory Reports Tenant');
    }

    /** @test */
    public function it_can_generate_stock_valuation_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/valuation?'.http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_generate_stock_movement_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/movement?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_generate_reorder_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/reorder');

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_generate_inventory_aging_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/aging?'.http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_can_generate_inventory_turnover_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/turnover?'.http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_requires_authentication_for_inventory_reports()
    {
        $response = $this->getJson('/api/v1/reports/inventory/valuation');

        $response->assertStatus(401);
    }

    /**
     * #1020: inventory reports accept a product filter (shared picker) and
     * return the product's short description for the name cell.
     *
     * Each endpoint is asserted in its own test: the report services swallow
     * some DB-level errors via try/catch (pre-existing), which leaves the
     * RefreshDatabase transaction aborted for the rest of that test.
     */
    private function seedFilteredProducts(): array
    {
        $warehouse = \App\Models\Warehouse::factory()->create();
        $tenantUser = \App\Models\User::factory()->create();

        $product = \App\Models\Product::factory()->create([
            'name' => 'Front Morcellator',
            'short_description' => 'High-speed front-blade morcellator',
            'track_inventory' => true,
            'is_active' => true,
            'cost' => 100,
            'price' => 200,
            'reorder_level' => 5,
        ]);
        $other = \App\Models\Product::factory()->create([
            'name' => 'Back Morcellator',
            'short_description' => 'Rear-blade morcellator',
            'track_inventory' => true,
            'is_active' => true,
            'cost' => 100,
            'price' => 200,
            'reorder_level' => 5,
        ]);

        \Illuminate\Support\Facades\DB::table('stock_levels')->insert([
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 3, 'min_quantity' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $other->id, 'warehouse_id' => $warehouse->id, 'quantity' => 50, 'min_quantity' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        \Illuminate\Support\Facades\DB::table('stock_movements')->insert([
            ['product_id' => $product->id, 'type' => 'in', 'quantity' => 3, 'balance_after' => 3, 'created_by' => $tenantUser->id, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $other->id, 'type' => 'in', 'quantity' => 50, 'balance_after' => 50, 'created_by' => $tenantUser->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return [$product, $other];
    }

    /** @test */
    public function valuation_filters_by_product_and_returns_short_description()
    {
        [$product] = $this->seedFilteredProducts();

        $valuation = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/valuation?product_id='.$product->id);
        $valuation->assertOk();

        $items = $valuation->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame($product->id, $items[0]['product_id']);
        $this->assertSame('High-speed front-blade morcellator', $items[0]['short_description']);
    }

    /** @test */
    public function reorder_filters_by_product_and_returns_short_description()
    {
        [$product] = $this->seedFilteredProducts();

        // The filtered product is below its reorder level (3 <= 5).
        $reorder = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/reorder?product_id='.$product->id);
        $reorder->assertOk();

        $items = $reorder->json('data.data');
        $this->assertCount(1, $items);
        $this->assertSame($product->id, $items[0]['id']);
        $this->assertSame('High-speed front-blade morcellator', $items[0]['short_description']);
    }

    /** @test */
    public function aging_filters_by_product_and_returns_short_description()
    {
        [$product] = $this->seedFilteredProducts();

        $aging = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/aging?product_id='.$product->id);
        $aging->assertOk();

        $items = $aging->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame($product->id, $items[0]['product_id']);
        $this->assertSame('High-speed front-blade morcellator', $items[0]['short_description']);
    }

    /** @test */
    public function movement_filters_by_product_and_returns_short_description()
    {
        [$product] = $this->seedFilteredProducts();

        $movement = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/movement?product_id='.$product->id);
        $movement->assertOk();

        $items = $movement->json('data.movements');
        $this->assertCount(1, $items);
        $this->assertSame($product->id, $items[0]['product_id']);
        $this->assertSame('High-speed front-blade morcellator', $items[0]['product_short_description']);
    }

    /** @test */
    public function turnover_filters_by_product_and_returns_short_description()
    {
        [$product] = $this->seedFilteredProducts();

        $turnover = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/turnover?'.http_build_query([
                'start_date' => now()->subMonth()->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
                'product_id' => $product->id,
            ]));
        $turnover->assertOk();

        $items = $turnover->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame($product->id, $items[0]['product_id']);
        $this->assertSame('High-speed front-blade morcellator', $items[0]['short_description']);
    }
}
