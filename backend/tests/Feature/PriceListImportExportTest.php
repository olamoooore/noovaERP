<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\PriceList;
use App\Models\PriceListApplication;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * MRD pricelist-multicurrency FR-2.7 — price-list item import/export kit
 * (CSV). Export must be portable (human keys: SKU, category name, list code);
 * import is a fail-closed full replace validated BEFORE any mutation.
 */
class PriceListImportExportTest extends TenantTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) \Illuminate\Support\Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $tenantId], [
            'name' => 'Price Import Tenant',
            'email' => 'pi-'.substr(md5($tenantId), 0, 8).'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = $this->setUpTenantAdminUser('Price Import Tenant');
        CompanySettings::query()->create(['base_currency' => 'SGD']);
    }

    private function makeListWithItems(): array
    {
        $category = ProductCategory::create(['name' => 'Consumables '.uniqid()]);
        $product = Product::factory()->create(['sku' => 'IMP-'.uniqid(), 'price' => 100, 'cost' => 40, 'track_inventory' => false]);

        $list = PriceList::create([
            'name' => 'Import list',
            'code' => 'IMPORT-'.uniqid(),
            'currency_code' => 'SGD',
            'is_active' => true,
        ]);
        PriceListApplication::create(['price_list_id' => $list->id, 'applies_to_type' => 'all']);

        PriceListItem::create([
            'price_list_id' => $list->id,
            'sequence' => 10,
            'apply_on' => 'product',
            'product_id' => $product->id,
            'compute_type' => 'fixed',
            'fixed_price' => 77,
        ]);
        PriceListItem::create([
            'price_list_id' => $list->id,
            'sequence' => 20,
            'apply_on' => 'category',
            'category_id' => $category->id,
            'compute_type' => 'percentage',
            'percent' => 10,
        ]);

        return [$list, $product, $category];
    }

    public function test_export_returns_portable_csv_rows_in_engine_order(): void
    {
        [$list, $product, $category] = $this->makeListWithItems();

        $response = $this->actingAs($this->admin)->get("/api/v1/price-lists/{$list->id}/items/export");
        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('sequence,apply_on,product_sku,category_name', $csv);
        $this->assertStringContainsString($product->sku, $csv, 'product rows export the SKU, not the id');
        $this->assertStringContainsString($category->name, $csv, 'category rows export the name, not the id');
        $this->assertStringContainsString('fixed', $csv);
        $this->assertStringContainsString('percentage', $csv);

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertGreaterThanOrEqual(3, count($lines), 'header + two rules');
        $this->assertStringContainsString('77', $lines[1], 'fixed price exported');
        $this->assertStringContainsString('10', $lines[2], 'percent exported');
    }

    public function test_import_replaces_items_and_reprices_through_the_engine(): void
    {
        [$list, $product, $category] = $this->makeListWithItems();

        // Import: change the percentage rule to 25% and keep the fixed rule.
        $csv = implode("\n", [
            'sequence,apply_on,product_sku,category_name,min_quantity,max_quantity,date_start,date_end,compute_type,fixed_price,percent,base,other_pricelist_code,markup_percent',
            '10,product,'.$product->sku.',,1,,,,fixed,77,,,,',
            '20,category,,'.$category->name.',1,,,,percentage,,25,,,,',
        ]);

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('items.csv', $csv);

        $response = $this->actingAs($this->admin)->post(
            "/api/v1/price-lists/{$list->id}/items/import",
            ['file' => $file]
        );
        $response->assertOk()->assertJsonPath('imported', 2);

        $this->assertSame(2, PriceListItem::where('price_list_id', $list->id)->count());

        // Engine now prices −25% for the category fallback.
        $quote = app(\App\Services\PricingService::class)->resolvePrice([
            'product' => Product::factory()->create(['price' => 100, 'track_inventory' => false, 'category_id' => $category->id]),
            'quantity' => 1,
            'date' => now()->toDateString(),
        ]);
        // The imported list is global; a fresh product in the same category
        // resolves through the percentage rule.
        $this->assertSame(75.0, round((float) $quote['unit_price'], 2));
    }

    public function test_import_with_unknown_sku_is_rejected_before_mutation(): void
    {
        [$list, $product, $category] = $this->makeListWithItems();

        $csv = implode("\n", [
            'sequence,apply_on,product_sku,category_name,min_quantity,max_quantity,date_start,date_end,compute_type,fixed_price,percent,base,other_pricelist_code,markup_percent',
            ',product,DOES-NOT-EXIST,,1,,,,fixed,50,,,,',
        ]);

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('items.csv', $csv);

        $response = $this->actingAs($this->admin)->post(
            "/api/v1/price-lists/{$list->id}/items/import",
            ['file' => $file]
        );
        $response->assertStatus(422);

        // Fail-closed: the original two rules are untouched.
        $this->assertSame(2, PriceListItem::where('price_list_id', $list->id)->count());
    }
}
