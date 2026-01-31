<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FinancialReporting\Services\Inventory\InventoryTurnoverService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Carbon\Carbon;

class InventoryTurnoverServiceTest extends TestCase
{
    use RefreshDatabase;

    protected InventoryTurnoverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InventoryTurnoverService();
    }

    /** @test */
    public function it_returns_turnover_report_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->generate($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Inventory Turnover Report', $result['title']);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    /** @test */
    public function it_includes_turnover_metrics()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->generate($filter);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('average_turnover_ratio', $result['summary']);
        $this->assertArrayHasKey('average_days_in_inventory', $result['summary']);
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
}
