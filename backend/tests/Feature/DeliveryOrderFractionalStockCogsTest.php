<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\DeliveryOrderService;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * #457 (DO5): completion moved (int) round(quantity_delivered) stock units
 * while COGS recognised the float quantity — 0.4 moved no stock at all and
 * 2.5 moved 3, so the stock ledger and the GL drifted. Reservation fulfilment
 * was integer too.
 *
 * Guard: stock movement == COGS quantity in base UOM, and reversal restores
 * exactly.
 */
class DeliveryOrderFractionalStockCogsTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'sales_orders', 'sales_order_lines', 'sales_order_status_history',
            'stock_reservations', 'stock_levels', 'stock_movements',
            'delivery_orders', 'delivery_order_lines',
            'journal_entries', 'journal_entry_lines', 'journal_entry_links',
        ]);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->warehouse = Warehouse::factory()->create(['is_default' => true]);
        $this->product = Product::factory()->create([
            'name' => 'Fractional Stock Widget',
            'sku' => 'FSW-'.uniqid(),
            'price' => 100,
            'cost' => 10,
            'track_inventory' => true,
        ]);
        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 50,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.delivery_order.default_requires_approval', false);
    }

    private function confirmedOrder(float $quantity): SalesOrder
    {
        $service = app(SalesOrderService::class);

        $order = $service->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->admin->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => $quantity,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        return $service->allocateOrder($service->confirmOrder($order));
    }

    private function completeFractionalDelivery(SalesOrder $order, float $quantity): DeliveryOrder
    {
        $service = app(DeliveryOrderService::class);

        $deliveryOrder = $service->createDeliveryOrder($order, [
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'sales_order_line_id' => $order->lines->first()->id,
                'quantity_delivered' => $quantity,
            ]],
            'created_by' => $this->admin->id,
        ]);

        return $service->completeDeliveryOrder($deliveryOrder);
    }

    private function cogsDebit(DeliveryOrder $deliveryOrder): float
    {
        $link = JournalEntryLink::query()
            ->where('source_document_type', 'delivery_order')
            ->where('source_document_id', $deliveryOrder->id)
            ->where('relation_type', 'post')
            ->first();

        $this->assertNotNull($link, 'The delivery completion must post a COGS journal entry.');

        return (float) JournalEntry::find($link->journal_entry_id)->lines()->sum('debit');
    }

    private function stockLevel(): StockLevel
    {
        return StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();
    }

    public function test_point_four_moves_stock_equal_to_cogs_and_reverses_exactly(): void
    {
        $order = $this->confirmedOrder(5);
        $deliveryOrder = $this->completeFractionalDelivery($order, 0.4);

        $moved = (float) StockMovement::query()
            ->where('reference_type', 'DeliveryOrder')
            ->where('reference_id', $deliveryOrder->id)
            ->sum('quantity');
        $this->assertSame(0.4, round($moved, 4), '0.4 must move 0.4 of stock — previously (int) round made it 0.');
        $this->assertSame(4.0, round($this->cogsDebit($deliveryOrder), 2), 'COGS must be 0.4 x cost 10 = 4.00.');
        $this->assertSame(49.6, round((float) $this->stockLevel()->quantity, 4), 'Stock level must drop by exactly 0.4.');

        $reservation = StockReservation::query()
            ->where('source_document_type', 'SalesOrder')
            ->where('source_document_id', $order->id)
            ->firstOrFail();
        $this->assertSame(0.4, round((float) $reservation->quantity_fulfilled, 4), 'The reservation must be fulfilled fractionally.');

        app(DeliveryOrderService::class)->reverseDeliveryOrder($deliveryOrder->fresh(), 'Fractional reversal test');

        $this->assertSame(50.0, round((float) $this->stockLevel()->quantity, 4), 'Reversal must restore exactly 0.4.');
        $restored = (float) StockMovement::query()
            ->where('reference_type', 'DeliveryOrder')
            ->where('reference_id', $deliveryOrder->id)
            ->where('type', 'return')
            ->sum('quantity');
        $this->assertSame(0.4, round($restored, 4), 'The compensating movement must be exactly 0.4.');
    }

    public function test_two_point_five_moves_two_point_five_not_three(): void
    {
        $order = $this->confirmedOrder(5);
        $deliveryOrder = $this->completeFractionalDelivery($order, 2.5);

        $moved = (float) StockMovement::query()
            ->where('reference_type', 'DeliveryOrder')
            ->where('reference_id', $deliveryOrder->id)
            ->sum('quantity');
        $this->assertSame(2.5, round($moved, 4), '2.5 must move 2.5 of stock — previously (int) round made it 3.');
        $this->assertSame(25.0, round($this->cogsDebit($deliveryOrder), 2), 'COGS must be 2.5 x cost 10 = 25.00.');
        $this->assertSame(47.5, round((float) $this->stockLevel()->quantity, 4), 'Stock level must drop by exactly 2.5.');

        app(DeliveryOrderService::class)->reverseDeliveryOrder($deliveryOrder->fresh(), 'Fractional reversal test');

        $this->assertSame(50.0, round((float) $this->stockLevel()->quantity, 4), 'Reversal must restore exactly 2.5.');
    }
}
