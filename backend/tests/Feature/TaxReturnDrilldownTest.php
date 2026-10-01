<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Services\TaxReportingService;
use Modules\AccountingCountry\Services\TaxReturnAggregator;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #280 F15: return-box drill-down shares the aggregator's row path — the sum
 * of drill-down rows equals the box value, zero-rated supplies appear, entity
 * scoping and reports.view are enforced.
 */
class TaxReturnDrilldownTest extends TenantTestCase
{
    private User $user;

    private User $viewer;

    private ChartOfAccount $cash;

    private ChartOfAccount $revenue;

    private ChartOfAccount $gst;

    private int $taxRuleId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->user->assignRole('Admin');
        $this->viewer = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->viewer->assignRole('Viewer');

        foreach ([[$this->user, 'admin'], [$this->viewer, 'viewer']] as [$u, $role]) {
            DB::table('user_company_access')->insert([
                'user_id' => $u->id, 'tenant_id' => $tenant->id, 'role' => $role,
                'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->cash = ChartOfAccount::factory()->create(['code' => 'DD-CASH-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $this->revenue = ChartOfAccount::factory()->create(['code' => 'DD-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true]);
        $this->gst = ChartOfAccount::factory()->create(['code' => 'DD-GST-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);

        Tax::query()->firstOrCreate(['code' => 'DD-GST-9'], ['name' => 'DD GST 9', 'type' => 'gst', 'rate' => 9, 'is_active' => true]);
        $this->taxRuleId = (int) Tax::query()->where('code', 'DD-GST-9')->value('id');
    }

    private function postTaxedInvoice(?int $legalEntityId = null): void
    {
        $request = new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: now()->toDateString(),
            description: 'Drill-down taxed invoice',
            legalEntityId: $legalEntityId,
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $this->cash->id, debit: 109.0, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenue->id, debit: 0.0, credit: 100.0),
                new PostingRequestLine(accountMappingKey: 'sales_tax_payable', accountId: $this->gst->id, debit: 0.0, credit: 9.0, taxCode: 'DD-GST-9', taxId: $this->taxRuleId),
            ],
            userId: $this->user->id,
            taxDeterminationResult: [
                'jurisdiction' => 'SG', 'tax_amount' => 9.0,
                'tax_lines' => [[
                    'tax_code' => 'DD-GST-9', 'taxable_base' => 100.0, 'tax_rate' => 9.0,
                    'tax_amount' => 9.0, 'treatment' => 'standard_rated',
                ]],
            ],
        );

        $this->assertTrue(app(PostingEngineInterface::class)->post($request)->success);
    }

    private function postZeroRatedSupply(): void
    {
        $expense = ChartOfAccount::factory()->create(['code' => 'DD-EXP-'.Str::upper(Str::random(4)), 'type' => 'expense', 'is_active' => true]);
        $ap = ChartOfAccount::factory()->create(['code' => 'DD-AP-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);

        $request = new PostingRequest(
            documentType: 'bill',
            documentId: null,
            postingDate: now()->toDateString(),
            description: 'Drill-down zero-rated supply',
            lines: [
                new PostingRequestLine(accountMappingKey: 'general_expense', accountId: $expense->id, debit: 50.0, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'accounts_payable', accountId: $ap->id, debit: 0.0, credit: 50.0),
            ],
            userId: $this->user->id,
            taxDeterminationResult: [
                'jurisdiction' => 'SG', 'tax_amount' => 0.0,
                'tax_lines' => [[
                    'tax_code' => 'DD-ZERO', 'taxable_base' => 50.0, 'tax_rate' => 0.0,
                    'tax_amount' => 0.0, 'treatment' => 'zero_rated',
                ]],
            ],
        );

        $this->assertTrue(app(PostingEngineInterface::class)->post($request)->success, 'Zero-rated bill posting must succeed.');
    }

    public function test_drilldown_sum_equals_the_box_value_and_exposes_supplies(): void
    {
        $this->postTaxedInvoice();
        $this->postZeroRatedSupply();

        $period = now()->format('Y-m');
        $aggregate = app(TaxReturnAggregator::class)->aggregate($period);
        $boxTax = round(array_sum(array_column($aggregate['output'], 'tax_amount')), 2);

        $detail = app(TaxReportingService::class)->returnDetail($period, null, 'output');

        $this->assertSame($boxTax, $detail['totals']['tax_amount'], 'sum(drilldown) must equal the output box tax to the cent.');

        $supply = collect($detail['rows'])->firstWhere('tax_code', 'DD-ZERO');
        $this->assertNotNull($supply, 'Zero-rated supplies must appear in the box drill-down.');
        $this->assertSame(0.0, (float) $supply['tax_amount']);
        $this->assertEqualsWithDelta(50.0, (float) $supply['taxable_amount'], 0.001);
        $this->assertSame('output', $supply['direction']);

        $taxed = collect($detail['rows'])->firstWhere('tax_code', 'DD-GST-9');
        $this->assertNotNull($taxed['journal_entry_id'] ?? null, 'Drill-down rows carry the journal entry.');
        $this->assertArrayHasKey('tax_determination_id', $taxed);

        $filtered = app(TaxReportingService::class)->returnDetail($period, null, 'output', 'DD-GST-9');
        $this->assertCount(1, $filtered['rows']);
        $this->assertSame('DD-GST-9', $filtered['rows'][0]['tax_code']);
    }

    public function test_drilldown_respects_entity_scoping_and_reports_view_permission(): void
    {
        $groupCode = 'DDG-'.Str::upper(Str::random(6));
        DB::table('entity_groups')->insert([
            'name' => 'DD group', 'code' => $groupCode,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $groupId = (int) DB::table('entity_groups')->where('code', $groupCode)->value('id');
        DB::table('legal_entities')->insert([
            'id' => 7, 'group_id' => $groupId, 'name' => 'DD entity', 'code' => 'DDE-'.Str::upper(Str::random(6)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postTaxedInvoice();
        $this->postTaxedInvoice(legalEntityId: 7);

        $period = now()->format('Y-m');
        $scoped = app(TaxReportingService::class)->returnDetail($period, 7, 'output');

        $this->assertCount(1, $scoped['rows'], 'Entity scoping must filter to the requested legal entity.');
        $adminResponse = $this->actingAs($this->user)->getJson("/api/v1/accounting/tax-report/return-detail?period={$period}&direction=output&entity_id=7");
        $adminResponse->assertStatus(200);
        $this->assertSame(
            $scoped['totals']['tax_amount'],
            round((float) $adminResponse->json('data.totals.tax_amount'), 2),
        );

        // A user with no tenant role access must be rejected.
        $stranger = User::factory()->create(['email' => Str::lower(Str::random(4)).'-'.uniqid().'@taxfix.test']);
        $denied = $this->actingAs($stranger)->getJson("/api/v1/accounting/tax-report/return-detail?period={$period}");
        $denied->assertStatus(403);
    }
}
