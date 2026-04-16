<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TenantTestCase;
use Modules\FinancialReporting\Services\Analytics\SalesAnalyticsService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Carbon\Carbon;

class SalesAnalyticsServiceTest extends TenantTestCase
{

    protected SalesAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SalesAnalyticsService();
    }

    /** @test */
    public function it_returns_empty_data_when_tables_missing()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getSalesByProduct($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertEmpty($result['data']);
    }

    /** @test */
    public function it_returns_sales_by_customer_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getSalesByCustomer($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Sales by Customer', $result['title']);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    /** @test */
    public function it_returns_sales_trends_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getSalesTrends($filter, 'day');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Sales Trends', $result['title']);
        $this->assertArrayHasKey('interval', $result);
        $this->assertEquals('day', $result['interval']);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('data', $result);
    }

    /** @test */
    public function it_supports_different_trend_intervals()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-12-31')
        );

        $intervals = ['day', 'week', 'month', 'year'];

        foreach ($intervals as $interval) {
            $result = $this->service->getSalesTrends($filter, $interval);
            $this->assertEquals($interval, $result['interval']);
        }
    }

    /** @test */
    public function it_returns_sales_by_category_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getSalesByCategory($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Sales by Category', $result['title']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    /** @test */
    public function it_returns_top_selling_products()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getTopSellingProducts($filter, 5);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertStringContainsString('Top', $result['title']);
    }

    /** @test */
    public function it_returns_profit_margins_analysis()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getProfitMargins($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Profit Margin Analysis', $result['title']);
    }

    /** @test */
    public function it_uses_default_dates_when_not_provided()
    {
        $filter = new ReportFilter();

        $result = $this->service->getSalesByProduct($filter);

        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('start_date', $result['period']);
        $this->assertArrayHasKey('end_date', $result['period']);
    }
}
