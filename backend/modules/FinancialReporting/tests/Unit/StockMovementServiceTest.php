<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TenantTestCase;
use Modules\FinancialReporting\Services\Inventory\StockMovementService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\DTOs\ReportData;
use Carbon\Carbon;

class StockMovementServiceTest extends TenantTestCase
{

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

        $this->assertInstanceOf(ReportData::class, $result);
        $this->assertEquals('Stock Movement Report', $result->title);
        $this->assertArrayHasKey('period', $result->metadata);
        $this->assertIsArray($result->data);
        $this->assertIsArray($result->summary);
    }

    /** @test */
    public function it_uses_default_dates_when_not_provided()
    {
        $filter = new ReportFilter();

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
}
