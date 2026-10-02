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
 * #281 F18: exception catalogue + close-blocking readiness. Reversal keeps
 * reconciliation balanced (R09 regression) and filing clears unfiled
 * activity; uncoded control-account movement is reported.
 */
class TaxReconciliationExceptionsTest extends TenantTestCase
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

        $this->cash = ChartOfAccount::factory()->create(['code' => 'EX-CASH-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $this->revenue = ChartOfAccount::factory()->create(['code' => 'EX-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true]);
        $this->gst = ChartOfAccount::factory()->create(['code' => 'EX-GST-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);

        AccountMapping::updateOrCreate(['mapping_key' => 'sales_tax_payable'], ['account_id' => $this->gst->id, 'is_active' => true]);

        Tax::query()->firstOrCreate(['code' => 'EX-GST-9'], ['name' => 'EX GST 9', 'type' => 'gst', 'rate' => 9, 'is_active' => true]);
        $this->taxRuleId = (int) Tax::query()->where('code', 'EX-GST-9')->value('id');
    }

    private function postTaxedInvoice(): int
    {
        $request = new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: now()->toDateString(),
            description: 'EX taxed invoice',
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $this->cash->id, debit: 109.0, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenue->id, debit: 0.0, credit: 100.0),
                new PostingRequestLine(accountMappingKey: 'sales_tax_payable', accountId: $this->gst->id, debit: 0.0, credit: 9.0, taxCode: 'EX-GST-9', taxId: $this->taxRuleId),
            ],
            userId: $this->user->id,
            taxDeterminationResult: [
                'id' => (string) Str::uuid(),
                'jurisdiction' => 'SG', 'tax_amount' => 9.0,
                'tax_lines' => [[
                    'tax_code' => 'EX-GST-9', 'taxable_base' => 100.0, 'tax_rate' => 9.0,
                    'tax_amount' => 9.0, 'treatment' => 'standard_rated',
                ]],
            ],
        );

        $result = app(PostingEngineInterface::class)->post($request);
        $this->assertTrue($result->success, $result->error ?? '');

        return (int) $result->journalEntry->id;
    }

    private function postUncodedMovement(): void
    {
        // Raw GL movement on the tax control account without a tax code —
        // exactly the manual-drift bucket the exception reports.
        $entry = \App\Models\JournalEntry::factory()->create([
            'status' => 'posted',
            'entry_date' => now()->toDateString(),
        ]);

        DB::table('journal_entry_lines')->insert([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->gst->id,
            'description' => 'Uncoded control-account movement',
            'debit' => 0,
            'credit' => 5,
            'tax_code' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_exception_catalogue_flags_unfiled_and_uncoded_then_clears_after_filing(): void
    {
        $entryId = $this->postTaxedInvoice();

        $period = now()->format('Y-m');
        $service = app(TaxReportingService::class);

        // Coded posting: unfiled activity only, no variance.
        $codes = collect($service->exceptions($period))->pluck('code')->all();
        $this->assertContains('UNFILED_PERIOD', $codes);
        $this->assertNotContains('RECONCILIATION_VARIANCE', $codes, 'Coded posting must reconcile.');

        // Full reversal keeps reconciliation balanced (R09/G-34 regression).
        $reversal = app(PostingEngineInterface::class)->reverse(new ReversalRequest(
            journalEntryId: $entryId,
            reversalDate: now()->toDateString(),
            reason: 'exception regression',
            reasonCode: 'TEST',
            actorUserId: $this->user->id,
            tenantId: tenant('id'),
        ));
        $this->assertTrue($reversal->success, $reversal->error ?? '');

        $codes = collect($service->exceptions($period))->pluck('code')->all();
        $this->assertNotContains('RECONCILIATION_VARIANCE', $codes, 'Reversal must not create a false variance.');

        // Uncoded control-account movement is reported (and is a true GL variance).
        $this->postUncodedMovement();
        $codes = collect($service->exceptions($period))->pluck('code')->all();
        $this->assertContains('UNCODED_TAX_ACCOUNT_MOVEMENT', $codes);

        $readiness = $service->readiness($period);
        $this->assertTrue($readiness['close_blocking'], 'Unfiled/uncoded exceptions must block close.');
        $this->assertContains('UNFILED_PERIOD', $readiness['blocking_exceptions']);

        $this->getJson("/api/v1/accounting/tax-report/exceptions?period={$period}")
            ->assertStatus(200)
            ->assertJsonPath('data.close_blocking', true);

        // File the period: unfiled clears (uncoded movement remains).
        $period2 = now()->format('Y-m');
        $this->postJson("/api/v1/accounting/statutory-returns/SG/{$period2}/file")->assertStatus(201);

        $codes = collect($service->exceptions($period))->pluck('code')->all();
        $this->assertNotContains('UNFILED_PERIOD', $codes);
    }
}
