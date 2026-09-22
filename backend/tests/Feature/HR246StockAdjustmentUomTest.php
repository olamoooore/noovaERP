<?php

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Models\FiscalPeriod;
use App\Models\JournalEntryLine;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockAdjustmentReason;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Services\StockAdjustmentService;
use Tests\TenantTestCase;

/**
 * Issue #246 — stock adjustments ignored UOM entirely: a manual stock-in of
 * "2 Boxes" was recorded as 2 base units. Validators now accept uom_id and
 * StockAdjustmentService converts through ProductUomConverter.
 *
 * Issue #250 — InventoryService::adjustStock() took `int $quantity`, silently
 * truncating fractional UOM-converted quantities while the GL posted the full
 * value. The parameter and the valuation delta are now float.
 */
class HR246StockAdjustmentUomTest extends TenantTestCase
{
    private User $user;

    private Warehouse $warehouse;

    private Product $product;

    private ProductUom $box;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) Str::uuid();
        $this->bindTenantContextWithPermissions($tenantId);

        $this->assertTenantSchemaReady([
            'products', 'warehouses', 'stock_levels', 'stock_movements',
            'stock_adjustment_reasons', 'journal_entries', 'journal_entry_lines',
        ]);

        $this->user = User::create([
            'name' => 'HR246 User',
            'email' => 'hr246-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($this->user);

        FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->user->id,
            ]
        );

        $this->warehouse = Warehouse::create([
            'name' => 'HR246 Warehouse',
            'code' => 'HR246-'.uniqid(),
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'HR246 Widget',
            'sku' => 'HR246-'.uniqid(),
            'cost' => 25.00,
            'price' => 40.00,
            'track_inventory' => true,
        ]);

        // Fractional conversion factor: 1 Box = 1.75 base units (also exercises
        // the former int truncation of #250).
        $this->box = ProductUom::create([
            'product_id' => $this->product->id,
            'name' => 'Box',
            'abbreviation' => 'box',
            'conversion_factor' => 1.75,
            'is_base_unit' => false,
            'is_purchase_unit' => true,
            'is_sales_unit' => false,
            'price_multiplier' => 1.75,
            'is_active' => true,
        ]);

        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 0,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);
    }

    private function reason(string $type = 'increase'): StockAdjustmentReason
    {
        return StockAdjustmentReason::create([
            'name' => 'HR246 '.Str::random(4),
            'code' => 'hr246-'.Str::lower(Str::random(6)),
            'type' => $type,
            'requires_approval' => false,
            'is_active' => true,
        ]);
    }

    private function stockLevel(): float
    {
        return (float) StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('quantity');
    }

    public function test_stock_in_converts_uom_and_keeps_fractional_base_quantity(): void
    {
        $movement = app(StockAdjustmentService::class)->stockIn(
            $this->product->id,
            $this->warehouse->id,
            2, // 2 Boxes
            $this->reason('increase')->id,
            'uom stock in',
            null,
            null,
            null,
            $this->box->id,
        );

        $this->assertSame(3.5, $this->stockLevel(),
            '2 Boxes x 1.75 must land as 3.5 base units, not 3 (int truncation) or 2 (no conversion)');

        // The GL valuation must use the same fractional delta (#250).
        $link = JournalEntryLink::where('source_document_type', 'stock_adjustment')
            ->where('source_document_id', $movement->id)
            ->first();
        $this->assertNotNull($link, 'stock-in must post a journal entry');

        $mapping = app(AccountMappingService::class);
        $lines = JournalEntryLine::where('journal_entry_id', $link->journal_entry_id)->get();
        $inventory = $lines->firstWhere('account_id', $mapping->resolveId('inventory'));

        $this->assertNotNull($inventory, 'inventory line must exist');
        $this->assertSame(87.50, round((float) $inventory->debit, 2),
            '3.5 base units x 25.00 cost — the GL must not truncate the delta');
    }

    public function test_cycle_count_adjust_converts_uom_for_absolute_quantity(): void
    {
        app(StockAdjustmentService::class)->adjust(
            $this->product->id,
            $this->warehouse->id,
            1, // 1 Box set as the absolute count
            $this->reason('both')->id,
            'uom cycle count',
            $this->box->id,
        );

        $this->assertSame(1.75, $this->stockLevel(),
            'An absolute count of 1 Box must set 1.75 base units');
    }
}
