<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Inventory\StockValuationService;
use Tests\TenantTestCase;

class VAL001CostingMethodSelectionTest extends TenantTestCase
{
    public function test_weighted_average_valuation_uses_batch_weighted_cost(): void
    {
        $productId = $this->seedInventoryFixture();
        $this->setValuationMethod('weighted_average');

        $report = app(StockValuationService::class)->generate(new ReportFilter(endDate: now()));
        $row = collect($report->data)->firstWhere('id', $productId);

        $this->assertNotNull($row);
        $this->assertSame('weighted_average', $report->metadata['valuation_method']);
        $this->assertEquals(15.00, (float) $row['cost']);
        $this->assertEquals(150.00, (float) $row['total_value']);
    }

    public function test_fifo_valuation_uses_oldest_batch_cost(): void
    {
        $productId = $this->seedInventoryFixture();
        $this->setValuationMethod('fifo');

        $report = app(StockValuationService::class)->generate(new ReportFilter(endDate: now()));
        $row = collect($report->data)->firstWhere('id', $productId);

        $this->assertNotNull($row);
        $this->assertSame('fifo', $report->metadata['valuation_method']);
        $this->assertEquals(10.00, (float) $row['cost']);
        $this->assertEquals(100.00, (float) $row['total_value']);
    }

    public function test_lifo_valuation_uses_latest_batch_cost(): void
    {
        $productId = $this->seedInventoryFixture();
        $this->setValuationMethod('lifo');

        $report = app(StockValuationService::class)->generate(new ReportFilter(endDate: now()));
        $row = collect($report->data)->firstWhere('id', $productId);

        $this->assertNotNull($row);
        $this->assertSame('lifo', $report->metadata['valuation_method']);
        $this->assertEquals(20.00, (float) $row['cost']);
        $this->assertEquals(200.00, (float) $row['total_value']);
    }

    private function seedInventoryFixture(): int
    {
        $productId = (int) DB::table('products')->insertGetId([
            'name' => 'VAL001 Product',
            'sku' => 'VAL001-' . Str::upper(Str::random(8)),
            'cost' => 12.00,
            'price' => 20.00,
            'track_inventory' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'name' => 'VAL001 Warehouse',
            'code' => 'VAL001-WH',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_levels')->insert([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
            'max_quantity' => null,
            'bin_location' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_batches')->insert([
            [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'batch_number' => 'VAL001-A',
                'quantity' => 5,
                'quantity_reserved' => 0,
                'cost' => 10.00,
                'status' => 'available',
                'manufacturing_date' => now()->subDays(10)->toDateString(),
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ],
            [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'batch_number' => 'VAL001-B',
                'quantity' => 5,
                'quantity_reserved' => 0,
                'cost' => 20.00,
                'status' => 'available',
                'manufacturing_date' => now()->subDays(1)->toDateString(),
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
        ]);

        return $productId;
    }

    private function setValuationMethod(string $method): void
    {
        DB::table('accounting_policies')->updateOrInsert(
            ['id' => 1],
            [
                'depreciation_method' => 'straight_line',
                'capitalization_threshold' => 1000.00,
                'inventory_valuation_method' => $method,
                'revenue_recognition_policy' => 'accrual',
                'expense_recognition_policy' => 'matching',
                'bad_debt_policy' => 'allowance_method',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
