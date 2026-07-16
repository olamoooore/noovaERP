<?php

namespace Modules\FinancialReporting\Tests\Unit;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Carbon\Carbon;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Reports\TrialBalanceService;
use Tests\TenantTestCase;

class TrialBalanceServiceTest extends TenantTestCase
{
    protected TrialBalanceService $service;

    protected User $testUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrialBalanceService::class);
        $this->testUser = User::factory()->create();
        $this->setupTestData();
    }

    protected function setupTestData(): void
    {
        ChartOfAccount::firstOrCreate(
            ['code' => '1000'],
            ['name' => 'Cash', 'type' => 'asset', 'is_active' => true]
        );

        ChartOfAccount::firstOrCreate(
            ['code' => '3000'],
            ['name' => 'Owner Equity', 'type' => 'equity', 'is_active' => true]
        );
    }

    private function createJournalEntry(array $attrs = []): JournalEntry
    {
        return JournalEntry::create(array_merge([
            'entry_number' => 'JE-TEST-'.uniqid(),
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'Test Entry',
            'status' => 'posted',
            'created_by' => $this->testUser->id,
        ], $attrs));
    }

    /** @test */
    public function it_generates_trial_balance_report()
    {
        $filter = new ReportFilter(
            asOfDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertNotNull($report);
        $this->assertEquals('Trial Balance', $report->title);
        // Data is a flat array of account rows
        $this->assertIsArray($report->data);
        // Summary contains totals
        $this->assertArrayHasKey('total_debits', $report->summary);
        $this->assertArrayHasKey('total_credits', $report->summary);
    }

    /** @test */
    public function it_verifies_debits_equal_credits()
    {
        $cashAccount = ChartOfAccount::where('code', '1000')->first();
        $equityAccount = ChartOfAccount::where('code', '3000')->first();

        $entry = $this->createJournalEntry([
            'description' => 'Test Entry',
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
            asOfDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertEquals(
            $report->summary['total_debits'],
            $report->summary['total_credits']
        );
    }

    /** @test */
    public function it_filters_by_account_type()
    {
        $filter = new ReportFilter(
            asOfDate: Carbon::parse('2024-01-31'),
            accountType: 'asset'
        );

        $report = $this->service->generate($filter);

        $this->assertNotNull($report);
        $this->assertIsArray($report->data);
    }

    /** @test */
    public function it_excludes_zero_balance_accounts()
    {
        $cashAccount = ChartOfAccount::where('code', '1000')->first();
        $equityAccount = ChartOfAccount::where('code', '3000')->first();

        $zeroBalanceAccount = ChartOfAccount::firstOrCreate(
            ['code' => '9991'],
            ['name' => 'Zero Balance Account', 'type' => 'asset', 'is_active' => true]
        );

        $entry = $this->createJournalEntry([
            'description' => 'Non-zero balances only',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cashAccount->id,
            'debit' => 5000,
            'credit' => 0,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $equityAccount->id,
            'debit' => 0,
            'credit' => 5000,
        ]);

        $filter = new ReportFilter(
            asOfDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertNotEmpty($report->data);
        $this->assertNotContains($zeroBalanceAccount->code, array_column($report->data, 'code'));

        // Data is a flat array of account rows
        foreach ($report->data as $account) {
            $this->assertNotEquals(0, $account['debit'] + $account['credit']);
        }
    }
}
