<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #767 (A7) — statutory statements/XBRL summed every legal entity in the
 * tenant. They must be one entity's figures: entity required when the tenant
 * has entities, and the export country must match the entity.
 *
 * Real HTTP through the router.
 */
class StatutoryExportEntityScopeTest extends TenantTestCase
{
    private User $admin;

    private int $cashId;

    private int $revenueId;

    private int $entityA;

    private int $entityB;

    private int $entityForeign;

    protected function setUp(): void
    {
        parent::setUp();

        // The BI job runs on the sync queue and ends tenancy mid-test.
        Queue::fake();

        $this->withMiddleware(
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
        );

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        $this->cashId = ChartOfAccount::factory()->create(['code' => 'SE-CASH-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true])->id;
        $this->revenueId = ChartOfAccount::factory()->create(['code' => 'SE-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true])->id;

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Group', 'code' => 'SEG-'.Str::upper(Str::random(4)),
            'base_currency' => 'SGD', 'fiscal_year_start' => '01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $entity = fn (string $name, string $country) => DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => $name, 'code' => Str::upper(Str::random(6)),
            'country_code' => $country, 'base_currency' => $country === 'SG' ? 'SGD' : 'MYR',
            'fiscal_year_start' => '01-01', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->entityA = $entity('Alpha Pte Ltd', 'SG');
        $this->entityB = $entity('Beta Pte Ltd', 'SG');
        $this->entityForeign = $entity('Gamma Sdn Bhd', 'MY');
    }

    private function postForEntity(int $entityId, float $amount): void
    {
        $result = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: '2026-09-15',
            description: 'SE posting',
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $this->cashId, debit: $amount, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenueId, debit: 0.0, credit: $amount),
            ],
            userId: $this->admin->id,
        ));

        $this->assertTrue($result->success, $result->error ?? '');

        DB::table('journal_entries')->where('id', $result->journalEntry->id)->update(['legal_entity_id' => $entityId]);
    }

    private function exportUrl(int $entityId, string $country = 'SG'): string
    {
        return "/api/v1/accounting/compliance/reports/{$country}/balance_sheet?as_of_date=2026-09-30&legal_entity_id={$entityId}";
    }

    public function test_statutory_export_requires_a_legal_entity_when_the_tenant_has_entities(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/accounting/compliance/reports/SG/balance_sheet?as_of_date=2026-09-30')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_each_entitys_export_contains_only_its_own_postings(): void
    {
        $this->postForEntity($this->entityA, 300.0);
        $this->postForEntity($this->entityB, 500.0);

        $alpha = $this->actingAs($this->admin)->getJson($this->exportUrl($this->entityA))->assertOk();
        $this->assertEqualsWithDelta(
            300.0,
            (float) $alpha->json('data.totals.total_assets'),
            0.01,
            "Alpha's statement must contain only Alpha's postings"
        );

        $beta = $this->actingAs($this->admin)->getJson($this->exportUrl($this->entityB))->assertOk();
        $this->assertEqualsWithDelta(500.0, (float) $beta->json('data.totals.total_assets'), 0.01);
    }

    public function test_the_export_country_must_match_the_entitys_country(): void
    {
        $this->actingAs($this->admin)
            ->getJson($this->exportUrl($this->entityForeign, 'SG'))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
