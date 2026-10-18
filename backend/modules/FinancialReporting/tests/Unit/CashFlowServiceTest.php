<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Carbon\Carbon;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Reports\CashFlowService;
use Tests\TenantTestCase;

class CashFlowServiceTest extends TenantTestCase
{
    protected CashFlowService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CashFlowService::class);
    }

    /** @test */
    public function it_generates_cash_flow_report()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertNotNull($report);
        $this->assertStringContainsString('Cash Flow Statement', $report->title);
        $this->assertArrayHasKey('operating_activities', $report->data);
        $this->assertArrayHasKey('investing_activities', $report->data);
        $this->assertArrayHasKey('financing_activities', $report->data);
    }

    /** @test */
    public function it_throws_exception_when_dates_missing()
    {
        $this->expectException(\InvalidArgumentException::class);

        $filter = new ReportFilter;
        $this->service->generate($filter);
    }

    /** @test */
    public function it_calculates_net_cash_flow()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertArrayHasKey('net_cash_flow', $report->data);
        $this->assertIsNumeric($report->data['net_cash_flow']);
    }

    /** @test */
    public function it_includes_period_metadata()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertArrayHasKey('period', $report->metadata);
        $this->assertEquals('2024-01-01', $report->metadata['period']['start']);
        $this->assertEquals('2024-01-31', $report->metadata['period']['end']);
    }

    /** @test */
    public function it_supports_direct_method()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31'),
            interval: 'direct',
        );

        $report = $this->service->generate($filter);

        $this->assertNotNull($report);
        $this->assertStringContainsString('Cash Flow Statement', $report->title);
        $this->assertArrayHasKey('operating_activities', $report->data);
    }

    /** @test */
    public function it_supports_indirect_method()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31'),
            interval: 'indirect',
        );

        $report = $this->service->generate($filter);

        $this->assertNotNull($report);
        $this->assertStringContainsString('Cash Flow Statement', $report->title);
        $this->assertArrayHasKey('operating_activities', $report->data);
    }
}
