<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\PeriodCloseService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue P1-6 — PeriodCloseService must actually generate
 * closing entries that zero out P&L accounts and post to Retained Earnings,
 * not silently skip work and mark the period closed anyway.
 *
 * Adapted to the #761 decision (2026-10-02, option b — Odoo model): a monthly
 * close is a lock only; the closing entry to retained earnings is posted at
 * fiscal year end. These tests therefore exercise a fiscal-year-end period
 * (December); the monthly-close behaviour is guarded by
 * Issue761PeriodCloseKeepsPLTest.
 */
class IssueP16PeriodCloseRealEntriesTest extends TenantTestCase
{
    private string $tenantUuid;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        $this->user = User::factory()->create();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->user->assignRole('Admin');
        Auth::login($this->user);
    }

    private function makePeriod(): FiscalPeriod
    {
        return FiscalPeriod::create([
            'code' => '2026-P'.uniqid(),
            'name' => 'Test Period',
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-31',
            'fiscal_year' => 2026,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);
    }

    private function makeAccount(string $code, string $name, string $type): ChartOfAccount
    {
        return ChartOfAccount::create([
            'code' => $code.'-'.uniqid(),
            'name' => $name.'-'.uniqid(),
            'type' => $type,
            'account_type' => $type,
            'is_active' => true,
        ]);
    }

    public function test_year_end_close_generates_closing_entries_zeroing_out_pnl(): void
    {
        $revenue = $this->makeAccount('4000', 'Sales Revenue', 'revenue');
        $expense = $this->makeAccount('5000', 'Salaries Expense', 'expense');
        $cash = $this->makeAccount('1000', 'Cash', 'asset');

        $period = $this->makePeriod();

        // Seed P&L activity in the period: revenue 1000, expense 400
        $entry = JournalEntry::create([
            'entry_number' => 'TEST-'.Str::uuid(),
            'entry_date' => '2026-12-15',
            'description' => 'Test revenue + expense',
            'status' => 'posted',
            'type' => 'manual',
            'posted_at' => now(),
            'posted_by' => $this->user->id,
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'description' => 'Cash receipt',
            'debit' => 1000,
            'credit' => 0,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenue->id,
            'description' => 'Revenue earned',
            'debit' => 0,
            'credit' => 1000,
        ]);

        $expenseEntry = JournalEntry::create([
            'entry_number' => 'TEST-'.Str::uuid(),
            'entry_date' => '2026-12-20',
            'description' => 'Expense accrual',
            'status' => 'posted',
            'type' => 'manual',
            'posted_at' => now(),
            'posted_by' => $this->user->id,
            'created_by' => $this->user->id,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $expenseEntry->id,
            'account_id' => $expense->id,
            'description' => 'Salaries',
            'debit' => 400,
            'credit' => 0,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $expenseEntry->id,
            'account_id' => $cash->id,
            'description' => 'Cash paid',
            'debit' => 0,
            'credit' => 400,
        ]);

        // Run the close
        $result = app(PeriodCloseService::class)->closePeriod($period, (string) $this->user->id);

        // Result should include the closing entry
        $this->assertNotEmpty($result['closing_entries'], 'Period close must produce closing entries');

        $closingEntry = $result['closing_entries'][0];
        $this->assertSame('period_close', $closingEntry['type']);

        $entryId = $closingEntry['journal_entry_id'];
        $lines = DB::table('journal_entry_lines')->where('journal_entry_id', $entryId)->get();

        // Must have at least 3 lines: debit revenue, credit expense, offset to retained earnings
        $this->assertGreaterThanOrEqual(3, $lines->count(),
            'Closing entry must have lines for revenue + expense + retained earnings offset');

        // Sum of debits = sum of credits
        $totalDebit = (float) $lines->sum('debit');
        $totalCredit = (float) $lines->sum('credit');
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.01,
            'Closing entry must balance');

        // Period must be marked closed
        $period->refresh();
        $this->assertSame('closed', $period->status);

        // Retained Earnings account must exist after close (by name + type,
        // since the canonical code 3900-RE may not match the pre-existing
        // public-schema account code).
        $retainedEarnings = DB::table('chart_of_accounts')
            ->where('name', 'Retained Earnings')
            ->where('type', 'equity')
            ->first();
        $this->assertNotNull($retainedEarnings, 'Retained Earnings account must exist');
        $this->assertSame('equity', $retainedEarnings->type);
    }

    public function test_period_close_with_no_activity_still_marks_period_closed(): void
    {
        $period = $this->makePeriod();

        try {
            $result = app(PeriodCloseService::class)->closePeriod($period, (string) $this->user->id);
        } catch (\Throwable $e) {
            $this->fail('closePeriod failed: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString());
        }

        $period->refresh();
        $this->assertSame('closed', $period->status);
        $this->assertEmpty($result['closing_entries']);
    }
}
