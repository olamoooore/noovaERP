<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
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
}