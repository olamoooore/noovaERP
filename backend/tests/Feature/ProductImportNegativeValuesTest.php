<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Tests\TenantTestCase;

/**
 * Issue #136 — CSV product import accepted negative cost/price as valid, then
 * silently stripped the sign during parsing (validator preserved '-', parser
 * discarded it), importing -500 as +500 with no warning.
 */
class ProductImportNegativeValuesTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext('product-import-negative-tenant');

        Product::unsetEventDispatcher();

        Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function import(string $csv)
    {
        $user = User::factory()->create();

        return $this->withoutMiddleware()->actingAs($user)->post('/api/v1/products/import', [
            'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
        ]);
    }

    public function test_negative_cost_is_rejected_not_sign_stripped(): void
    {
        $csv = implode("\n", [
            'name,sku,cost,price,track_inventory,is_active',
            'Negative Cost Product,NEG-001,-500,20,false,true',
        ]);

        $response = $this->import($csv);

        $response->assertOk();
        $response->assertJsonPath('created', 0);

        $errors = json_encode($response->json('errors'));
        $this->assertStringContainsString('Negative value', $errors);
        $this->assertStringContainsString('cost', $errors);

        // The row must not be imported at all — neither as -500 nor as 500.
        $this->assertDatabaseMissing('products', ['sku' => 'NEG-001']);
        $this->assertDatabaseMissing('products', ['name' => 'Negative Cost Product']);
    }

    public function test_negative_price_and_stock_are_rejected(): void
    {
        $csv = implode("\n", [
            'name,sku,cost,price,stock_quantity,track_inventory,is_active',
            'Negative Price Product,NEG-002,10,-20,5,false,true',
            'Negative Stock Product,NEG-003,10,20,-5,false,true',
        ]);

        $response = $this->import($csv);

        $response->assertOk();
        $response->assertJsonPath('created', 0);
        $this->assertDatabaseMissing('products', ['sku' => 'NEG-002']);
        $this->assertDatabaseMissing('products', ['sku' => 'NEG-003']);
    }

    /**
     * Issue #248: vendor_price is parsed by the same parseNumericValue() and is
     * min:0 on the direct API, but was missing from the #136 guard list.
     */
    public function test_negative_vendor_price_is_rejected(): void
    {
        $csv = implode("\n", [
            'name,sku,cost,price,vendor_price,stock_quantity,track_inventory,is_active',
            'Negative Vendor Price Product,NEG-004,10,20,-50,5,false,true',
        ]);

        $response = $this->import($csv);

        $response->assertOk();
        $response->assertJsonPath('created', 0);
        $this->assertDatabaseMissing('products', ['sku' => 'NEG-004']);
    }

    /** Positive control: currency symbols/thousands separators still parse. */
    public function test_positive_values_with_currency_symbols_still_import(): void
    {
        $csv = implode("\n", [
            'name,sku,cost,price,track_inventory,is_active,unit',
            'Priced Product,POS-001,"$1,000.50","2,500.00",false,true,PCS',
        ]);

        $response = $this->import($csv);

        $response->assertOk();
        $response->assertJsonPath('created', 1);

        $product = Product::where('sku', 'POS-001')->firstOrFail();
        $this->assertEqualsWithDelta(1000.50, (float) $product->cost, 0.001);
        $this->assertEqualsWithDelta(2500.00, (float) $product->price, 0.001);
    }
}
