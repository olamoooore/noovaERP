<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TagService;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\Supplier;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * The purchase-order LIST search must be as wide as the sales lists: typing a
 * supplier, its address/contact person, a line description or a tag must find
 * the PO.
 *
 * Regression: the list only matched po_number + supplier name.
 */
class PurchaseOrderWideSearchTest extends TenantTestCase
{
    private User $user;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '55556666-7777-4888-9999-000011112222',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'suppliers', 'warehouses', 'purchase_orders', 'purchase_order_items',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        $this->withoutMiddleware([\App\Http\Middleware\EnsureInventoryAccess::class]);

        $this->supplier = Supplier::create([
            'name' => 'Astra Supplies Pte Ltd',
            'code' => 'SUP-ASTRA',
            'contact_name' => 'Tan Toh Lick',
            'city' => 'Petaling Jaya',
            'postal_code' => '47301',
            'country' => 'MY',
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'PO Search Warehouse',
            'code' => 'POS-'.uniqid(),
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'PO Search Product',
            'sku' => 'PO-SEARCH-'.uniqid(),
            'track_inventory' => false,
            'price' => 10,
            'cost' => 5,
        ]);
    }

    private function createPo(string $lineDescription): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-SEARCH-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'order_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'line_type' => 'product',
            'sequence' => 0,
            'product_id' => $this->product->id,
            'description' => $lineDescription,
            'quantity_ordered' => 1,
            'quantity_received' => 0,
            'unit_cost' => 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 10,
        ]);

        return $po;
    }

    /**
     * @return int[]
     */
    private function searchPoIds(string $term): array
    {
        $response = $this->getJson('/api/v1/purchase-orders?search='.urlencode($term).'&per_page=50')
            ->assertOk();

        return collect($response->json('data'))->pluck('id')->map('intval')->all();
    }

    public function test_po_search_matches_supplier_identity_and_address(): void
    {
        $po = $this->createPo('Consulting');

        $this->assertContains($po->id, $this->searchPoIds('Astra Supplies'));
        $this->assertContains($po->id, $this->searchPoIds('Tan Toh'));
        $this->assertContains($po->id, $this->searchPoIds('Petaling Jaya'));
    }

    public function test_po_search_matches_line_contents_number_and_tags(): void
    {
        $po = $this->createPo('Widget Assembly');

        $this->assertContains($po->id, $this->searchPoIds('Widget Assembly'));
        $this->assertContains($po->id, $this->searchPoIds($po->po_number));

        app(TagService::class)->attach($po, ['Urgent'], $this->user->id);
        $this->assertContains($po->id, $this->searchPoIds('urgent'));

        $this->assertSame([], $this->searchPoIds('Nobody Contactperson Zzz'));
    }
}
