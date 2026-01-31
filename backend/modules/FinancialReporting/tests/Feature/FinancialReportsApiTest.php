<?php

namespace Modules\FinancialReporting\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;

class FinancialReportsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->setupTestData();
    }

    protected function setupTestData(): void
    {
        ChartOfAccount::create([
            'code' => '4000',
            'name' => 'Sales Revenue',
            'type' => 'revenue',
            'is_active' => true,
        ]);

        ChartOfAccount::create([
            'code' => '5000',
            'name' => 'Cost of Goods Sold',
            'type' => 'expense',
            'is_active' => true,
        ]);

        ChartOfAccount::create([
            'code' => '1000',
            'name' => 'Cash',
            'type' => 'asset',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_can_generate_profit_loss_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/profit-loss?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'data' => [
                    'revenue',
                    'cost_of_goods_sold',
                    'gross_profit',
                    'operating_expenses',
                    'net_profit',
                ],
                'summary',
                'metadata',
            ]);
    }

    /** @test */
    public function it_requires_authentication_for_profit_loss()
    {
        $response = $this->getJson('/api/reports/financial/profit-loss?' . http_build_query([
            'start_date' => '2024-01-01',
            'end_date' => '2024-01-31',
        ]));

        $response->assertStatus(401);
    }

    /** @test */
    public function it_validates_required_dates_for_profit_loss()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/profit-loss');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    /** @test */
    public function it_can_generate_balance_sheet_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/balance-sheet?' . http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'data' => [
                    'assets',
                    'liabilities',
                    'equity',
                ],
                'summary',
            ]);
    }

    /** @test */
    public function it_can_generate_cash_flow_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/cash-flow?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'data' => [
                    'operating_activities',
                    'investing_activities',
                    'financing_activities',
                    'net_cash_flow',
                ],
            ]);
    }

    /** @test */
    public function it_can_generate_trial_balance_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/trial-balance?' . http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'data' => [
                    'accounts',
                    'totals',
                ],
            ]);
    }

    /** @test */
    public function it_can_generate_general_ledger_report()
    {
        $account = ChartOfAccount::first();

        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/financial/general-ledger?' . http_build_query([
                'account_id' => $account->id,
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'data' => [
                    'transactions',
                    'opening_balance',
                ],
            ]);
    }

    /** @test */
    public function it_caches_report_results()
    {
        // First request
        $start = microtime(true);
        $this->actingAs($this->user)
            ->getJson('/api/reports/financial/profit-loss?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));
        $firstDuration = microtime(true) - $start;

        // Second request (should be cached)
        $start = microtime(true);
        $this->actingAs($this->user)
            ->getJson('/api/reports/financial/profit-loss?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));
        $secondDuration = microtime(true) - $start;

        // Cached request should be faster
        $this->assertLessThan($firstDuration, $secondDuration);
    }
}
