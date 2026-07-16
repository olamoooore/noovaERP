<?php

namespace Modules\FinancialReporting\Tests\Unit;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Carbon\Carbon;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Reports\GeneralLedgerService;
use Tests\TenantTestCase;

class GeneralLedgerServiceTest extends TenantTestCase
{
    protected GeneralLedgerService $service;

    protected User $testUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(GeneralLedgerService::class);
        $this->testUser = User::factory()->create();
        $this->setupTestData();
    }

    protected function setupTestData(): void
    {
        ChartOfAccount::firstOrCreate(
            ['code' => '1000'],
            ['name' => 'Cash', 'type' => 'asset', 'is_active' => true]
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
        // Data is a flat array of transactions
        $this->assertIsArray($report->data);
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

        $entry1 = $this->createJournalEntry([
            'entry_date' => Carbon::parse('2024-01-10'),
            'description' => 'First Entry',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $account->id,
            'debit' => 1000,
            'credit' => 0,
        ]);

        $entry2 = $this->createJournalEntry([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'Second Entry',
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

        // Data is a flat array of transaction rows
        $this->assertIsArray($report->data);
        $this->assertCount(2, $report->data);

        // Check running balance
        $this->assertEquals(1000, $report->data[0]['balance']);
        $this->assertEquals(1500, $report->data[1]['balance']);
    }

    /** @test */
    public function it_filters_by_date_range()
    {
        $account = ChartOfAccount::where('code', '1000')->first();

        $entry1 = $this->createJournalEntry([
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'January Entry',
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $account->id,
            'debit' => 1000,
            'credit' => 0,
        ]);

        $entry2 = $this->createJournalEntry([
            'entry_date' => Carbon::parse('2024-02-15'),
            'description' => 'February Entry',
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

        $this->assertCount(1, $report->data);
        $this->assertEquals('January Entry', $report->data[0]['description']);
    }

    /** @test */
    public function it_includes_opening_balance()
    {
        $account = ChartOfAccount::where('code', '1000')->first();

        // Entry before the filter period
        $entry1 = $this->createJournalEntry([
            'entry_date' => Carbon::parse('2023-12-15'),
            'description' => 'Previous Period',
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

        $this->assertArrayHasKey('opening_balance', $report->summary);
        $this->assertEquals(5000, $report->summary['opening_balance']);
    }
}
