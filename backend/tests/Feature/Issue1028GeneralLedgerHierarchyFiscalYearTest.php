<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\PostingEngine;
use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\FiscalPeriod;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #1028 (follow-up to #768) — the GL hierarchy drill-down and
 * CompanySettings::getFiscalYearStartDate() still read
 * company_settings.fiscal_year_start_month/day directly. #768 made
 * legal_entities.fiscal_year_start the single source of truth, so a year
 * start changed through the entity endpoint never reached these readers.
 *
 * Real HTTP through the router: an entity moves to FYE 30 June via
 * PUT /accounting/legal-entities/{id}; the GL hierarchy must then bucket
 * June 2026 into FY2026 and July 2026 into FY2027 (July–June).
 */
class Issue1028GeneralLedgerHierarchyFiscalYearTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private int $entityId;

    private int $revenueId;

    private int $arId;

    protected function setUp(): void
    {
        parent::setUp();

        // JournalEntryObserver's BI job runs on the sync queue and ends tenancy mid-test.
        Queue::fake();

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

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Group', 'code' => 'GRP-'.Str::upper(Str::random(4)),
            'base_currency' => 'MYR', 'fiscal_year_start' => '01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Starts on a calendar year; company_settings agrees (January).
        $this->entityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Year End Change Sdn Bhd', 'code' => 'YEC-'.Str::upper(Str::random(4)),
            'country_code' => 'MY', 'base_currency' => 'MYR', 'fiscal_year_start' => '01-01',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('company_settings')->delete();
        DB::table('company_settings')->insert([
            'company_name' => 'Year End Change Sdn Bhd',
            'fiscal_year_start_month' => 1,
            'fiscal_year_start_day' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $account = fn (string $code, string $type) => ChartOfAccount::create([
            'code' => $code, 'name' => $code, 'type' => $type, 'account_type' => $type, 'is_active' => true,
        ])->id;

        $this->revenueId = $account('4100-'.Str::random(4), 'revenue');
        $this->arId = $account('1100-'.Str::random(4), 'asset');

        foreach (['2026-06-01' => '2026-06-30', '2026-07-01' => '2026-07-31'] as $start => $end) {
            FiscalPeriod::create([
                'name' => $start, 'code' => substr($start, 0, 7),
                'period_start' => $start, 'period_end' => $end,
                'period_type' => 'monthly', 'fiscal_year' => 2026,
                'status' => 'open', 'created_by' => $this->admin->id,
            ]);
        }
    }

    private function postRevenue(string $date, int $documentId): void
    {
        $result = app(PostingEngine::class)->post(new PostingRequest(
            documentType: 'credit_note',
            documentId: $documentId,
            postingDate: $date,
            description: 'Issue #1028 revenue '.$date,
            lines: [
                new PostingRequestLine(accountMappingKey: 'accounts_receivable', accountId: $this->arId, debit: 100, credit: 0, description: 'DR AR'),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenueId, debit: 0, credit: 100, description: 'CR Revenue'),
            ],
            userId: $this->admin->id,
        ));

        $this->assertTrue($result->success, $result->error ?? $result->message ?? 'posting failed');
    }

    /** @return array<string, list<string>> fiscal year => months for the revenue account */
    private function revenueFiscalYears(array $hierarchy): array
    {
        foreach ($hierarchy['types'] as $type) {
            foreach ($type['groups'] as $group) {
                foreach ($group['accounts'] as $account) {
                    if ($account['id'] === $this->revenueId) {
                        $years = [];
                        foreach ($account['fiscal_years'] as $year) {
                            $years[(string) $year['year']] = array_column($year['months'], 'key');
                        }
                        ksort($years);

                        return $years;
                    }
                }
            }
        }

        $this->fail('revenue account missing from the GL hierarchy');
    }

    public function test_gl_hierarchy_follows_the_entitys_year_start_changed_through_the_entity_endpoint(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/accounting/legal-entities/'.$this->entityId, ['fiscal_year_start' => '07-01'])
            ->assertOk();

        $this->postRevenue('2026-06-15', 1028001);
        $this->postRevenue('2026-07-15', 1028002);

        $hierarchy = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/financial/general-ledger?start_date=2026-06-01&end_date=2026-07-31&legal_entity_id='.$this->entityId)
            ->assertOk()
            ->json('data.hierarchy');

        $this->assertSame(7, $hierarchy['fiscal_year_start_month'], 'the drill-down must use the entity\'s July year start');
        $this->assertSame(
            ['2026' => ['2026-06'], '2027' => ['2026-07']],
            $this->revenueFiscalYears($hierarchy),
            'FYE 30 June: June 2026 closes FY2026, July 2026 opens FY2027'
        );
    }

    public function test_company_settings_fiscal_year_start_date_is_derived_from_the_legal_entity(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/accounting/legal-entities/'.$this->entityId, ['fiscal_year_start' => '07-01'])
            ->assertOk();

        $settings = CompanySettings::query()->firstOrFail();

        $this->assertSame('2026-07-01', $settings->getFiscalYearStartDate(2026)->format('Y-m-d'));
        $this->assertSame('2027-06-30', $settings->getFiscalYearEndDate(2026)->format('Y-m-d'));
    }
}
