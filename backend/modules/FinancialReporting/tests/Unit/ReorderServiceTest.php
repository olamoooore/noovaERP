<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TenantTestCase;
use Modules\FinancialReporting\Services\Inventory\ReorderService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\DTOs\ReportData;

class ReorderServiceTest extends TenantTestCase
{

    protected ReorderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReorderService();
    }

    /** @test */
    public function it_returns_reorder_report_structure()
    {
        $filter = new ReportFilter();

        $result = $this->service->generate($filter);

        $this->assertInstanceOf(ReportData::class, $result);
        $this->assertEquals('Reorder Report', $result->title);
        $this->assertIsArray($result->data);
        $this->assertIsArray($result->summary);
    }

    /** @test */
    public function it_includes_reorder_summary()
    {
        $filter = new ReportFilter();

        $result = $this->service->generate($filter);

        $this->assertIsArray($result->summary);
        $this->assertArrayHasKey('total_products_to_reorder', $result->summary);
        $this->assertArrayHasKey('total_suggested_order_value', $result->summary);
    }

    /** @test */
    public function it_handles_missing_products_table()
    {
        $filter = new ReportFilter();

        $result = $this->service->generate($filter);

        $this->assertInstanceOf(ReportData::class, $result);
        $this->assertIsArray($result->data);
    }
}
