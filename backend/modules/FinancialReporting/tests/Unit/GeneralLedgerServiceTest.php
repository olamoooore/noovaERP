<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TenantTestCase;
use Modules\FinancialReporting\Services\Reports\GeneralLedgerService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;

class GeneralLedgerServiceTest extends TenantTestCase
{

    protected GeneralLedgerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(GeneralLedgerService::class);
        $this->setupTestData();
    }

    protected function setupTestData(): void
    {
        ChartOfAccount::create([
            'code' => '1000',
            'name' => 'Cash',
            'type' => 'asset',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_generates_general_ledger_report()
    {
        $account = ChartOfAccount::where('code', '1000')->first();
        
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31'),
            accountId: $account->id
        );

        $report = $this->service->generate($filter);

        $this->assertNotNull($report);
        $this->assertEquals('General Ledger', $report->title);
        $this->assertArrayHasKey('transactions', $report->data);
    }

    /** @test */
    public function it_requires_account_id()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31')
        );

        $this->service->generate($filter);
    }

    /** @test */
    public function it_calculates_running_balance()
    {
        $account = ChartOfAccount::where('code', '1000')->first();
        
        $entry1 = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-10'),
            'description' => 'First Entry',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $account->id,
            'debit' => 1000,
            'credit' => 0,
        ]);

        $entry2 = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'Second Entry',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $account->id,
            'debit' => 500,
            'credit' => 0,
        ]);

        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31'),
            accountId: $account->id
        );

        $report = $this->service->generate($filter);

        $this->assertArrayHasKey('transactions', $report->data);
        $this->assertCount(2, $report->data['transactions']);
        
        // Check running balance
        $this->assertEquals(1000, $report->data['transactions'][0]['balance']);
        $this->assertEquals(1500, $report->data['transactions'][1]['balance']);
    }

    /** @test */
    public function it_filters_by_date_range()
    {
        $account = ChartOfAccount::where('code', '1000')->first();
        
        $entry1 = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'January Entry',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $account->id,
            'debit' => 1000,
            'credit' => 0,
        ]);

        $entry2 = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-02-15'),
            'description' => 'February Entry',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $account->id,
            'debit' => 500,
            'credit' => 0,
        ]);

        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31'),
            accountId: $account->id
        );

        $report = $this->service->generate($filter);

        $this->assertCount(1, $report->data['transactions']);
        $this->assertEquals('January Entry', $report->data['transactions'][0]['description']);
    }

    /** @test */
    public function it_includes_opening_balance()
    {
        $account = ChartOfAccount::where('code', '1000')->first();
        
        // Entry before the filter period
        $entry1 = JournalEntry::create([
            'entry_date' => Carbon::parse('2023-12-15'),
            'description' => 'Previous Period',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $account->id,
            'debit' => 5000,
            'credit' => 0,
        ]);

        $filter = new ReportFilter(
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-01-31'),
            accountId: $account->id
        );

        $report = $this->service->generate($filter);

        $this->assertArrayHasKey('opening_balance', $report->data);
        $this->assertEquals(5000, $report->data['opening_balance']);
    }
}
