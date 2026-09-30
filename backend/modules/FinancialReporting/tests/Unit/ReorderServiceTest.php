<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Modules\FinancialReporting\DTOs\ReportData;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Inventory\ReorderService;
use Tests\TenantTestCase;

class ReorderServiceTest extends TenantTestCase
{
    protected ReorderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReorderService;
    }

    /** @test */
    public function it_returns_reorder_report_structure()
    {
        $filter = new ReportFilter;

        $result = $this->service->generate($filter);

        $this->assertInstanceOf(ReportData::class, $result);
        $this->assertEquals('Reorder Report', $result->title);
        $this->assertIsArray($result->data);
        $this->assertIsArray($result->summary);
    }

    /** @test */
    public function it_includes_reorder_summary()
    {
        $filter = new ReportFilter;

        $result = $this->service->generate($filter);

        $this->assertIsArray($result->summary);
        $this->assertArrayHasKey('total_products_to_reorder', $result->summary);
        $this->assertArrayHasKey('total_suggested_order_value', $result->summary);
    }

    /** @test */
    public function it_handles_missing_products_table()
    {
        $filter = new ReportFilter;

        $result = $this->service->generate($filter);

        $this->assertInstanceOf(ReportData::class, $result);
        $this->assertIsArray($result->data);
    }

    /** @test */
    public function it_uses_the_default_threshold_when_no_reorder_level_is_set()
    {
        $sku = 'RDT-'.uniqid();
        // reorder_level is NOT NULL in the schema; 0 means "not configured".
        $needsReorder = \App\Models\Product::withoutEvents(fn () => \App\Models\Product::factory()->create([
            'name' => 'Zero Stock Product',
            'sku' => $sku,
            'track_inventory' => true,
            'reorder_level' => 0,
            'is_active' => true,
        ]));
        $wellStocked = \App\Models\Product::withoutEvents(fn () => \App\Models\Product::factory()->create([
            'name' => 'Well Stocked Product',
            'sku' => 'RDTW-'.uniqid(),
            'track_inventory' => true,
            'reorder_level' => 0,
            'stock_quantity' => 25,
            'is_active' => true,
        ]));
        $warehouse = \App\Models\Warehouse::create([
            'name' => 'Reorder Test Warehouse',
            'code' => 'ROS-'.uniqid(),
            'is_active' => true,
        ]);

        \Illuminate\Support\Facades\DB::table('stock_levels')->insert([
            'product_id' => $wellStocked->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 25,
            'min_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->service->generate(new ReportFilter);
        $rows = collect($result->data);
        $row = $rows->firstWhere('id', $needsReorder->id);

        $this->assertNotNull($row, 'A zero-stock product with no reorder level must appear in the reorder report.');
        $this->assertEquals(10.0, (float) $row->effective_threshold, 'The platform default threshold (10) applies when none is configured.');
        $this->assertEquals(10.0, (float) $row->shortage);
        $this->assertTrue((bool) $row->is_critical, 'Out-of-stock items are critical.');

        $this->assertNull($rows->firstWhere('id', $wellStocked->id), 'Products above the threshold are not reorder items.');
    }
}
