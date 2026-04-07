<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
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
            database_path('migrations/tenant/2026_04_07_000001_drop_unique_barcode_from_products.php'),
            database_path('migrations/tenant/2025_12_15_150010_create_product_categories_table.php'),
            database_path('migrations/tenant/2025_12_15_150020_add_category_to_products_table.php'),
            database_path('migrations/tenant/2025_12_17_000002_create_taxes_table.php'),
            database_path('migrations/tenant/2025_12_17_000003_add_commerce_fields_to_products_table.php'),
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
    }
}