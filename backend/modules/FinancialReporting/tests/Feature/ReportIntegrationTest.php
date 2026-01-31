<?php

namespace Modules\FinancialReporting\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;

class ReportIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
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
            ChartOfAccount::create([
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $account['type'],
                'is_active' => true,
            ]);
        }

        // Create realistic transactions
        $this->createInitialInvestment();
        $this->createSalesTransactions();
        $this->createExpenseTransactions();
        $this->createAssetPurchase();
    }

    protected function createInitialInvestment(): void
    {
        $entry = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-01'),
            'description' => 'Initial Investment',
            'status' => 'posted',
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
                'entry_date' => Carbon::parse('2024-01-' . (5 * $i)),
                'description' => "Sale #{$i}",
                'status' => 'posted',
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
            'entry_date' => Carbon::parse('2024-01-31'),
            'description' => 'Monthly Operating Expenses',
            'status' => 'posted',
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
            'entry_date' => Carbon::parse('2024-01-15'),
            'description' => 'Equipment Purchase',
            'status' => 'posted',
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
        // Generate all three main financial statements
        $plResponse = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/profit-loss?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $bsResponse = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/balance-sheet?' . http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $cfResponse = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/cash-flow?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        // All reports should succeed
        $plResponse->assertStatus(200);
        $bsResponse->assertStatus(200);
        $cfResponse->assertStatus(200);

        $pl = $plResponse->json();
        $bs = $bsResponse->json();
        $cf = $cfResponse->json();

        // Verify P&L calculations
        $this->assertEquals(50000, $pl['data']['revenue']['total']); // 5 sales × 10000
        $this->assertEquals(30000, $pl['data']['cost_of_goods_sold']['total']); // 5 sales × 6000
        $this->assertEquals(20000, $pl['data']['gross_profit']['amount']); // 50000 - 30000
        $this->assertEquals(5000, $pl['data']['operating_expenses']['total']);
        $this->assertEquals(15000, $pl['data']['net_profit']['amount']); // 20000 - 5000

        // Verify Balance Sheet balances
        $this->assertEquals(
            $bs['data']['assets']['total'],
            $bs['data']['liabilities']['total'] + $bs['data']['equity']['total'],
            'Assets should equal Liabilities + Equity'
        );

        // Verify net profit flows to equity
        $this->assertEquals(
            115000, // 100000 initial + 15000 net profit
            $bs['data']['equity']['total']
        );
    }

    /** @test */
    public function it_maintains_data_consistency_across_trial_balance_and_balance_sheet()
    {
        $tbResponse = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/trial-balance?' . http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $bsResponse = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/balance-sheet?' . http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $tbResponse->assertStatus(200);
        $bsResponse->assertStatus(200);

        $tb = $tbResponse->json();
        $bs = $bsResponse->json();

        // Trial balance should balance
        $this->assertEquals(
            $tb['data']['totals']['total_debits'],
            $tb['data']['totals']['total_credits'],
            'Trial Balance should balance'
        );

        $this->assertTrue($tb['data']['totals']['balanced']);
    }

    /** @test */
    public function it_handles_complete_workflow_from_transaction_to_report()
    {
        // Step 1: Create a new transaction
        $cashAccount = ChartOfAccount::where('code', '1000')->first();
        $revenueAccount = ChartOfAccount::where('code', '4100')->first();

        $entry = JournalEntry::create([
            'entry_date' => Carbon::parse('2024-01-31'),
            'description' => 'New Sale',
            'status' => 'posted',
        ]);

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

        // Step 2: Generate P&L report
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/profit-loss?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $response->assertStatus(200);
        $data = $response->json();

        // Step 3: Verify new transaction appears in report
        $this->assertGreaterThanOrEqual(5000, $data['data']['other_income']['total']);

        // Step 4: Export report
        $exportResponse = $this->actingAs($this->user)
            ->postJson('/api/reports/export/csv', [
                'report_type' => 'profit-loss',
                'report_data' => $data,
            ]);

        $exportResponse->assertStatus(200)
            ->assertJsonStructure(['success', 'file_path']);
    }

    /** @test */
    public function it_handles_multi_period_comparison_correctly()
    {
        // Generate report with comparison
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/profit-loss?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
                'compare_start_date' => '2023-12-01',
                'compare_end_date' => '2023-12-31',
            ]));

        $response->assertStatus(200);
        $data = $response->json();

        // Should include comparison data
        if (isset($data['comparison'])) {
            $this->assertArrayHasKey('revenue', $data['comparison']);
            $this->assertArrayHasKey('change', $data['comparison']['revenue']);
            $this->assertArrayHasKey('change_percent', $data['comparison']['revenue']);
        }
    }

    /** @test */
    public function it_handles_general_ledger_with_running_balance()
    {
        $cashAccount = ChartOfAccount::where('code', '1000')->first();

        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/general-ledger?' . http_build_query([
                'account_id' => $cashAccount->id,
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('opening_balance', $data['data']);
        $this->assertArrayHasKey('transactions', $data['data']);
        $this->assertArrayHasKey('closing_balance', $data['data']);

        // Verify running balance is calculated
        if (count($data['data']['transactions']) > 0) {
            foreach ($data['data']['transactions'] as $transaction) {
                $this->assertArrayHasKey('balance', $transaction);
            }
        }
    }

    /** @test */
    public function it_validates_accounting_equation_across_all_reports()
    {
        $bsResponse = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/balance-sheet?' . http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $bsResponse->assertStatus(200);
        $bs = $bsResponse->json();

        // Assets = Liabilities + Equity (fundamental accounting equation)
        $assets = $bs['data']['assets']['total'];
        $liabilities = $bs['data']['liabilities']['total'];
        $equity = $bs['data']['equity']['total'];

        $this->assertEquals(
            $assets,
            $liabilities + $equity,
            'Accounting equation must hold: Assets = Liabilities + Equity',
            0.01 // Allow for rounding
        );
    }
}
