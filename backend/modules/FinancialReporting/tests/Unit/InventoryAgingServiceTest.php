<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TenantTestCase;
use Modules\FinancialReporting\Services\Inventory\InventoryAgingService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Carbon\Carbon;

class InventoryAgingServiceTest extends TenantTestCase
{

    protected InventoryAgingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InventoryAgingService();
    }

    /** @test */
    public function it_returns_aging_report_structure()
    {
        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->generate($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Inventory Aging Report', $result['title']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    /** @test */
    public function it_includes_age_buckets()
    {
        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->generate($filter);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('age_buckets', $result['summary']);
    }

    /** @test */
    public function it_uses_default_date_when_not_provided()
    {
        $filter = new ReportFilter();

        $result = $this->service->generate($filter);

        $this->assertArrayHasKey('as_of_date', $result);
        $this->assertNotNull($result['as_of_date']);
    }
}
