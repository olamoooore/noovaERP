<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FinancialReporting\Services\Analytics\CustomerAnalyticsService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Carbon\Carbon;

class CustomerAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CustomerAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CustomerAnalyticsService();
    }

    /** @test */
    public function it_returns_customer_lifetime_value_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getCustomerLifetimeValue($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Customer Lifetime Value', $result['title']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    /** @test */
    public function it_returns_purchase_patterns_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getPurchasePatterns($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Customer Purchase Patterns', $result['title']);
        $this->assertArrayHasKey('data', $result);
    }

    /** @test */
    public function it_returns_customer_segmentation_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getCustomerSegmentation($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Customer Segmentation (RFM)', $result['title']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('segments', $result);
    }

    /** @test */
    public function it_calculates_retention_rate()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getRetentionRate($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('retention_rate', $result);
        $this->assertIsNumeric($result['retention_rate']);
    }

    /** @test */
    public function it_uses_default_dates_when_not_provided()
    {
        $filter = new ReportFilter();

        $result = $this->service->getCustomerLifetimeValue($filter);

        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('start_date', $result['period']);
        $this->assertArrayHasKey('end_date', $result['period']);
    }

    /** @test */
    public function it_handles_missing_tables_gracefully()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getCustomerLifetimeValue($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }
}
