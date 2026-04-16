<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TenantTestCase;
use Modules\FinancialReporting\Services\Inventory\ReorderService;
use Modules\FinancialReporting\DTOs\ReportFilter;

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

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Reorder Report', $result['title']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    /** @test */
    public function it_includes_reorder_summary()
    {
        $filter = new ReportFilter();

        $result = $this->service->generate($filter);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('products_below_reorder_level', $result['summary']);
        $this->assertArrayHasKey('total_reorder_value', $result['summary']);
    }

    /** @test */
    public function it_handles_missing_products_table()
    {
        $filter = new ReportFilter();

        $result = $this->service->generate($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }
}
