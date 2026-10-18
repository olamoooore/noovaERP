<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Carbon\Carbon;
use Modules\FinancialReporting\DTOs\ReportData;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Inventory\StockMovementService;
use Tests\TenantTestCase;

class StockMovementServiceTest extends TenantTestCase
{
    protected StockMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockMovementService;
    }

    /** @test */
    public function it_returns_stock_movement_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->generate($filter);

        $this->assertInstanceOf(ReportData::class, $result);
        $this->assertEquals('Stock Movement Report', $result->title);
        $this->assertArrayHasKey('period', $result->metadata);
        $this->assertIsArray($result->data);
        $this->assertIsArray($result->summary);
    }

    /** @test */
    public function it_uses_default_dates_when_not_provided()
    {
        $filter = new ReportFilter;

        $result = $this->service->generate($filter);

        $this->assertArrayHasKey('period', $result->metadata);
        $this->assertArrayHasKey('start_date', $result->metadata['period']);
        $this->assertArrayHasKey('end_date', $result->metadata['period']);
    }

    /** @test */
    public function it_includes_movement_summary()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->generate($filter);

        $this->assertIsArray($result->summary);
        $this->assertArrayHasKey('movement_count', $result->summary);
        $this->assertArrayHasKey('total_in', $result->summary);
        $this->assertArrayHasKey('total_out', $result->summary);
    }

    /** @test */
    public function it_summarises_with_signed_direction_and_ignores_pagination()
    {
        // withoutEvents: product audit logs need a tenant user that this unit
        // test does not provision.
        $product = \App\Models\Product::withoutEvents(fn () => \App\Models\Product::factory()->create([
            'name' => 'Movement Summary Product',
            'sku' => 'MSP-'.uniqid(),
            'track_inventory' => true,
            'cost' => 1,
        ]));

        $user = \App\Models\User::factory()->create();

        // Before the period: +4 opening.
        \Illuminate\Support\Facades\DB::table('stock_movements')->insert([
            'product_id' => $product->id, 'type' => 'in', 'quantity' => 4,
            'old_quantity' => 0, 'balance_after' => 4, 'created_by' => $user->id,
            'created_at' => '2026-01-05 10:00:00', 'updated_at' => '2026-01-05 10:00:00',
        ]);
        // In period: +5 in, then 3 out (quantity stored positive; type carries direction).
        \Illuminate\Support\Facades\DB::table('stock_movements')->insert([
            'product_id' => $product->id, 'type' => 'in', 'quantity' => 5,
            'old_quantity' => 4, 'balance_after' => 9, 'created_by' => $user->id,
            'created_at' => '2026-01-20 10:00:00', 'updated_at' => '2026-01-20 10:00:00',
        ]);
        \Illuminate\Support\Facades\DB::table('stock_movements')->insert([
            'product_id' => $product->id, 'type' => 'out', 'quantity' => 3,
            'old_quantity' => 9, 'balance_after' => 6, 'created_by' => $user->id,
            'created_at' => '2026-01-21 10:00:00', 'updated_at' => '2026-01-21 10:00:00',
        ]);

        $filter = new ReportFilter(
            startDate: Carbon::parse('2026-01-15'),
            endDate: Carbon::parse('2026-01-31'),
            perPage: 1,
        );

        $summary = $this->service->generate($filter)->summary;

        $this->assertEquals(4.0, (float) $summary['opening_balance']);
        $this->assertEquals(5.0, (float) $summary['total_in'], 'In-movements must count once, regardless of page size.');
        $this->assertEquals(3.0, (float) $summary['total_out'], 'Removals are stored positive with type=out — they must not count as additions.');
        $this->assertEquals(2.0, (float) $summary['net_change']);
        $this->assertEquals(6.0, (float) $summary['closing_balance'], 'Closing = opening + net change.');
        $this->assertEquals(2, (int) $summary['movement_count']);
    }
}
