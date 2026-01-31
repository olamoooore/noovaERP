<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FinancialReporting\Services\Reports\ProfitLossService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;

class ProfitLossServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ProfitLossService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProfitLossService::class);
        $this->setupTestData();
    }

    protected function setupTestData(): void
    {
        // Create revenue account
        ChartOfAccount::create([
            'code' => '4000',
            'name' => 'Sales Revenue',
            'type' => 'revenue',
            'is_active' => true,
        ]);

        // Create COGS account
        ChartOfAccount::create([
            'code' => '5000',
            'name' => 'Cost of Goods Sold',
            'type' => 'expense',
            'is_active' => true,
        ]);

        // Create operating expense account
        ChartOfAccount::create([
            'code' => '5100',
            'name' => 'Operating Expenses',
            'type' => 'expense',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_generates_profit_loss_report()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertNotNull($report);
        $this->assertEquals('Profit & Loss Statement', $report->title);
        $this->assertArrayHasKey('revenue', $report->data);
        $this->assertArrayHasKey('gross_profit', $report->data);
        $this->assertArrayHasKey('net_profit', $report->data);
    }

    /** @test */
    public function it_throws_exception_when_dates_missing()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        $filter = new ReportFilter();
        $this->service->generate($filter);
    }

    /** @test */
    public function it_calculates_revenue_correctly()
    {
        $revenueAccount = ChartOfAccount::where('code', '4000')->first();
        
        $entry = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'Test Revenue',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'debit' => 0,
            'credit' => 10000,
        ]);

        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertEquals(10000, $report->data['revenue']['total']);
    }

    /** @test */
    public function it_calculates_gross_profit_correctly()
    {
        $revenueAccount = ChartOfAccount::where('code', '4000')->first();
        $cogsAccount = ChartOfAccount::where('code', '5000')->first();
        
        $entry = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'Test Transaction',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'debit' => 0,
            'credit' => 10000,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cogsAccount->id,
            'debit' => 6000,
            'credit' => 0,
        ]);

        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertEquals(10000, $report->data['revenue']['total']);
        $this->assertEquals(6000, $report->data['cost_of_goods_sold']['total']);
        $this->assertEquals(4000, $report->data['gross_profit']['amount']);
        $this->assertEquals(40, $report->data['gross_profit']['margin_percent']);
    }

    /** @test */
    public function it_includes_metadata_in_report()
    {
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertArrayHasKey('metadata', $report->metadata);
        $this->assertArrayHasKey('period', $report->metadata);
        $this->assertEquals('2024-01-01', $report->metadata['period']['start']);
        $this->assertEquals('2024-01-31', $report->metadata['period']['end']);
    }

    /** @test */
    public function it_filters_by_date_range()
    {
        $revenueAccount = ChartOfAccount::where('code', '4000')->first();
        
        // Entry in January
        $entry1 = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'January Revenue',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $revenueAccount->id,
            'debit' => 0,
            'credit' => 5000,
        ]);

        // Entry in February (should be excluded)
        $entry2 = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-02-15'),
            'description' => 'February Revenue',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $revenueAccount->id,
            'debit' => 0,
            'credit' => 3000,
        ]);

        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertEquals(5000, $report->data['revenue']['total']);
    }

    /** @test */
    public function it_excludes_draft_entries()
    {
        $revenueAccount = ChartOfAccount::where('code', '4000')->first();
        
        $entry = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'Draft Revenue',
            'status' => 'draft',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'debit' => 0,
            'credit' => 10000,
        ]);

        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertEquals(0, $report->data['revenue']['total']);
    }
}
