<?php

declare(strict_types=1);

namespace Modules\FinancialReporting\Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TenantTestCase;

/**
 * Per-tenant statement-line presentation filter.
 *
 * Tenant setting `reporting.balance_sheet.hidden_account_codes` hides
 * accounts/groups from the Statement of Financial Position UI while the
 * section totals (and therefore A = L + E) remain untouched.
 *
 * Operator decision 2026-09-24: Acme Production hides Sales Tax Payable
 * (200120), Inventory (121000) and the GST control / Current tax payable
 * pair (223000 / 226000) to match their published statement layout.
 */
class HR263BalanceSheetHiddenLinesTest extends TenantTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path' => [database_path('migrations/tenant')],
            '--realpath' => true,
        ]);

        $this->withoutMiddleware([
            \Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains::class,
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\ResolveEntityContext::class,
            \App\Http\Middleware\EnsureEntityAccess::class,
        ]);

        $this->user = $this->setUpTenantAdminUser('Hidden Lines Tenant');

        // Cash (to keep the sheet balanced)
        ChartOfAccount::firstOrCreate(
            ['code' => '125100'],
            ['name' => 'Cash at bank — SGD', 'type' => 'asset', 'subtype' => 'asset_current', 'is_active' => true]
        );

        // The three lines the operator wants hidden
        ChartOfAccount::firstOrCreate(
            ['code' => '121000'],
            ['name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['code' => '200120'],
            ['name' => 'Sales Tax Payable', 'type' => 'liability', 'subtype' => 'current_liability', 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['code' => '226000'],
            ['name' => 'Current tax payable', 'type' => 'liability', 'subtype' => 'liability_current', 'is_active' => true]
        );

        // Revenue — used to keep the fixture in balance (A = L + E).
        ChartOfAccount::firstOrCreate(
            ['code' => '400100'],
            ['name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'operating_revenue', 'is_active' => true]
        );
    }

    private function postEntry(string $accountCode, float $debit, float $credit): void
    {
        $account = ChartOfAccount::where('code', $accountCode)->whereNull('deleted_at')->firstOrFail();

        $entry = JournalEntry::create([
            'entry_number' => 'JE-HIDDEN-'.uniqid(),
            'entry_date' => Carbon::parse('2025-08-15'),
            'description' => 'Hidden-lines fixture',
            'status' => 'posted',
            'is_active_version' => true,
            'total_debit' => $debit,
            'total_credit' => $credit,
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
            'description' => 'Hidden-lines fixture',
        ]);
    }

    private function fetchSheet(): array
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/financial/balance-sheet?as_of_date=2025-08-31');

        $response->assertOk();

        return $response->json('data');
    }

    /** @test */
    public function lines_are_visible_when_no_hidden_setting_is_configured(): void
    {
        $this->postEntry('125100', 1000, 0);
        $this->postEntry('121000', 500, 0);
        $this->postEntry('200120', 0, 300);
        $this->postEntry('226000', 0, 200);

        Setting::setValue('reporting.balance_sheet.hidden_account_codes', []);

        $data = $this->fetchSheet();

        $codes = collect($data['assets']['current'])
            ->flatMap(fn ($g) => collect($g['accounts'] ?? [])->pluck('code'))
            ->merge(collect($data['liabilities']['current'])->flatMap(fn ($g) => collect($g['accounts'] ?? [])->pluck('code')))
            ->all();

        $this->assertContains('121000', $codes, 'Inventory must show when nothing is hidden.');
        $this->assertContains('200120', $codes, 'Sales Tax Payable must show when nothing is hidden.');
        $this->assertContains('226000', $codes, 'Current tax payable must show when nothing is hidden.');
    }

    /** @test */
    public function hidden_codes_are_removed_from_groups_but_totals_are_unchanged(): void
    {
        // Balanced fixture:
        //   assets 1,500 (cash 1,000 + inventory 500)
        //   liabilities 500 (sales tax 300 + current tax 200)
        //   equity 1,000 (calculated earnings: revenue 1,000 - expense 0)
        $this->postEntry('125100', 1000, 0);
        $this->postEntry('121000', 500, 0);
        $this->postEntry('200120', 0, 300);   // Sales Tax Payable
        $this->postEntry('226000', 0, 200);   // Current tax payable
        $this->postEntry('400100', 0, 1000);  // Revenue (balances the sheet)

        // Capture the totals with NO filter applied.
        Setting::setValue('reporting.balance_sheet.hidden_account_codes', []);
        $before = $this->fetchSheet();

        // Now hide the three lines.
        Setting::setValue('reporting.balance_sheet.hidden_account_codes', ['200120', '121000', '226000']);
        $after = $this->fetchSheet();

        // Totals must be identical (presentation-only filter).
        $this->assertSame(
            (float) $before['total_assets'],
            (float) $after['total_assets'],
            'Total assets must not change when lines are hidden.'
        );
        $this->assertSame(
            (float) $before['total_liabilities'],
            (float) $after['total_liabilities'],
            'Total liabilities must not change when lines are hidden.'
        );
        $this->assertSame(
            (float) $before['total_equity'],
            (float) $after['total_equity'],
            'Total equity must not change when lines are hidden.'
        );

        // Hidden codes must not appear anywhere in the statement groups.
        $allCodes = collect($after['assets']['current'])
            ->concat($after['assets']['non_current'])
            ->concat($after['liabilities']['current'])
            ->concat($after['liabilities']['non_current'])
            ->concat($after['equity']['groups'] ?? [])
            ->flatMap(function ($group) {
                $accountCodes = collect($group['accounts'] ?? [])->pluck('code')->all();

                return array_merge([$group['code'] ?? ''], $accountCodes);
            })
            ->all();

        $this->assertNotContains('200120', $allCodes, 'Hidden account 200120 must not appear.');
        $this->assertNotContains('121000', $allCodes, 'Hidden account 121000 must not appear.');
        $this->assertNotContains('226000', $allCodes, 'Hidden account 226000 must not appear.');

        // The balance check must still be zero.
        $this->assertSame(
            0.0,
            round((float) $after['balance_check'], 2),
            'A - L - E must remain exactly 0 with hidden lines.'
        );
    }

    /** @test */
    public function group_with_all_accounts_hidden_is_dropped_entirely(): void
    {
        $this->postEntry('125100', 1000, 0);
        $this->postEntry('226000', 0, 200);

        Setting::setValue('reporting.balance_sheet.hidden_account_codes', ['226000']);
        $data = $this->fetchSheet();

        // The parent group (however the tenant COA names it) must not appear
        // as an empty shell.
        $groups = $data['liabilities']['current'];
        foreach ($groups as $group) {
            $this->assertNotEmpty(
                $group['accounts'] ?? [],
                "Group {$group['code']} must not be present with zero accounts."
            );
        }
    }
}
