<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosBarcodeResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    /** @test */
    public function test_duplicate_barcode_returns_deterministic_product(): void
    {
        $product1 = Product::create([
            'name' => 'Product First',
            'sku' => 'FIRST-SKU',
            'barcode' => 'DUPLICATE-BARCODE',
            'price' => 100.00,
            'status' => 'active',
            'type' => 'product',
        ]);

        $product2 = Product::create([
            'name' => 'Product Second',
            'sku' => 'SECOND-SKU',
            'barcode' => 'DUPLICATE-BARCODE',
            'price' => 200.00,
            'status' => 'active',
            'type' => 'product',
        ]);

        $result1 = Product::findSellableByBarcodeOrSku('DUPLICATE-BARCODE');
        $result2 = Product::findSellableByBarcodeOrSku('DUPLICATE-BARCODE');

        $this->assertNotNull($result1);
        $this->assertNotNull($result2);
        $this->assertEquals(
            $result1['product']->id,
            $result2['product']->id,
            'Barcode lookup should be deterministic'
        );
    }

    /** @test */
    public function test_duplicate_barcode_always_returns_lowest_id(): void
    {
        $product1 = Product::create([
            'name' => 'Product First',
            'sku' => 'FIRST-SKU',
            'barcode' => 'DUPLICATE-BARCODE',
            'price' => 100.00,
            'status' => 'active',
            'type' => 'product',
        ]);

        $product2 = Product::create([
            'name' => 'Product Second',
            'sku' => 'SECOND-SKU',
            'barcode' => 'DUPLICATE-BARCODE',
            'price' => 200.00,
            'status' => 'active',
            'type' => 'product',
        ]);

        $result = Product::findSellableByBarcodeOrSku('DUPLICATE-BARCODE');

        $this->assertNotNull($result);
        $this->assertEquals(
            min($product1->id, $product2->id),
            $result['product']->id,
            'Barcode lookup should return the product with lowest ID'
        );
    }

    /** @test */
    public function test_variant_barcode_takes_precedence(): void
    {
        $product = Product::create([
            'name' => 'Main Product',
            'sku' => 'MAIN-SKU',
            'barcode' => 'MAIN-BARCODE',
            'price' => 100.00,
            'status' => 'active',
            'type' => 'product',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant',
            'sku' => 'VARIANT-SKU',
            'barcode' => 'VARIANT-BARCODE',
            'price' => 150.00,
            'is_active' => true,
        ]);

        $result = Product::findSellableByBarcodeOrSku('VARIANT-BARCODE');

        $this->assertNotNull($result);
        $this->assertEquals('variant', $result['type']);
        $this->assertEquals($variant->id, $result['variant']->id);
    }

    /** @test */
    public function test_sku_lookup_is_deterministic(): void
    {
        $product1 = Product::create([
            'name' => 'Product First',
            'sku' => 'DUPLICATE-SKU',
            'price' => 100.00,
            'status' => 'active',
            'type' => 'product',
        ]);

        $product2 = Product::create([
            'name' => 'Product Second',
            'sku' => 'DUPLICATE-SKU',
            'price' => 200.00,
            'status' => 'active',
            'type' => 'product',
        ]);

        $result1 = Product::findSellableByBarcodeOrSku('DUPLICATE-SKU');
        $result2 = Product::findSellableByBarcodeOrSku('DUPLICATE-SKU');

        $this->assertEquals(
            $result1['product']->id,
            $result2['product']->id,
            'SKU lookup should be deterministic'
        );
    }
}