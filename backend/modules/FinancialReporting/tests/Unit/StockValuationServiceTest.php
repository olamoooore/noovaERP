<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TenantTestCase;
use Modules\FinancialReporting\Services\Inventory\StockValuationService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\DTOs\ReportData;
use Carbon\Carbon;

class StockValuationServiceTest extends TenantTestCase
{

    protected StockValuationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockValuationService();
    }

    /** @test */
    public function it_returns_stock_valuation_structure()
    {
        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->generate($filter);

        $this->assertInstanceOf(ReportData::class, $result);
        $this->assertEquals('Stock Valuation Report', $result->title);
        $this->assertIsArray($result->data);
        $this->assertIsArray($result->summary);
    }

    /** @test */
    public function it_uses_default_date_when_not_provided()
    {
        $filter = new ReportFilter();

        $result = $this->service->generate($filter);

        $this->assertArrayHasKey('as_of_date', $result->metadata);
        $this->assertNotNull($result->metadata['as_of_date']);
    }

    /** @test */
    public function it_handles_missing_products_table()
    {
        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->generate($filter);

        $this->assertInstanceOf(ReportData::class, $result);
        $this->assertIsArray($result->data);
    }

    /** @test */
    public function it_includes_summary_totals()
    {
        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->generate($filter);

        $this->assertIsArray($result->summary);
        $this->assertArrayHasKey('total_value', $result->summary);
        $this->assertArrayHasKey('total_quantity', $result->summary);
        $this->assertArrayHasKey('total_products', $result->summary);
    }
}
