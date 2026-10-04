<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
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
 * Issue #857 (RP5) — filings key activity by legal entity while a filing made
 * without one was stored as entity_key 0, so a single-entity tenant's filing
 * never closed its activity and the period stayed open forever.
 *
 * Real HTTP through the router for the filing; filings() for the tracker.
 */
class TaxFilingEntityKeyTest extends TenantTestCase
{
    private User $user;

    private ChartOfAccount $cash;

    private ChartOfAccount $revenue;

    private ChartOfAccount $gst;

    private int $taxRuleId;

    private int $entityId;

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

        $this->cash = ChartOfAccount::factory()->create(['code' => 'FK-CASH-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $this->revenue = ChartOfAccount::factory()->create(['code' => 'FK-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true]);
        $this->gst = ChartOfAccount::factory()->create(['code' => 'FK-GST-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);

        AccountMapping::updateOrCreate(['mapping_key' => 'sales_tax_payable'], ['account_id' => $this->gst->id, 'is_active' => true]);

        Tax::query()->firstOrCreate(['code' => 'FK-GST-9'], ['name' => 'FK GST 9', 'type' => 'gst', 'rate' => 9, 'is_active' => true]);
        $this->taxRuleId = (int) Tax::query()->where('code', 'FK-GST-9')->value('id');

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Group', 'code' => 'FKG-'.Str::upper(Str::random(4)),
            'base_currency' => 'SGD', 'fiscal_year_start' => '01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Singapore Pte Ltd', 'code' => 'FKE-'.Str::upper(Str::random(4)),
            'country_code' => 'SG', 'base_currency' => 'SGD', 'fiscal_year_start' => '01-01',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function postTaxedInvoice(string $date): int
    {
        $result = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: $date,
            description: 'FK taxed invoice',
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $this->cash->id, debit: 109.0, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenue->id, debit: 0.0, credit: 100.0),
                new PostingRequestLine(accountMappingKey: 'sales_tax_payable', accountId: $this->gst->id, debit: 0.0, credit: 9.0, taxCode: 'FK-GST-9', taxId: $this->taxRuleId),
            ],
            userId: $this->user->id,
            taxDeterminationResult: [
                'id' => (string) Str::uuid(),
                'jurisdiction' => 'SG', 'tax_amount' => 9.0,
                'tax_lines' => [[
                    'tax_code' => 'FK-GST-9', 'taxable_base' => 100.0, 'tax_rate' => 9.0,
                    'tax_amount' => 9.0, 'treatment' => 'standard_rated',
                ]],
            ],
        ));

        $this->assertTrue($result->success, $result->error ?? '');

        return (int) $result->journalEntry->id;
    }

    public function test_filing_without_entity_defaults_to_the_single_entity_and_closes_its_activity(): void
    {
        $this->postTaxedInvoice('2026-09-15');

        $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/statutory-returns/SG/2026-09/file')
            ->assertStatus(201);

        $filing = DB::table('statutory_return_filings')->where('period', '2026-09')->first();
        $this->assertNotNull($filing, 'the filing must be stored');
        $this->assertSame($this->entityId, (int) $filing->entity_key, 'a single-entity tenant files under that entity, not key 0');

        $tracker = app(TaxReportingService::class)->filings();
        $this->assertSame([], $tracker['open_periods'], 'the filing must close the entity activity; no orphan open period');
    }

    public function test_a_legacy_key_zero_filing_closes_the_single_entitys_activity(): void
    {
        $entryId = $this->postTaxedInvoice('2026-08-15');
        // The activity is attributed to the legal entity (the normal case once
        // ResolveEntityContext is used) while the filing legacy key is 0.
        DB::table('journal_entries')->where('id', $entryId)->update(['legal_entity_id' => $this->entityId]);

        // A filing written before #736 required the entity — entity_key 0.
        DB::table('statutory_return_filings')->insert([
            'country' => 'SG', 'form' => 'GST F5', 'period' => '2026-08', 'entity_key' => 0,
            'boxes' => json_encode([]), 'filed_by' => $this->user->id, 'filed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tracker = app(TaxReportingService::class)->filings();

        $this->assertSame(
            [],
            $tracker['open_periods'],
            'a legacy tenant-wide filing must close the single entity activity'
        );
    }
}
