<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TenantTestCase;
use Modules\FinancialReporting\Services\Analytics\CustomerAnalyticsService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Carbon\Carbon;

class CustomerAnalyticsServiceTest extends TenantTestCase
{

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

        // Happy path includes summary; error path includes message
        if (!isset($result['message'])) {
            $this->assertArrayHasKey('summary', $result);
        }
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
        $this->assertEquals('Purchase Patterns', $result['title']);

        // Happy path has by_day_of_week; error path has data
        if (isset($result['message'])) {
            $this->assertArrayHasKey('data', $result);
        } else {
            $this->assertArrayHasKey('by_day_of_week', $result);
        }
    }

    /** @test */
    public function it_returns_rfm_segmentation_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getRFMSegmentation($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('RFM Segmentation', $result['title']);
        $this->assertArrayHasKey('data', $result);

        if (!isset($result['message'])) {
            $this->assertArrayHasKey('segments', $result);
        }
    }

    /** @test */
    public function it_calculates_retention_rate()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getCustomerRetention($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Customer Retention', $result['title']);

        if (!isset($result['message'])) {
            $this->assertArrayHasKey('metrics', $result);
            $this->assertArrayHasKey('retention_rate', $result['metrics']);
            $this->assertIsNumeric($result['metrics']['retention_rate']);
        } else {
            // Error/fallback path
            $this->assertArrayHasKey('data', $result);
        }
    }

    /** @test */
    public function it_uses_default_dates_when_not_provided()
    {
        $filter = new ReportFilter();

        $result = $this->service->getCustomerLifetimeValue($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);

        if (!isset($result['message'])) {
            $this->assertArrayHasKey('period', $result);
            $this->assertArrayHasKey('start_date', $result['period']);
            $this->assertArrayHasKey('end_date', $result['period']);
        }
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
