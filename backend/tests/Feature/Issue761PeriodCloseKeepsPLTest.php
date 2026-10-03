<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\PostingEngine;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PeriodCloseService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #761 (A1, decision 2026-10-02, option b — Odoo model) — a period close
 * is a lock only: the old monthly `period_close` entry zeroed the month's P&L,
 * so every closed month reported ~zero revenue and expenses. The one closing
 * entry to retained earnings is posted at fiscal year end.
 *
 * Real HTTP through the router for the close and both statements.
 */
class Issue761PeriodCloseKeepsPLTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private FiscalPeriod $may;

    private int $revenueId;

    private int $arId;

    private int $retainedId;

    protected function setUp(): void
    {
        parent::setUp();

        // JournalEntryObserver's BI job runs on the sync queue and ends
        // tenancy mid-test; the later authenticated requests would then
        // re-initialize and lose this uncommitted fixture transaction.
        Queue::fake();

        // The close route carries entity-context middleware not under test here.
        $this->withoutMiddleware([
            \App\Http\Middleware\ResolveEntityContext::class,
            \App\Http\Middleware\EnsureEntityAccess::class,
        ]);

        $this->withMiddleware(
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
        );

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->assertTenantSchemaReady([
            'chart_of_accounts',
            'journal_entries',
            'journal_entry_lines',
            'fiscal_periods',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $account = fn (string $code, string $type) => ChartOfAccount::create([
            'code' => $code, 'name' => $code, 'type' => $type, 'account_type' => $type, 'is_active' => true,
        ])->id;

        $this->revenueId = $account('4100-'.Str::random(4), 'revenue');
        $this->arId = $account('1100-'.Str::random(4), 'asset');
        $this->retainedId = $account('3100-'.Str::random(4), 'equity');

        $this->may = FiscalPeriod::create([
            'name' => 'May 2026',
            'code' => '2026-05',
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2026,
            'status' => 'open',
            'created_by' => $this->admin->id,
        ]);
    }

    private function postMayRevenue(): void
    {
        $result = app(PostingEngine::class)->post(new PostingRequest(
            documentType: 'credit_note',
            documentId: 761001,
            postingDate: '2026-05-15',
            description: 'Issue #761 May revenue',
            lines: [
                new PostingRequestLine(accountMappingKey: 'accounts_receivable', accountId: $this->arId, debit: 1000, credit: 0, description: 'DR AR'),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenueId, debit: 0, credit: 1000, description: 'CR Revenue'),
            ],
            userId: $this->admin->id,
        ));

        $this->assertTrue($result->success, $result->error ?? $result->message ?? 'May revenue posting failed');
    }

    private function profitLossRevenue(): float
    {
        return (float) $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/financial/profit-loss?start_date=2026-05-01&end_date=2026-05-31')
            ->assertOk()
            ->json('data.revenue.total');
    }

    private function balanceSheet(): array
    {
        return (array) $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/financial/balance-sheet?as_of_date=2026-05-31')
            ->assertOk()
            ->json('data');
    }

    public function test_closing_may_keeps_mays_pl_and_the_balance_sheet_balanced(): void
    {
        $this->postMayRevenue();

        $before = $this->balanceSheet();
        $this->assertEqualsWithDelta(
            (float) $before['total_assets'],
            (float) $before['total_liabilities_and_equity'],
            0.01,
            'the balance sheet must be balanced before the close'
        );

        $this->assertSame(1000.0, $this->profitLossRevenue(), 'May revenue must be visible before the close');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/accounting/periods/{$this->may->id}/close")
            ->assertOk();

        $after = $this->balanceSheet();
        $this->assertEqualsWithDelta(
            (float) $after['total_assets'],
            (float) $after['total_liabilities_and_equity'],
            0.01,
            'the balance sheet must stay balanced after the close (no equity double-count)'
        );
        $this->assertGreaterThan(0.0, (float) $after['total_equity'], 'the year-to-date P&L must appear as equity');

        $this->assertSame(1000.0, $this->profitLossRevenue(), 'closing May must not zero May revenue');

        $this->assertSame(
            0,
            JournalEntry::where('type', 'period_close')->where('period_id', $this->may->id)->count(),
            'a monthly close must not post a closing entry (decision option b: the close is a lock)'
        );
    }

    public function test_the_repair_reverses_a_legacy_monthly_closing_entry_and_keeps_the_lock(): void
    {
        $this->postMayRevenue();

        // Simulate the legacy state: a monthly closing entry posted under the
        // old model (before the decision).
        $this->actingAs($this->admin);
        $service = app(PeriodCloseService::class);
        $postClosing = new ReflectionMethod($service, 'postClosingEntry');
        $postClosing->setAccessible(true);
        $entry = $postClosing->invoke($service, $this->may, $this->retainedId, [$this->revenueId => 1000.0]);
        $this->assertNotNull($entry, 'the legacy fixture must create the old monthly closing entry');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/accounting/periods/{$this->may->id}/close")
            ->assertOk();

        $active = fn () => JournalEntry::where('type', 'period_close')
            ->where('period_id', $this->may->id)
            ->where('is_active_version', true)
            ->count();

        $this->assertSame(1, $active(), 'the legacy closing entry must exist before the repair');

        Artisan::call('accounting:reverse-monthly-period-closes');
        $this->bindTenantContext($this->tenantId);
        $this->assertSame(1, $active(), 'the default run must be a dry-run report');

        Artisan::call('accounting:reverse-monthly-period-closes', ['--apply' => true]);
        $this->bindTenantContext($this->tenantId);

        $this->assertSame(0, $active(), 'the repair must reverse the legacy monthly closing entry');

        $period = FiscalPeriod::find($this->may->id);
        $this->assertSame('closed', $period?->status, 'the period lock must be kept');
    }
}
