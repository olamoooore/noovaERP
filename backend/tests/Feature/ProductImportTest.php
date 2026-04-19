<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Services\InventoryService;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            database_path('migrations/tenant/2024_01_01_100001_create_chart_of_accounts_table.php'),
            database_path('migrations/tenant/2024_01_01_200001_create_products_table.php'),
            database_path('migrations/tenant/2024_01_01_200005_create_stock_movements_table.php'),
            database_path('migrations/tenant/2026_04_07_000001_drop_unique_barcode_from_products.php'),
            database_path('migrations/tenant/2025_12_15_150010_create_product_categories_table.php'),
            database_path('migrations/tenant/2025_12_15_150020_add_category_to_products_table.php'),
            database_path('migrations/tenant/2025_12_17_000001_create_settings_table.php'),
            database_path('migrations/tenant/2025_12_17_000002_create_taxes_table.php'),
            database_path('migrations/tenant/2025_12_17_000003_add_commerce_fields_to_products_table.php'),
            database_path('migrations/tenant/2025_12_18_231016_create_warehouses_table.php'),
            database_path('migrations/tenant/2026_01_01_000001_enhance_inventory_module.php'),
            database_path('migrations/tenant/2026_03_30_000004_add_short_description_to_products.php'),
        ] as $migrationPath) {
            Artisan::call('migrate', [
                '--path' => $migrationPath,
                '--realpath' => true,
            ]);
        }

        if (!Schema::hasColumn('products', 'manufacturer')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('manufacturer')->nullable();
            });
        }

        Product::unsetEventDispatcher();
    }

    public function test_import_creates_distinct_products_that_share_a_barcode_when_identity_differs(): void
    {
        $user = User::factory()->create();

        Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
            'is_default' => true,
        ]);

        Product::create([
            'name' => 'Existing Product',
            'sku' => 'EXIST-001',
            'barcode' => '2222222222222',
            'price' => 10,
            'cost' => 5,
            'track_inventory' => true,
            'stock_quantity' => 1,
            'is_active' => true,
        ]);

        $csv = implode("\n", [
            'name,sku,barcode,description,short_description,secondary_name,type,category_id,cost,price,stock_quantity,track_inventory,is_active,image_url,manufacturer,unit',
            'Alpha Product,,1111111111111,,,Artist A,product,,10,20,1,true,true,,,PCS',
            'Beta Product,,1111111111111,,,Artist B,product,,11,21,2,true,true,,,PCS',
            'Existing Product,EXIST-001,2222222222222,,,Artist C,product,,12,22,3,true,true,,,PCS',
        ]);

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $response = $this->withoutMiddleware()->actingAs($user)->post('/api/v1/products/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'created' => 2,
            'updated' => 1,
        ]);

        $this->assertDatabaseCount('products', 3);
        $this->assertDatabaseHas('products', [
            'name' => 'Alpha Product',
            'barcode' => '1111111111111',
            'secondary_name' => 'Artist A',
        ]);
        $this->assertDatabaseHas('products', [
            'name' => 'Beta Product',
            'barcode' => '1111111111111',
            'secondary_name' => 'Artist B',
        ]);
        $this->assertDatabaseHas('products', [
            'name' => 'Existing Product',
            'sku' => 'EXIST-001',
            'price' => 22,
            'stock_quantity' => 3,
        ]);
        $warehouseId = Warehouse::where('is_default', true)->value('id');
        $this->assertDatabaseHas('stock_levels', [
            'product_id' => Product::where('name', 'Alpha Product')->value('id'),
            'warehouse_id' => $warehouseId,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('stock_levels', [
            'product_id' => Product::where('name', 'Beta Product')->value('id'),
            'warehouse_id' => $warehouseId,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('stock_levels', [
            'product_id' => Product::where('sku', 'EXIST-001')->value('id'),
            'warehouse_id' => $warehouseId,
            'quantity' => 3,
        ]);
    }

    public function test_store_does_not_allocate_stock_from_product_payload(): void
    {
        $user = User::factory()->create();

        $warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
            'is_default' => true,
        ]);

        $response = $this->withoutMiddleware()->actingAs($user)->postJson('/api/v1/products', [
            'name' => 'Warehouse Allocated Product',
            'sku' => 'ALLOC-001',
            'type' => 'product',
            'cost' => 5,
            'price' => 10,
            'track_inventory' => true,
            'stock_quantity' => 7,
            'is_active' => true,
        ]);

        $response->assertStatus(201);

        $productId = Product::where('sku', 'ALLOC-001')->value('id');

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'stock_quantity' => 0,
        ]);
        $this->assertDatabaseMissing('stock_levels', [
            'product_id' => $productId,
            'warehouse_id' => $warehouse->id,
        ]);
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $productId,
            'warehouse_id' => $warehouse->id,
        ]);
    }

    public function test_store_does_not_create_default_warehouse_when_none_exists(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseCount('warehouses', 0);

        $response = $this->withoutMiddleware()->actingAs($user)->postJson('/api/v1/products', [
            'name' => 'Auto Warehouse Product',
            'sku' => 'AUTO-WH-001',
            'type' => 'product',
            'cost' => 12,
            'price' => 20,
            'track_inventory' => true,
            'stock_quantity' => 4,
            'is_active' => true,
        ]);

        $response->assertStatus(201);

        $productId = Product::where('sku', 'AUTO-WH-001')->value('id');

        $this->assertDatabaseCount('warehouses', 0);
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'stock_quantity' => 0,
        ]);
        $this->assertDatabaseMissing('stock_levels', [
            'product_id' => $productId,
        ]);
    }

    public function test_import_creates_default_warehouse_when_none_exists(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseCount('warehouses', 0);

        $csv = implode("\n", [
            'name,sku,type,cost,price,stock_quantity,track_inventory,is_active,unit',
            'Imported Warehouse Product,IMPORTED-WH-001,product,7,11,6,true,true,PCS',
        ]);

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $response = $this->withoutMiddleware()->actingAs($user)->post('/api/v1/products/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'created' => 1,
            'updated' => 0,
        ]);

        $productId = Product::where('sku', 'IMPORTED-WH-001')->value('id');
        $warehouse = Warehouse::where('is_default', true)->first();

        $this->assertNotNull($warehouse);
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'stock_quantity' => 6,
        ]);
        $this->assertDatabaseHas('stock_levels', [
            'product_id' => $productId,
            'warehouse_id' => $warehouse->id,
            'quantity' => 6,
        ]);
    }

    public function test_inventory_service_remains_the_canonical_stock_mutation_path(): void
    {
        $user = User::factory()->create();

        $warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
            'is_default' => true,
        ]);

        $product = Product::create([
            'name' => 'Inventory Service Product',
            'sku' => 'INV-SVC-001',
            'price' => 10,
            'cost' => 5,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        app(InventoryService::class)->adjustStock(
            productId: $product->id,
            warehouseId: $warehouse->id,
            quantity: 7,
            type: 'in',
            reference: 'TEST-INV-SVC-001',
        );

        $this->assertDatabaseHas('stock_levels', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 7,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'in',
            'quantity' => 7,
            'balance_after' => 7,
            'created_by' => $user->id,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 7,
        ]);
    }
}
