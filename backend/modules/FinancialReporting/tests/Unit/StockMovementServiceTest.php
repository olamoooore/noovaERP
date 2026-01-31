<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FinancialReporting\Services\Inventory\StockMovementService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Carbon\Carbon;

class StockMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StockMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockMovementService();
    }

    /** @test */
    public function it_returns_stock_movement_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->generate($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Stock Movement Report', $result['title']);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    /** @test */
    public function it_uses_default_dates_when_not_provided()
    {
        $filter = new ReportFilter();

        $result = $this->service->generate($filter);

        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('start_date', $result['period']);
        $this->assertArrayHasKey('end_date', $result['period']);
    }

    /** @test */
    public function it_includes_movement_summary()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->generate($filter);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('total_movements', $result['summary']);
        $this->assertArrayHasKey('total_in', $result['summary']);
        $this->assertArrayHasKey('total_out', $result['summary']);
    }
}
