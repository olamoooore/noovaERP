<?php

declare(strict_types=1);

namespace Modules\FinancialReporting\Tests\Unit;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Reports\BalanceSheetService;
use Tests\TenantTestCase;

/**
 * Regression tests for the production 2026-09-24 incident where the Balance
 * Sheet diverged from Acme's source PDF. Five distinct bugs were fixed
 * in this change:
 *
 *   1. Soft-deleted chart_of_accounts rows surfaced in the SQL via the
 *      coa.id = jel.account_id join, double-counting balances whenever a
 *      tenant had a deactivated duplicate (e.g. 125100 with deleted_at set).
 *   2. LEFT JOIN bank_accounts multiplied journal lines per COA row that
 *      had multiple bank-account rows pointing at it.
 *   3. "Opening Balance Equity" rows polluted current-period equity totals.
 *   4. 200120 Sales Tax Payable appeared as its own top-level line because
 *      it had parent_id = NULL, when Acme-style statements roll it into
 *      "Trade and other payables" — verified at the controller level via
 *      grouping by parent_code.
 *   5. Hard `is_active_version = true` filter was kept (this is correct:
 *      superseded journal versions must not double-count) — tests below
 *      prove it is still applied.
 */
class HR262BalanceSheetLiveDataAccuracyTest extends TenantTestCase
{
    protected BalanceSheetService $service;

    protected User $testUser;

    protected function setUp(): void
    {
        parent::setUp();
        // Force journal-entry queries (not summary table); production
        // behaviour matches the live SQL path that produced the wrong
        // numbers.
        config(['financialreporting.reports.use_summary_after_days' => 999999]);
        $this->service = app(BalanceSheetService::class);
        $this->testUser = User::factory()->create();
        $this->seedFixtures();
    }

    private function seedFixtures(): void
    {
        ChartOfAccount::firstOrCreate(
            ['code' => '125100'],
            ['name' => 'Cash at bank — SGD', 'type' => 'asset', 'subtype' => 'asset_current', 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['code' => '110210'],
            ['name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'current_asset', 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['code' => '200120'],
            ['name' => 'Sales Tax Payable', 'type' => 'liability', 'subtype' => 'current_liability', 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['code' => '300190'],
            ['name' => 'Opening Balance Equity', 'type' => 'equity', 'subtype' => 'equity', 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['code' => '400100'],
            ['name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'operating_revenue', 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['code' => '510000'],
            ['name' => 'Cost of Goods Sold', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold', 'is_active' => true]
        );
    }

    private function postEntry(
        string $accountCode,
        float $debit,
        float $credit,
        string $date = '2025-08-15',
        string $description = 'Test posting'
    ): void {
        $account = ChartOfAccount::where('code', $accountCode)->whereNull('deleted_at')->first();
        $this->assertNotNull($account, "Fixture account {$accountCode} missing");

        $entry = JournalEntry::create([
            'entry_number' => 'JE-'.uniqid(),
            'entry_date' => Carbon::parse($date),
            'description' => $description,
            'status' => 'posted',
            'is_active_version' => true,
            'total_debit' => $debit,
            'total_credit' => $credit,
            'created_by' => $this->testUser->id,
            'legal_entity_id' => null,
            'entity_book_id' => null,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
        ]);
    }

    public function test_soft_deleted_coa_row_is_excluded_from_assets(): void
    {
        // Post a deposit to the original 125100 row.
        $this->postEntry('125100', 10000, 0, '2025-08-15', 'Cash deposit');

        // Deactivate the original and create a fresh replacement (partial
        // unique index on (code) WHERE deleted_at IS NULL permits the
        // second row). The original 10,000 stays attributed to the
        // soft-deleted row — it does NOT carry over to the replacement.
        $original = ChartOfAccount::where('code', '125100')->whereNull('deleted_at')->first();
        $originalId = $original->id;
        $original->delete();

        $replacement = new ChartOfAccount;
        $replacement->forceFill([
            'code' => '125100',
            'name' => 'Cash at bank — SGD',
            'type' => 'asset',
            'subtype' => 'asset_current',
            'is_active' => true,
        ])->save();

        $this->postEntry('125100', 5000, 0, '2025-08-15', 'Second deposit');

        $report = $this->service->generate(new ReportFilter(asOfDate: Carbon::parse('2025-08-31')));

        $currentAssets = collect($report->data['assets']['current_assets']['accounts']);
        $cashRow = $currentAssets->firstWhere('code', '125100');
        $this->assertNotNull($cashRow, 'Live 125100 row must appear in current assets.');
        // The replacement only sees its own 5,000 — the soft-deleted
        // original's 10,000 is correctly excluded from the report.
        $this->assertSame(5000.0, (float) $cashRow['balance']);
        $this->assertSame($replacement->id, (int) $cashRow['account_id']);
        $this->assertNotSame($originalId, $replacement->id);
    }

    public function test_opening_balance_equity_is_included_in_equity_section(): void
    {
        // Opening Balance Equity is a legitimate equity account created by
        // the import process. It MUST be included in the equity total so
        // the accounting equation (A = L + E) balances. The earlier
        // "drop opening-balance equity" filter was a production-incident
        // regression that threw the balance check off by that account's
        // balance (2026-09-24).
        //
        // Post a balanced pair so the equation holds end-to-end:
        //   DR Cash 10,000  /  CR Opening Balance Equity 10,000
        $this->postEntry('125100', 10000, 0, '2025-08-15', 'Opening cash');
        $this->postEntry('300190', 0, 10000, '2025-08-15', 'Opening balancing entry');

        $report = $this->service->generate(new ReportFilter(asOfDate: Carbon::parse('2025-08-31')));

        $equityAccounts = $report->data['equity']['accounts'];
        $codes = array_column($equityAccounts, 'code');
        $this->assertContains(
            '300190',
            $codes,
            'Opening Balance Equity must appear in the equity-section accounts list.'
        );

        $totalAssets = (float) $report->data['assets']['total'];
        $totalLiabilities = (float) $report->data['liabilities']['total'];
        $totalEquity = (float) $report->data['equity']['total'];

        $this->assertSame(10000.0, $totalAssets);
        $this->assertSame(0.0, $totalLiabilities);
        $this->assertSame(10000.0, $totalEquity);
        $this->assertSame(
            0.0,
            round($totalAssets - $totalLiabilities - $totalEquity, 2),
            'A - L - E must be exactly 0 when opening-balance equity is included.'
        );
    }

    public function test_superseded_journal_version_does_not_double_count(): void
    {
        // First version: 10,000 debit to 125100.
        $this->postEntry('125100', 10000, 0, '2025-08-15', 'Original');

        // Mark the original entry as superseded (is_active_version = false)
        // via raw DB update to bypass any model events that might flip it back.
        DB::table('journal_entries')
            ->where('description', 'Original')
            ->update(['is_active_version' => false]);

        // Second version: 7,000 debit, marked as active.
        $account = ChartOfAccount::where('code', '125100')->whereNull('deleted_at')->first();
        $entry = JournalEntry::create([
            'entry_number' => 'JE-'.uniqid(),
            'entry_date' => Carbon::parse('2025-08-16'),
            'description' => 'Amended',
            'status' => 'posted',
            'is_active_version' => true,
            'total_debit' => 7000,
            'total_credit' => 0,
            'created_by' => $this->testUser->id,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => 7000,
            'credit' => 0,
            'description' => 'Amended',
        ]);

        $report = $this->service->generate(new ReportFilter(asOfDate: Carbon::parse('2025-08-31')));

        $currentAssets = collect($report->data['assets']['current_assets']['accounts']);
        $cashRow = $currentAssets->firstWhere('code', '125100');
        $this->assertNotNull($cashRow);
        $this->assertSame(7000.0, (float) $cashRow['balance'], 'Only the active journal version must contribute.');
    }

    public function test_null_subtype_asset_with_typed_parent_land_is_included_in_total(): void
    {
        // Production case: `123200 Short-term deposits` carries subtype=NULL
        // but its parent chain ascends to `120000 Current assets`. A bare
        // `subtype IN (...)` filter dropped it from total assets and broke
        // A = L + E by 1,631 on the restored Acme data (2026-09-24).
        $parent = ChartOfAccount::firstOrCreate(
            ['code' => '122000'],
            ['name' => 'Trade and other receivables', 'type' => 'asset', 'subtype' => null, 'is_active' => true]
        );
        $rootGroup = ChartOfAccount::firstOrCreate(
            ['code' => '120000'],
            ['name' => 'Current assets', 'type' => 'asset', 'subtype' => null, 'is_active' => true]
        );
        $parent->parent_id = $rootGroup->id;
        $parent->save();

        $child = new ChartOfAccount;
        $child->forceFill([
            'code' => '123200',
            'name' => 'Short-term deposits',
            'type' => 'asset',
            'subtype' => null,
            'parent_id' => $parent->id,
            'is_active' => true,
        ])->save();

        // Balanced pair so the equation holds:
        //   DR Short-term deposits 1,631  /  CR Opening Balance Equity 1,631
        $this->postEntry('123200', 1631, 0, '2025-08-15', 'Deposit placement');
        $this->postEntry('300190', 0, 1631, '2025-08-15', 'Funding');

        $report = $this->service->generate(new ReportFilter(asOfDate: Carbon::parse('2025-08-31')));

        $currentAssets = collect($report->data['assets']['current_assets']['accounts']);
        $depositRow = $currentAssets->firstWhere('code', '123200');

        $this->assertNotNull(
            $depositRow,
            'A subtype-less asset whose parent chain reaches "Current assets" must appear in current assets.'
        );
        $this->assertSame(1631.0, (float) $depositRow['balance']);

        $totalAssets = (float) $report->data['assets']['total'];
        $totalEquity = (float) $report->data['equity']['total'];
        $this->assertSame(1631.0, $totalAssets);
        $this->assertSame(1631.0, $totalEquity);
        $this->assertSame(0.0, round($totalAssets - $totalEquity, 2));
    }

    public function test_accounting_equation_holds_after_fix(): void
    {
        // Balanced transaction: 1000 cash sale (debit 125100 cash, credit 400100 revenue).
        $this->postEntry('125100', 1000, 0, '2025-08-15', 'Cash sale');
        $this->postEntry('400100', 0, 1000, '2025-08-15', 'Cash sale revenue');

        $report = $this->service->generate(new ReportFilter(asOfDate: Carbon::parse('2025-08-31')));

        $totalAssets = (float) $report->data['assets']['total'];
        $totalLiabilities = (float) $report->data['liabilities']['total'];
        $totalEquity = (float) $report->data['equity']['total'];

        // A = 1000 (cash); L = 0 (no liabilities); E = 1000 (calculated
        // earnings = revenue 1000 - expense 0). The accounting equation
        // A = L + E must hold exactly.
        $this->assertSame(1000.0, $totalAssets);
        $this->assertSame(0.0, $totalLiabilities);
        $this->assertSame(1000.0, $totalEquity);
        $this->assertSame(0.0, round($totalAssets - $totalLiabilities - $totalEquity, 2), 'A - L - E must equal 0.');
    }
}
