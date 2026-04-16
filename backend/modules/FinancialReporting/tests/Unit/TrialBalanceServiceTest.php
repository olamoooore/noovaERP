<?php

namespace Modules\FinancialReporting\Tests\Unit;

use Tests\TenantTestCase;
use Modules\FinancialReporting\Services\Reports\TrialBalanceService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;

class TrialBalanceServiceTest extends TenantTestCase
{

    protected TrialBalanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrialBalanceService::class);
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

        ChartOfAccount::create([
            'code' => '3000',
            'name' => 'Owner Equity',
            'type' => 'equity',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_generates_trial_balance_report()
    {
        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertNotNull($report);
        $this->assertEquals('Trial Balance', $report->title);
        $this->assertArrayHasKey('accounts', $report->data);
        $this->assertArrayHasKey('totals', $report->data);
    }

    /** @test */
    public function it_verifies_debits_equal_credits()
    {
        $cashAccount = ChartOfAccount::where('code', '1000')->first();
        $equityAccount = ChartOfAccount::where('code', '3000')->first();
        
        $entry = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'Test Entry',
            'status' => 'posted',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cashAccount->id,
            'debit' => 10000,
            'credit' => 0,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $equityAccount->id,
            'debit' => 0,
            'credit' => 10000,
        ]);

        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertEquals(
            $report->data['totals']['total_debits'],
            $report->data['totals']['total_credits']
        );
    }

    /** @test */
    public function it_filters_by_account_type()
    {
        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31'),
            accountType: 'asset'
        );

        $report = $this->service->generate($filter);

        $this->assertNotNull($report);
        $this->assertArrayHasKey('accounts', $report->data);
    }

    /** @test */
    public function it_excludes_zero_balance_accounts()
    {
        $filter = new ReportFilter(
            endDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        foreach ($report->data['accounts'] as $account) {
            $this->assertNotEquals(0, $account['debit'] + $account['credit']);
        }
    }
}
