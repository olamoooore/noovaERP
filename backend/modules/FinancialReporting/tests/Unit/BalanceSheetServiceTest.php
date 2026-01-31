<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FinancialReporting\Services\Reports\BalanceSheetService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;

class BalanceSheetServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BalanceSheetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BalanceSheetService::class);
        $this->setupTestData();
    }

    protected function setupTestData(): void
    {
        // Create asset accounts
        ChartOfAccount::create([
            'code' => '1000',
            'name' => 'Cash',
            'type' => 'asset',
            'is_active' => true,
        ]);

        ChartOfAccount::create([
            'code' => '1200',
            'name' => 'Accounts Receivable',
            'type' => 'asset',
            'is_active' => true,
        ]);

        // Create liability account
        ChartOfAccount::create([
            'code' => '2000',
            'name' => 'Accounts Payable',
            'type' => 'liability',
            'is_active' => true,
        ]);

        // Create equity account
        ChartOfAccount::create([
            'code' => '3000',
            'name' => 'Owner Equity',
            'type' => 'equity',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_generates_balance_sheet_report()
    {
        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertNotNull($report);
        $this->assertEquals('Balance Sheet', $report->title);
        $this->assertArrayHasKey('assets', $report->data);
        $this->assertArrayHasKey('liabilities', $report->data);
        $this->assertArrayHasKey('equity', $report->data);
    }

    /** @test */
    public function it_calculates_assets_correctly()
    {
        $cashAccount = ChartOfAccount::where('code', '1000')->first();
        
        $entry = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'Initial Cash',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cashAccount->id,
            'debit' => 50000,
            'credit' => 0,
        ]);

        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertEquals(50000, $report->data['assets']['total']);
    }

    /** @test */
    public function it_verifies_accounting_equation()
    {
        $cashAccount = ChartOfAccount::where('code', '1000')->first();
        $equityAccount = ChartOfAccount::where('code', '3000')->first();
        
        $entry = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'Initial Investment',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cashAccount->id,
            'debit' => 50000,
            'credit' => 0,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $equityAccount->id,
            'debit' => 0,
            'credit' => 50000,
        ]);

        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        // Assets = Liabilities + Equity
        $this->assertEquals(
            $report->data['assets']['total'],
            $report->data['liabilities']['total'] + $report->data['equity']['total']
        );
    }

    /** @test */
    public function it_filters_by_as_of_date()
    {
        $cashAccount = ChartOfAccount::where('code', '1000')->first();
        
        // Entry in January
        $entry1 = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'January Cash',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $cashAccount->id,
            'debit' => 30000,
            'credit' => 0,
        ]);

        // Entry in February (should be excluded)
        $entry2 = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-02-15'),
            'description' => 'February Cash',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $cashAccount->id,
            'debit' => 20000,
            'credit' => 0,
        ]);

        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertEquals(30000, $report->data['assets']['total']);
    }

    /** @test */
    public function it_includes_summary_metrics()
    {
        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertArrayHasKey('summary', $report->summary);
        $this->assertArrayHasKey('total_assets', $report->summary);
        $this->assertArrayHasKey('total_liabilities', $report->summary);
        $this->assertArrayHasKey('total_equity', $report->summary);
    }
}
