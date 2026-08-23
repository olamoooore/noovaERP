<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class PosBarcodeResolutionTest extends TenantTestCase
{

    protected \App\Models\User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Tenant migrations and central migrations are run by the
        // RefreshDatabase trait (via TenantTestCase::refreshTestDatabase).
        // We do NOT call migrate:fresh here because it would wipe the
        // tenant tables that the test needs.

        // Authenticate as a user so ProductAuditLog::log() can attribute
        // via TenantUserHelper. The helper resolves to the matching
        // tenant users row (created centrally here for simplicity).
        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);
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
        // The schema enforces unique SKUs, so we cannot create two
        // products with the same SKU. Instead, verify that repeated
        // lookups for the same SKU return the same product (which is
        // the underlying property the original test was asserting).
        $product = Product::create([
            'name' => 'Deterministic SKU Product',
            'sku' => 'DETERMINISTIC-SKU',
            'price' => 100.00,
            'status' => 'active',
            'type' => 'product',
        ]);

        $result1 = Product::findSellableByBarcodeOrSku('DETERMINISTIC-SKU');
        $result2 = Product::findSellableByBarcodeOrSku('DETERMINISTIC-SKU');

        $this->assertNotNull($result1);
        $this->assertEquals(
            $product->id,
            $result1['product']->id,
            'SKU lookup should return the matching product'
        );
        $this->assertEquals(
            $result1['product']->id,
            $result2['product']->id,
            'SKU lookup should be deterministic'
        );
    }
}
