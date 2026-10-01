<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
use App\Models\StatutoryReturnFiling;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxPeriodClosingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #278 (F4): a locked tax period blocks tax-relevant postings dated inside it
 * — including supply-only documents with no GL tax line — while untaxed
 * postings remain unaffected.
 */
class TaxPeriodLockScopeTest extends TenantTestCase
{
    private User $user;

    private ChartOfAccount $cash;

    private ChartOfAccount $expense;

    private ChartOfAccount $ap;

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

        $this->cash = ChartOfAccount::factory()->create(['code' => 'PL-CASH-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $this->expense = ChartOfAccount::factory()->create(['code' => 'PL-EXP-'.Str::upper(Str::random(4)), 'type' => 'expense', 'is_active' => true]);
        $this->ap = ChartOfAccount::factory()->create(['code' => 'PL-AP-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);
        $this->revenue = ChartOfAccount::factory()->create(['code' => 'PL-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true]);
        $this->gst = ChartOfAccount::factory()->create(['code' => 'PL-GST-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);

        Tax::query()->firstOrCreate(['code' => 'PL-GST-9'], ['name' => 'PL GST 9', 'type' => 'gst', 'rate' => 9, 'is_active' => true]);
        $this->taxRuleId = (int) Tax::query()->where('code', 'PL-GST-9')->value('id');
    }

    private function taxedInvoiceRequest(?int $legalEntityId = null, ?string $postingDate = null): PostingRequest
    {
        return new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: $postingDate ?? now()->toDateString(),
            description: 'Taxed invoice',
            legalEntityId: $legalEntityId,
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $this->cash->id, debit: 109.0, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenue->id, debit: 0.0, credit: 100.0),
                new PostingRequestLine(accountMappingKey: 'sales_tax_payable', accountId: $this->gst->id, debit: 0.0, credit: 9.0, taxCode: 'PL-GST-9', taxId: $this->taxRuleId),
            ],
            userId: $this->user->id,
            taxDeterminationResult: [
                'jurisdiction' => 'SG', 'tax_amount' => 9.0,
                'tax_lines' => [[
                    'tax_code' => 'PL-GST-9', 'taxable_base' => 100.0, 'tax_rate' => 9.0,
                    'tax_amount' => 9.0, 'treatment' => 'standard_rated',
                ]],
            ],
        );
    }

    private function makeEntity(int $id): void
    {
        $groupCode = 'PLG-'.Str::upper(Str::random(6));
        DB::table('entity_groups')->insert(['name' => 'PL group', 'code' => $groupCode, 'created_at' => now(), 'updated_at' => now()]);
        $groupId = (int) DB::table('entity_groups')->where('code', $groupCode)->value('id');
        DB::table('legal_entities')->insert([
            'id' => $id, 'group_id' => $groupId, 'name' => 'PL entity '.$id,
            'code' => 'PLE-'.Str::upper(Str::random(6)), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function supplyOnlyRequest(): PostingRequest
    {
        return new PostingRequest(
            documentType: 'bill',
            documentId: null,
            postingDate: now()->toDateString(),
            description: 'Zero-rated supply into locked period',
            lines: [
                new PostingRequestLine(accountMappingKey: 'general_expense', accountId: $this->expense->id, debit: 50.0, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'accounts_payable', accountId: $this->ap->id, debit: 0.0, credit: 50.0),
            ],
            userId: $this->user->id,
            taxDeterminationResult: [
                'jurisdiction' => 'SG', 'tax_amount' => 0.0,
                'tax_lines' => [[
                    'tax_code' => 'PL-ZERO', 'taxable_base' => 50.0, 'tax_rate' => 0.0,
                    'tax_amount' => 0.0, 'treatment' => 'zero_rated',
                ]],
            ],
        );
    }

    private function untaxedJournalRequest(): PostingRequest
    {
        return new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: now()->toDateString(),
            description: 'Untaxed posting into locked period',
            lines: [
                new PostingRequestLine(accountMappingKey: 'general_expense', accountId: $this->expense->id, debit: 20.0, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $this->cash->id, debit: 0.0, credit: 20.0),
            ],
            userId: $this->user->id,
        );
    }

    public function test_locked_period_blocks_supply_only_documents_but_not_untaxed_postings(): void
    {
        $period = now()->format('Y-m');
        app(TaxPeriodClosingService::class)->closePeriod($period, (int) tenant('id'));

        $blocked = app(PostingEngineInterface::class)->post($this->supplyOnlyRequest());
        $this->assertFalse($blocked->success, 'A supply-only document changes the return and must be blocked in a locked period.');
        $this->assertStringContainsString('TAX_PERIOD_LOCKED', (string) $blocked->error);

        $untaxed = app(PostingEngineInterface::class)->post($this->untaxedJournalRequest());
        $this->assertTrue($untaxed->success, 'Untaxed postings must be unaffected by a tax lock: '.($untaxed->error ?? ''));
    }

    public function test_entity_scoped_lock_does_not_block_other_entities(): void
    {
        $this->makeEntity(7);
        $this->makeEntity(8);

        $period = now()->format('Y-m');
        app(TaxPeriodClosingService::class)->closePeriod($period, (int) tenant('id'), 7, 'SG');

        $blocked = app(PostingEngineInterface::class)->post($this->taxedInvoiceRequest(7));
        $this->assertFalse($blocked->success, 'Entity 7 postings must be blocked by entity 7 lock.');
        $this->assertStringContainsString('TAX_PERIOD_LOCKED', (string) $blocked->error);

        $allowed = app(PostingEngineInterface::class)->post($this->taxedInvoiceRequest(8));
        $this->assertTrue($allowed->success, 'Entity 8 must remain open: '.($allowed->error ?? ''));

        // A tenant-wide lock (NULL entity) blocks entity 7 too.
        app(TaxPeriodClosingService::class)->closePeriod($period, (int) tenant('id'));
        $blockedWide = app(PostingEngineInterface::class)->post($this->taxedInvoiceRequest(7));
        $this->assertFalse($blockedWide->success);
    }

    public function test_adjustment_document_posts_into_the_next_open_period(): void
    {
        $period = now()->format('Y-m');
        app(TaxPeriodClosingService::class)->closePeriod($period, (int) tenant('id'));

        $nextMonth = now()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $adjustment = app(PostingEngineInterface::class)->post($this->taxedInvoiceRequest(null, $nextMonth));

        $this->assertTrue($adjustment->success, 'A dated adjustment in the next open period must post: '.($adjustment->error ?? ''));
    }

    public function test_reopen_requires_reason_and_elevated_authority_and_flags_amendment(): void
    {
        $period = now()->format('Y-m');
        app(TaxPeriodClosingService::class)->closePeriod($period, (int) tenant('id'));

        StatutoryReturnFiling::create([
            'tenant_id' => tenant('id'), 'country' => 'SG', 'form' => 'GST-F5',
            'period' => $period, 'entity_key' => 0, 'filing_version' => 1,
            'state' => 'FILED', 'boxes' => [], 'filed_at' => now(),
        ]);

        // No reason -> 422.
        $this->actingAs($this->user)->postJson("/api/v1/accounting/tax-periods/{$period}/reopen", [])
            ->assertStatus(422);

        // No tenant role -> 403.
        $stranger = User::factory()->create(['email' => Str::lower(Str::random(4)).'-'.uniqid().'@taxfix.test']);
        $this->actingAs($stranger)->postJson("/api/v1/accounting/tax-periods/{$period}/reopen", ['reason' => 'Late supplier invoice'])
            ->assertStatus(403);

        // Elevated role + reason -> reopened, baseline flagged for amendment.
        $ok = $this->actingAs($this->user)->postJson("/api/v1/accounting/tax-periods/{$period}/reopen", ['reason' => 'Late supplier invoice']);
        $ok->assertStatus(200);
        $this->assertSame(1, (int) $ok->json('filings_flagged_for_amendment'));

        $this->assertSame('AMENDMENT_REQUIRED', StatutoryReturnFiling::first()->state);
        $this->assertTrue(DB::table('audit_logs')->where('operation', 'tax_period_reopened')->exists());
    }

    public function test_readiness_fact_reflects_lock_filing_and_version(): void
    {
        $period = now()->format('Y-m');

        $before = app(\Modules\AccountingCountry\Services\TaxReportingService::class)->readiness($period);
        $this->assertFalse($before['locked']);
        $this->assertTrue($before['unfiled']);
        $this->assertNotEmpty($before['version']);

        app(TaxPeriodClosingService::class)->closePeriod($period, (int) tenant('id'));
        StatutoryReturnFiling::create([
            'tenant_id' => tenant('id'), 'country' => 'SG', 'form' => 'GST-F5',
            'period' => $period, 'entity_key' => 0, 'filing_version' => 1,
            'state' => 'FILED', 'boxes' => [], 'filed_at' => now(),
        ]);

        $after = app(\Modules\AccountingCountry\Services\TaxReportingService::class)->readiness($period);
        $this->assertTrue($after['locked']);
        $this->assertTrue($after['filed']);
        $this->assertSame('FILED', $after['filing_state']);
        $this->assertNotSame($before['version'], $after['version'], 'The readiness version must change with the fact.');

        $this->actingAs($this->user)->getJson("/api/v1/accounting/tax-report/readiness?period={$period}")
            ->assertStatus(200)
            ->assertJsonPath('data.locked', true);
    }
}
