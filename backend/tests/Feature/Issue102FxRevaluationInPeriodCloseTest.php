<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PeriodCloseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #102 (hostile re-review C-5): period close must run the REAL FX
 * revaluation (not a placeholder returning zero), and reopening the period must
 * reverse the revaluation entries.
 */
class Issue102FxRevaluationInPeriodCloseTest extends TenantTestCase
{
    private User $user;

    private FiscalPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '10210210-2020-3030-4040-505060607080',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'fiscal_periods', 'journal_entries', 'journal_entry_lines',
            'chart_of_accounts', 'account_mappings', 'exchange_rates', 'company_settings',
        ]);

        $this->user = User::create([
            'name' => 'Issue102 User',
            'email' => 'issue102-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        foreach (['accounting.write', 'accounting.post', 'accounting.reverse'] as $permission) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo(['accounting.write', 'accounting.post', 'accounting.reverse']);

        $this->actingAs($this->user);

        DB::table('company_settings')->insert([
            'base_currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->period = FiscalPeriod::create([
            'code' => now()->format('Y-m'),
            'name' => now()->format('F Y'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'period_type' => 'monthly',
            'fiscal_year' => (int) now()->format('Y'),
            'is_locked' => false,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);

        $fxLoss = ChartOfAccount::create(['code' => '7199', 'name' => 'FX loss', 'type' => 'expense', 'account_type' => 'expense', 'is_active' => true]);
        AccountMapping::create(['mapping_key' => 'unrealized_fx_gain_loss', 'account_id' => $fxLoss->id]);
    }

    private function foreignCurrencyBalance(): void
    {
        $monetary = ChartOfAccount::create(['code' => '1199', 'name' => 'FC receivable', 'type' => 'asset', 'account_type' => 'asset', 'is_active' => true]);

        $entry = JournalEntry::create([
            'entry_number' => 'JE-102-'.Str::upper(Str::random(6)),
            'entry_date' => now()->startOfMonth()->toDateString(),
            'status' => 'posted',
            'is_active_version' => true,
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $monetary->id,
            'debit' => 700,
            'credit' => 0,
            'currency_code' => 'SGD',
            'original_amount' => 1000,
        ]);
    }

    public function test_period_close_posts_real_fx_revaluation_and_reopen_reverses_it(): void
    {
        $this->foreignCurrencyBalance();

        DB::table('exchange_rates')->insert([
            'from_currency' => 'SGD',
            'to_currency' => 'USD',
            'effective_date' => now()->toDateString(),
            'rate' => 0.75,
            'source' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PeriodCloseService::class)->closePeriod($this->period, (string) $this->user->id);

        $fxAdjustments = collect($result['adjustments'])->where('type', 'fx_revaluation');
        $this->assertNotEmpty($fxAdjustments, 'Close must run a real FX revaluation, not a placeholder');
        $this->assertTrue((bool) $fxAdjustments->first()['posted'], 'The revaluation entry must be posted');

        $fxEntry = JournalEntry::where('reference', 'like', 'FXREVAL-%')->first();
        $this->assertNotNull($fxEntry, 'A revaluation journal entry must exist');

        // 1000 SGD at 0.75 = 750 vs 700 book -> 50 unrealised gain.
        $contra = JournalEntryLine::where('journal_entry_id', $fxEntry->id)
            ->where('account_id', '!=', $fxEntry->lines()->first()->account_id)
            ->first();
        $this->assertNotNull($contra);

        // Reopening the period reverses the revaluation entries.
        app(PeriodCloseService::class)->reopenPeriod($this->period->fresh(), (string) $this->user->id, 'test reopen');

        // The reversal engine posts a separate reversal entry (the original
        // keeps its row for audit; the reversal carries reversed_entry_id).
        $this->assertTrue(
            JournalEntry::where('reversed_entry_id', $fxEntry->id)->where('status', 'posted')->exists(),
            'Reopening must post a reversal for the FX revaluation entry'
        );
    }
}
