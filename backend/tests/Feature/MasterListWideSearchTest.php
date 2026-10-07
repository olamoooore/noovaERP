<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCustomField;
use App\Models\ProductCustomFieldValue;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAddress;
use App\Services\TagService;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Brand;
use Modules\Inventory\Models\Supplier;
use Tests\TenantTestCase;

/**
 * Master lists must also have wide search: vendors through their address book
 * and tags, suppliers through their contact/address fields, and products
 * through their brand/category names and description.
 */
class MasterListWideSearchTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = 'ddddeeee-dddd-4eee-8fff-eeeeeeeeeeee';
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'name' => 'Master List Wide Search Tenant',
            'email' => 'master-list-wide@example.com',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($tenantId);

        $this->user = User::create([
            'name' => 'Tenant User',
            'email' => 'master-list-wide-user@test.local',
            'password' => bcrypt('password'),
        ]);
    }

    /**
     * @return int[]
     */
    private function searchIds(string $path, string $term): array
    {
        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->getJson($path.'?search='.urlencode($term).'&per_page=50')
            ->assertOk();

        return collect($response->json('data'))->pluck('id')->map('intval')->all();
    }

    public function test_vendor_list_search_matches_address_book_and_tags(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'Astra Supplies Pte Ltd']);
        VendorAddress::create([
            'vendor_id' => $vendor->id,
            'type' => 'billing',
            'label' => 'HQ',
            'street' => '12 Jalan Kilang',
            'city' => 'Petaling Jaya',
            'country' => 'Malaysia',
        ]);

        $this->assertContains($vendor->id, $this->searchIds('/api/v1/accounting/vendors', 'Petaling Jaya'));

        app(TagService::class)->attach($vendor, ['Preferred'], $this->user->id);
        $this->assertContains($vendor->id, $this->searchIds('/api/v1/accounting/vendors', 'preferred'));
        $this->assertSame([], $this->searchIds('/api/v1/accounting/vendors', 'Nobody Zzz'));
    }

    public function test_supplier_list_search_matches_contact_and_address_fields(): void
    {
        $supplier = Supplier::create([
            'name' => 'Astra Supplies Pte Ltd',
            'code' => 'SUP-'.Str::upper(Str::random(5)),
            'contact_name' => 'Tan Toh Lick',
            'city' => 'Petaling Jaya',
            'postal_code' => '47301',
            'country' => 'MY',
        ]);

        $this->assertContains($supplier->id, $this->searchIds('/api/v1/suppliers', 'Tan Toh'));
        $this->assertContains($supplier->id, $this->searchIds('/api/v1/suppliers', 'Petaling Jaya'));
        $this->assertSame([], $this->searchIds('/api/v1/suppliers', 'Nobody Zzz'));
    }

    public function test_product_search_matches_brand_category_and_description(): void
    {
        $brand = Brand::create(['name' => 'Zeiss Medical', 'slug' => 'zeiss-'.Str::random(4), 'is_active' => true]);
        $category = ProductCategory::create(['name' => 'Surgical Instruments', 'slug' => 'surgical-'.Str::random(4)]);

        $product = Product::factory()->create([
            'name' => 'Precision Forceps',
            'sku' => 'PF-'.Str::upper(Str::random(5)),
            'description' => 'Stainless steel micro forceps',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'track_inventory' => false,
        ]);

        $this->assertContains($product->id, $this->searchIds('/api/v1/products', 'Zeiss'));
        $this->assertContains($product->id, $this->searchIds('/api/v1/products', 'Surgical Instruments'));
        $this->assertContains($product->id, $this->searchIds('/api/v1/products', 'micro forceps'));
    }

    public function test_product_search_matches_model_code_in_short_description(): void
    {
        $product = Product::factory()->create([
            'name' => 'Disposable Hysteroscope',
            'sku' => '21005',
            'short_description' => 'HTx60',
            'description' => null,
            'track_inventory' => false,
        ]);

        $this->assertContains($product->id, $this->searchIds('/api/v1/products', 'HTx60'));
        $this->assertContains($product->id, $this->searchIds('/api/v1/products', 'htx60'));
    }

    public function test_product_search_matches_every_declared_field_including_metafields(): void
    {
        $brand = Brand::create(['name' => 'Zeiss Medical', 'slug' => 'zeiss-'.Str::random(4), 'is_active' => true]);
        $category = ProductCategory::create(['name' => 'Surgical Instruments', 'slug' => 'surgical-'.Str::random(4)]);

        $product = Product::factory()->create([
            'name' => 'Precision Forceps',
            'secondary_name' => 'Micro Grasper',
            'sku' => 'SKU-XYZ-1',
            'barcode' => 'BC-99887766',
            'manufacturer_part_number' => 'MPN-4455',
            'description' => 'Long description with Zirconium coating',
            'short_description' => 'SD-ShortTag-77',
            'attributes' => ['Model' => 'HTxM-9000', 'Material' => 'Titanium Alloy'],
            'lot_number' => 'LOT-ALPHA-9',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'track_inventory' => false,
        ]);

        $customField = ProductCustomField::create([
            'name' => 'Sterile Ref',
            'slug' => 'sterile-ref-'.Str::random(4),
            'type' => 'text',
            'is_active' => true,
        ]);
        ProductCustomFieldValue::create([
            'product_id' => $product->id,
            'product_custom_field_id' => $customField->id,
            'value' => 'CF-VAL-4242',
        ]);

        $terms = [
            'name' => 'Precision',
            'secondary name' => 'Micro Grasper',
            'sku' => 'SKU-XYZ-1',
            'barcode' => 'BC-99887766',
            'manufacturer part' => 'MPN-4455',
            'description' => 'Zirconium',
            'short description' => 'SD-ShortTag-77',
            'attribute model' => 'HTxM-9000',
            'attribute value' => 'Titanium Alloy',
            'lot number' => 'LOT-ALPHA-9',
            'brand' => 'Zeiss Medical',
            'category' => 'Surgical Instruments',
            'custom field value' => 'CF-VAL-4242',
        ];

        foreach ($terms as $label => $term) {
            $this->assertContains(
                $product->id,
                $this->searchIds('/api/v1/products', $term),
                "Expected product to match {$label} term '{$term}'.",
            );
        }

        $this->assertSame([], $this->searchIds('/api/v1/products', 'Nobody Zzz'));
    }
}
