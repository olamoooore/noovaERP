<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Services\TaxReportingService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #860 (RP6) — the trail side (TaxReturnAggregator) and the GL side of
 * the tax reports used two different lifecycle rules: the aggregator kept
 * reversed originals (the reversal engine writes signed offsetting rows)
 * while the GL queries excluded the original AND its posted reversal, so a
 * reversal made the trail and the GL disagree by the reversal amount.
 *
 * One shared scope is asserted here: originals kept, signed reversal rows
 * counted, only voided/superseded excluded.
 */
class TaxReportingReversalConsistencyTest extends TenantTestCase
{
    private User $user;

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

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        $this->cash = ChartOfAccount::factory()->create(['code' => 'RC-CASH-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $this->revenue = ChartOfAccount::factory()->create(['code' => 'RC-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true]);
        $this->gst = ChartOfAccount::factory()->create(['code' => 'RC-GST-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);

        AccountMapping::updateOrCreate(['mapping_key' => 'sales_tax_payable'], ['account_id' => $this->gst->id, 'is_active' => true]);

        Tax::query()->firstOrCreate(['code' => 'RC-GST-9'], ['name' => 'RC GST 9', 'type' => 'gst', 'rate' => 9, 'is_active' => true]);
        $this->taxRuleId = (int) Tax::query()->where('code', 'RC-GST-9')->value('id');
    }

    private function postTaxedInvoice(string $date): int
    {
        $result = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: $date,
            description: 'RC taxed invoice',
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $this->cash->id, debit: 109.0, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenue->id, debit: 0.0, credit: 100.0),
                new PostingRequestLine(accountMappingKey: 'sales_tax_payable', accountId: $this->gst->id, debit: 0.0, credit: 9.0, taxCode: 'RC-GST-9', taxId: $this->taxRuleId),
            ],
            userId: $this->user->id,
            taxDeterminationResult: [
                'id' => (string) Str::uuid(),
                'jurisdiction' => 'SG', 'tax_amount' => 9.0,
                'tax_lines' => [[
                    'tax_code' => 'RC-GST-9', 'taxable_base' => 100.0, 'tax_rate' => 9.0,
                    'tax_amount' => 9.0, 'treatment' => 'standard_rated',
                ]],
            ],
        ));

        $this->assertTrue($result->success, $result->error ?? '');

        return (int) $result->journalEntry->id;
    }

    private function reverse(int $entryId, string $date): void
    {
        $result = app(PostingEngineInterface::class)->reverse(new ReversalRequest(
            journalEntryId: $entryId,
            reversalDate: $date,
            reason: 'reversal-consistency test',
            reasonCode: 'TEST',
            actorUserId: $this->user->id,
            tenantId: tenant('id'),
        ));

        $this->assertTrue($result->success, $result->error ?? '');
    }

    public function test_post_and_reverse_in_one_period_leave_trail_gl_and_return_at_zero(): void
    {
        $entryId = $this->postTaxedInvoice('2026-09-15');
        $this->reverse($entryId, '2026-09-20');

        $service = app(TaxReportingService::class);

        $recon = $service->reconciliation('2026-09');
        $this->assertTrue($recon['balanced'], 'reconciliation must be balanced after a same-period reversal');
        $this->assertSame(0.0, (float) $recon['total_abs_variance']);

        $row = $recon['by_tax_code'][0] ?? [];
        $this->assertSame(0.0, (float) ($row['trail_tax'] ?? -1), 'the trail must net to zero');
        $this->assertSame(0.0, (float) ($row['gl_net'] ?? -1), 'the GL must net to zero');

        $journal = $service->journal('2026-09', '2026-09');
        $this->assertSame(0.0, (float) $journal['totals']['output_tax']);
        $this->assertSame(0.0, (float) $journal['totals']['net']);

        $return = $service->returnDetail('2026-09', null, 'output');
        $this->assertSame(0.0, (float) $return['totals']['tax_amount'], 'the return must net to zero');

        $codes = collect($service->exceptions('2026-09'))->pluck('code')->all();
        $this->assertNotContains('RECONCILIATION_VARIANCE', $codes);
    }

    public function test_a_cross_period_reversal_reconciles_in_both_periods(): void
    {
        $entryId = $this->postTaxedInvoice('2026-09-15');
        $this->reverse($entryId, '2026-10-02');

        $service = app(TaxReportingService::class);

        // September keeps the original — the signed reversal is October activity.
        $september = $service->reconciliation('2026-09');
        $sept = $september['by_tax_code'][0] ?? [];
        $this->assertSame(9.0, (float) ($sept['trail_tax'] ?? 0), 'September trail keeps the original');
        $this->assertSame(9.0, (float) ($sept['gl_net'] ?? 0), 'September GL must match the trail');
        $this->assertTrue($september['balanced'], 'September must not show a false variance');
        $this->assertSame(
            [],
            array_values(array_filter(
                collect($service->exceptions('2026-09'))->pluck('code')->all(),
                fn ($code) => $code === 'RECONCILIATION_VARIANCE'
            )),
            'September must not raise RECONCILIATION_VARIANCE'
        );

        // October carries the reversal.
        $october = $service->reconciliation('2026-10');
        $oct = $october['by_tax_code'][0] ?? [];
        $this->assertSame(-9.0, (float) ($oct['trail_tax'] ?? 0), 'October trail carries the signed reversal');
        $this->assertSame(-9.0, (float) ($oct['gl_net'] ?? 0), 'October GL must match the trail');
        $this->assertTrue($october['balanced'], 'October must be balanced');
    }
}
