<?php

namespace Modules\FinancialReporting\Tests\Unit;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Carbon\Carbon;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Reports\BalanceSheetService;
use Tests\TenantTestCase;

class BalanceSheetServiceTest extends TenantTestCase
{
    protected BalanceSheetService $service;

    protected User $testUser;

    protected function setUp(): void
    {
        parent::setUp();
        // Force journal-entry queries (not summary table) and legacy code-range filtering
        config(['financialreporting.reports.use_summary_after_days' => 999999]);
        config(['app.report_classification' => 'legacy']);
        $this->service = app(BalanceSheetService::class);
        $this->testUser = User::factory()->create();
        $this->setupTestData();
    }

    protected function setupTestData(): void
    {
        // Use firstOrCreate to avoid collision with migration-seeded accounts
        ChartOfAccount::firstOrCreate(
            ['code' => '1000'],
            ['name' => 'Cash', 'type' => 'asset', 'is_active' => true]
        );

        ChartOfAccount::firstOrCreate(
            ['code' => '1200'],
            ['name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]
        );

        ChartOfAccount::firstOrCreate(
            ['code' => '2000'],
            ['name' => 'Accounts Payable', 'type' => 'liability', 'is_active' => true]
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
    public function it_generates_balance_sheet_report()
    {
        $filter = new ReportFilter(
            asOfDate: Carbon::parse('2024-01-31')
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

        $entry = $this->createJournalEntry([
            'description' => 'Initial Cash',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cashAccount->id,
            'debit' => 50000,
            'credit' => 0,
        ]);

        $filter = new ReportFilter(
            asOfDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertEquals(50000, $report->data['assets']['total']);
    }

    /** @test */
    public function it_verifies_accounting_equation()
    {
        $cashAccount = ChartOfAccount::where('code', '1000')->first();
        $equityAccount = ChartOfAccount::where('code', '3000')->first();

        $entry = $this->createJournalEntry([
            'description' => 'Initial Investment',
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
            asOfDate: Carbon::parse('2024-01-31')
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
        $entry1 = $this->createJournalEntry([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'January Cash',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $cashAccount->id,
            'debit' => 30000,
            'credit' => 0,
        ]);

        // Entry in February (should be excluded)
        $entry2 = $this->createJournalEntry([
            'entry_date' => Carbon::parse('2024-02-15'),
            'description' => 'February Cash',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $cashAccount->id,
            'debit' => 20000,
            'credit' => 0,
        ]);

        $filter = new ReportFilter(
            asOfDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertEquals(30000, $report->data['assets']['total']);
    }

    /** @test */
    public function it_includes_summary_metrics()
    {
        $filter = new ReportFilter(
            asOfDate: Carbon::parse('2024-01-31')
        );

        $report = $this->service->generate($filter);

        $this->assertArrayHasKey('total_assets', $report->summary);
        $this->assertArrayHasKey('total_liabilities', $report->summary);
        $this->assertArrayHasKey('total_equity', $report->summary);
    }

    /** @test */
    public function it_returns_actual_recoded_equity_accounts_from_the_coa()
    {
        $cashAccount = ChartOfAccount::where('code', '1000')->first();
        $equityAccount = ChartOfAccount::where('code', '3000')->first();
        $equityAccount->update(['code' => '399900', 'name' => 'Members Contributions']);

        $entry = $this->createJournalEntry(['description' => 'Recoded equity account']);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cashAccount->id,
            'debit' => 12500,
            'credit' => 0,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $equityAccount->id,
            'debit' => 0,
            'credit' => 12500,
        ]);

        $report = $this->service->generate(new ReportFilter(asOfDate: Carbon::parse('2024-01-31')));

        $this->assertSame('399900', $report->data['equity']['accounts'][0]['code']);
        $this->assertSame('Members Contributions', $report->data['equity']['accounts'][0]['name']);
        $this->assertEquals(12500, $report->data['equity']['accounts'][0]['balance']);
        $this->assertEquals(12500, $report->data['equity']['total']);
    }
}
