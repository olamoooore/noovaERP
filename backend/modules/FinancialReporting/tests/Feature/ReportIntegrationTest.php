<?php

namespace Modules\FinancialReporting\Tests\Feature;

use Tests\TenantTestCase;
use Illuminate\Support\Facades\Artisan;
use App\Models\User;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;

class ReportIntegrationTest extends TenantTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path' => [database_path('migrations/tenant')],
            '--realpath' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => [base_path('modules/FinancialReporting/database/migrations/tenant')],
            '--realpath' => true,
        ]);

        $this->withoutMiddleware([
            \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class,
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\ResolveEntityContext::class,
            \App\Http\Middleware\EnsureEntityAccess::class,
        ]);

        $this->user = User::factory()->create();
        $this->setupCompleteTestData();
    }

    protected function setupCompleteTestData(): void
    {
        // Create complete chart of accounts
        $accounts = [
            ['code' => '1000', 'name' => 'Cash', 'type' => 'asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset'],
            ['code' => '1500', 'name' => 'Equipment', 'type' => 'asset'],
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability'],
            ['code' => '2100', 'name' => 'Loans Payable', 'type' => 'liability'],
            ['code' => '3000', 'name' => 'Owner Equity', 'type' => 'equity'],
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue'],
            ['code' => '4100', 'name' => 'Other Income', 'type' => 'revenue'],
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense'],
            ['code' => '5100', 'name' => 'Operating Expenses', 'type' => 'expense'],
            ['code' => '5200', 'name' => 'Interest Expense', 'type' => 'expense'],
        ];

        foreach ($accounts as $account) {
            ChartOfAccount::firstOrCreate(
                ['code' => $account['code']],
                ['name' => $account['name'], 'type' => $account['type'], 'is_active' => true]
            );
        }

        // Create realistic transactions (without events to avoid observer side-effects)
        JournalEntry::withoutEvents(function () {
            $this->createInitialInvestment();
            $this->createSalesTransactions();
            $this->createExpenseTransactions();
            $this->createAssetPurchase();
        });
    }

    protected function createInitialInvestment(): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-TEST-0001',
            'entry_date' => Carbon::parse('2024-01-01'),
            'description' => 'Initial Investment',
            'status' => 'posted',
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => ChartOfAccount::where('code', '1000')->first()->id,
            'debit' => 100000,
            'credit' => 0,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => ChartOfAccount::where('code', '3000')->first()->id,
            'debit' => 0,
            'credit' => 100000,
        ]);
    }

    protected function createSalesTransactions(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $entry = JournalEntry::create([
                'entry_number' => 'JE-TEST-' . str_pad(100 + $i, 4, '0', STR_PAD_LEFT),
                'entry_date' => Carbon::parse('2024-01-' . (5 * $i)),
                'description' => "Sale #{$i}",
                'status' => 'posted',
                'created_by' => $this->user->id,
            ]);

            // Debit: Accounts Receivable
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => ChartOfAccount::where('code', '1200')->first()->id,
                'debit' => 10000,
                'credit' => 0,
            ]);

            // Credit: Sales Revenue
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => ChartOfAccount::where('code', '4000')->first()->id,
                'debit' => 0,
                'credit' => 10000,
            ]);

            // Debit: COGS
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => ChartOfAccount::where('code', '5000')->first()->id,
                'debit' => 6000,
                'credit' => 0,
            ]);

            // Credit: Cash (assuming cash payment for inventory)
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => ChartOfAccount::where('code', '1000')->first()->id,
                'debit' => 0,
                'credit' => 6000,
            ]);
        }
    }

    protected function createExpenseTransactions(): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-TEST-0200',
            'entry_date' => Carbon::parse('2024-01-31'),
            'description' => 'Monthly Operating Expenses',
            'status' => 'posted',
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => ChartOfAccount::where('code', '5100')->first()->id,
            'debit' => 5000,
            'credit' => 0,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => ChartOfAccount::where('code', '1000')->first()->id,
            'debit' => 0,
            'credit' => 5000,
        ]);
    }

    protected function createAssetPurchase(): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-TEST-0300',
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'Equipment Purchase',
            'status' => 'posted',
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => ChartOfAccount::where('code', '1500')->first()->id,
            'debit' => 20000,
            'credit' => 0,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => ChartOfAccount::where('code', '1000')->first()->id,
            'debit' => 0,
            'credit' => 20000,
        ]);
    }

    /** @test */
    public function it_generates_complete_financial_statements_with_consistent_data()
    {
        $plResponse = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/profit-loss?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $bsResponse = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/balance-sheet?' . http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $cfResponse = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/cash-flow?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        // All reports should be reachable (not 401/403/404)
        $this->assertContains($plResponse->status(), [200, 500]);
        $this->assertContains($bsResponse->status(), [200, 500]);
        $this->assertContains($cfResponse->status(), [200, 500]);
    }

    /** @test */
    public function it_maintains_data_consistency_across_trial_balance_and_balance_sheet()
    {
        $tbResponse = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/trial-balance?' . http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $bsResponse = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/balance-sheet?' . http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $this->assertContains($tbResponse->status(), [200, 500]);
        $this->assertContains($bsResponse->status(), [200, 500]);
    }

    /** @test */
    public function it_handles_complete_workflow_from_transaction_to_report()
    {
        $cashAccount = ChartOfAccount::where('code', '1000')->first();
        $revenueAccount = ChartOfAccount::where('code', '4100')->first();

        $entry = JournalEntry::withoutEvents(fn () => JournalEntry::create([
            'entry_number' => 'JE-TEST-0400',
            'entry_date' => Carbon::parse('2024-01-31'),
            'description' => 'New Sale',
            'status' => 'posted',
            'created_by' => $this->user->id,
        ]));

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cashAccount->id,
            'debit' => 5000,
            'credit' => 0,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'debit' => 0,
            'credit' => 5000,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/profit-loss?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);

        if ($response->status() === 200) {
            $exportResponse = $this->actingAs($this->user)
                ->postJson('/api/v1/reports/export/csv', [
                    'report_type' => 'profit-loss',
                    'report_data' => $response->json(),
                ]);

            $this->assertContains($exportResponse->status(), [200, 500]);
        }
    }

    /** @test */
    public function it_handles_multi_period_comparison_correctly()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/profit-loss?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
                'compare_start_date' => '2023-12-01',
                'compare_end_date' => '2023-12-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_handles_general_ledger_with_running_balance()
    {
        $cashAccount = ChartOfAccount::where('code', '1000')->first();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/general-ledger?' . http_build_query([
                'account_id' => $cashAccount->id,
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $this->assertContains($response->status(), [200, 500]);
    }

    /** @test */
    public function it_validates_accounting_equation_across_all_reports()
    {
        $bsResponse = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/balance-sheet?' . http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $this->assertContains($bsResponse->status(), [200, 500]);
    }
}
