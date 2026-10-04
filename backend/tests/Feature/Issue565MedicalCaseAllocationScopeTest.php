<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #565 (MC3): `store()` only checked that `sales_order_item_id` exists —
 * not that the linked sales order line belongs to the case customer, that the
 * product matches the line's product, that the lot belongs to that product, or
 * that `allocated_qty` fits the line's open quantity. `allocate()` only
 * overwrote `allocated_qty` and set the status; it reserved no stock.
 *
 * Real HTTP through the router (POST /api/v1/medical-cases and
 * POST /api/v1/medical-cases/{id}/allocate), not source-string assertions.
 */
class Issue565MedicalCaseAllocationScopeTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Customer $otherCustomer;

    private Product $product;

    private Product $otherProduct;

    private Warehouse $warehouse;

    private SalesOrderLine $line;

    private SalesOrderLine $otherCustomerLine;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '56556565-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'sales_order_lines',
            'warehouses', 'stock_levels', 'stock_reservations', 'product_batches',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'Issue565 User',
            'email' => 'issue565-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->user->assignRole('admin');

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->otherCustomer = Customer::factory()->create();
        $this->product = Product::factory()->create();
        $this->otherProduct = Product::factory()->create();
        $this->warehouse = Warehouse::factory()->create();

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-565-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
        ]);
        $this->line = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 565 line',
            'product_id' => $this->product->id,
            'quantity' => 100,
            'unit_price' => 25,
        ]);

        $otherOrder = SalesOrder::create([
            'sales_order_number' => 'SO-565-'.Str::upper(Str::random(6)),
            'customer_id' => $this->otherCustomer->id,
            'created_by' => $this->user->id,
        ]);
        $this->otherCustomerLine = SalesOrderLine::create([
            'sales_order_id' => $otherOrder->id,
            'description' => 'Issue 565 foreign line',
            'product_id' => $this->product->id,
            'quantity' => 100,
            'unit_price' => 25,
        ]);
    }

    private function item(array $overrides = []): array
    {
        return array_merge([
            'sales_order_item_id' => $this->line->id,
            'product_id' => $this->product->id,
            'allocated_qty' => 10,
        ], $overrides);
    }

    private function storeCase(array $items)
    {
        return $this->actingAs($this->user)->postJson('/api/v1/medical-cases', [
            'customer_id' => $this->customer->id,
            'items' => $items,
        ]);
    }

    private function storage(Product $product, float $quantity): StockLevel
    {
        return StockLevel::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);
    }

    private function allocate(MedicalCaseItem $item, float $quantity)
    {
        return $this->actingAs($this->user)->postJson(
            '/api/v1/medical-cases/'.$item->medical_case_id.'/allocate',
            ['items' => [['id' => $item->id, 'allocated_qty' => $quantity]]],
        );
    }

    public function test_store_rejects_a_line_from_another_customers_order(): void
    {
        $response = $this->storeCase([
            $this->item(['sales_order_item_id' => $this->otherCustomerLine->id]),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.sales_order_item_id']);

        $this->assertSame(0, MedicalCaseItem::count(), 'A line from another customer must not create a case item.');
    }

    public function test_store_rejects_a_product_that_differs_from_the_linked_order_line(): void
    {
        $response = $this->storeCase([
            $this->item(['product_id' => $this->otherProduct->id]),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.product_id']);

        $this->assertSame(0, MedicalCaseItem::count());
    }

    public function test_store_rejects_a_lot_that_does_not_belong_to_the_line_product(): void
    {
        $foreignBatch = ProductBatch::create([
            'product_id' => $this->otherProduct->id,
            'batch_number' => 'LOT-565-FOREIGN',
            'quantity' => 5,
        ]);

        $response = $this->storeCase([
            $this->item(['lot_id' => $foreignBatch->id]),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.lot_id']);

        $this->assertSame(0, MedicalCaseItem::count());
    }

    public function test_store_accepts_a_lot_that_belongs_to_the_line_product(): void
    {
        $batch = ProductBatch::create([
            'product_id' => $this->product->id,
            'batch_number' => 'LOT-565-MATCH',
            'quantity' => 5,
        ]);

        $response = $this->storeCase([
            $this->item(['lot_id' => $batch->id]),
        ]);

        $response->assertCreated();

        $this->assertSame(
            $batch->id,
            (int) MedicalCaseItem::firstOrFail()->lot_id,
            'A lot of the linked line product is accepted.',
        );
    }

    public function test_store_rejects_allocating_more_than_the_order_lines_open_quantity(): void
    {
        $this->storeCase([$this->item(['allocated_qty' => 60])])->assertCreated();

        $response = $this->storeCase([$this->item(['allocated_qty' => 50])]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.allocated_qty']);

        $this->assertSame(1, MedicalCaseItem::count(), 'Only the first case line may be created (100 - 60 = 40 open).');
    }

    public function test_allocate_rejects_quantities_above_the_order_lines_open_quantity(): void
    {
        $this->storeCase([$this->item(['allocated_qty' => 10])])->assertCreated();
        $item = MedicalCaseItem::firstOrFail();

        $response = $this->allocate($item, 101);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.allocated_qty']);

        $this->assertSame('draft', MedicalCase::firstOrFail()->status);
        $this->assertSame(10.0, round((float) $item->fresh()->allocated_qty, 4));
        $this->assertSame(0, StockReservation::count());
    }

    public function test_allocate_reserves_stock_through_the_stock_reservation_service(): void
    {
        $stock = $this->storage($this->product, 100);

        $this->storeCase([$this->item(['allocated_qty' => 10])])->assertCreated();
        $item = MedicalCaseItem::firstOrFail();

        $this->allocate($item, 10)->assertOk();

        $reservation = StockReservation::firstOrFail();
        $this->assertSame('MedicalCase', $reservation->source_document_type);
        $this->assertSame((int) $item->medical_case_id, (int) $reservation->source_document_id);
        $this->assertSame($item->id, (int) $reservation->source_document_line_id);
        $this->assertSame($this->product->id, (int) $reservation->product_id);
        $this->assertSame($this->warehouse->id, (int) $reservation->warehouse_id);
        $this->assertSame(10.0, round((float) $reservation->quantity, 4));
        $this->assertSame('open', $reservation->status);

        $this->assertSame(10.0, round((float) $stock->fresh()->reserved_quantity, 4));
        $this->assertSame('allocated', MedicalCase::firstOrFail()->status);
    }

    public function test_allocate_rejects_more_than_the_available_stock(): void
    {
        $stock = $this->storage($this->product, 5);

        $this->storeCase([$this->item(['allocated_qty' => 10])])->assertCreated();
        $item = MedicalCaseItem::firstOrFail();

        $response = $this->allocate($item, 10);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.allocated_qty']);

        $this->assertSame(0, StockReservation::count(), 'Nothing may be reserved when stock is insufficient.');
        $this->assertSame(0.0, round((float) $stock->fresh()->reserved_quantity, 4));
        $this->assertSame('draft', MedicalCase::firstOrFail()->status);
    }

    public function test_cancelling_an_allocated_case_releases_its_stock_reservation(): void
    {
        $stock = $this->storage($this->product, 100);

        $this->storeCase([$this->item(['allocated_qty' => 10])])->assertCreated();
        $item = MedicalCaseItem::firstOrFail();
        $this->allocate($item, 10)->assertOk();

        $this->actingAs($this->user)
            ->postJson('/api/v1/medical-cases/'.$item->medical_case_id.'/cancel')
            ->assertOk();

        $this->assertSame('released', StockReservation::firstOrFail()->status);
        $this->assertSame(0.0, round((float) $stock->fresh()->reserved_quantity, 4));
        $this->assertSame('cancelled', MedicalCase::firstOrFail()->status);
    }
}
