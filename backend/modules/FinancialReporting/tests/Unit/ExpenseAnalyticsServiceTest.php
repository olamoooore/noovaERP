<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FinancialReporting\Services\Analytics\ExpenseAnalyticsService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Carbon\Carbon;

class ExpenseAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ExpenseAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExpenseAnalyticsService();
    }

    /** @test */
    public function it_returns_expenses_by_category_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getExpensesByCategory($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Expenses by Category', $result['title']);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    /** @test */
    public function it_returns_expenses_by_vendor_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getExpensesByVendor($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Expenses by Vendor', $result['title']);
        $this->assertArrayHasKey('data', $result);
    }

    /** @test */
    public function it_returns_expenses_by_department_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getExpensesByDepartment($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Expenses by Department', $result['title']);
        $this->assertArrayHasKey('data', $result);
    }

    /** @test */
    public function it_returns_expense_trends_structure()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $result = $this->service->getExpenseTrends($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Expense Trends', $result['title']);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('data', $result);
    }

    /** @test */
    public function it_uses_default_dates_when_not_provided()
    {
        $filter = new ReportFilter();

        $result = $this->service->getExpensesByCategory($filter);

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

        $result = $this->service->getExpensesByCategory($filter);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }
}
