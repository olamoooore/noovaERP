<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\CompanySettings;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\Tenant;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\HRM\Models\PayrollRun;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #793 (Y4): "Mark paid" must post the PAYMENT, not the accrual, in the same
 * transaction as the status change, and a posting failure must not leave the
 * run marked paid.
 *
 * Router-level: postJson through middleware -> controller.
 *
 * Acceptance:
 *  - approve posts the accrual (DR salary expense / CR payroll payable);
 *  - mark-paid posts the payment (DR payroll payable / CR bank) as its own
 *    document, leaving the run's Payroll Payable at zero;
 *  - a forced posting failure rolls back the status change and returns an
 *    error instead of a 200 with an accounting_warning.
 */
class PayrollMarkPaidAccountingTest extends TenantTestCase
{
    private User $creator;

    private User $approver;

    private User $payer;

    private string $tenantId;

    private PayrollRun $payrollRun;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => UserRole::ADMIN->spatieName(), 'guard_name' => 'web']);

        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'Payroll Mark Paid Accounting Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });

        // Three distinct admins so the SoD guard (creator != approver != payer)
        // is never the reason an assertion fails.
        $this->creator = $this->makeAdminUser();
        $this->approver = $this->makeAdminUser();
        $this->payer = $this->makeAdminUser();

        $this->payrollRun = PayrollRun::create([
            'reference' => 'PR-'.Str::upper(Str::random(6)),
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'status' => 'calculated',
            'total_gross' => 5000,
            'total_deductions' => 500,
            'total_net' => 4500,
            'currency' => 'MYR',
            'created_by' => $this->creator->id,
        ]);
    }

    public function test_approve_posts_the_accrual_and_mark_paid_clears_the_payable(): void
    {
        $this->actingAs($this->approver)
            ->postJson("/api/v1/hrm/payroll-runs/{$this->payrollRun->id}/approve")
            ->assertOk();

        $approved = $this->payrollRun->fresh();
        $this->assertSame('approved', $approved->status);
        $this->assertNotNull(
            $approved->journal_entry_id,
            'Approval must post the payroll accrual entry.'
        );

        $this->actingAs($this->payer)
            ->postJson("/api/v1/hrm/payroll-runs/{$this->payrollRun->id}/mark-paid")
            ->assertOk();

        $this->assertSame('paid', $this->payrollRun->fresh()->status);

        $accrualLink = JournalEntryLink::where('source_document_type', 'payroll_run')
            ->where('source_document_id', $this->payrollRun->id)
            ->where('relation_type', 'post')
            ->first();
        $this->assertNotNull($accrualLink, 'The approval accrual must be linked to the run.');

        $paymentLink = JournalEntryLink::where('source_document_type', 'payroll_payment')
            ->where('source_document_id', $this->payrollRun->id)
            ->where('relation_type', 'post')
            ->first();
        $this->assertNotNull(
            $paymentLink,
            'Marking paid must post a payment entry (payroll_payment), not the accrual.'
        );
        $this->assertNotSame(
            (int) $accrualLink->journal_entry_id,
            (int) $paymentLink->journal_entry_id,
            'The payment must be its own journal entry, not the accrual entry.'
        );

        $payableId = ChartOfAccount::where('code', '2300')->value('id');
        $this->assertNotNull($payableId, 'The Payroll Payable account (2300) must be seeded.');

        $payableBalance = (float) DB::table('journal_entry_lines')
            ->whereIn('journal_entry_id', [$accrualLink->journal_entry_id, $paymentLink->journal_entry_id])
            ->where('account_id', $payableId)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS balance')
            ->value('balance');
        $this->assertSame(
            0.0,
            $payableBalance,
            'After approve -> pay, Payroll Payable for the run must be cleared to zero.'
        );

        $paymentEntry = JournalEntry::with('lines')->find($paymentLink->journal_entry_id);
        $this->assertNotNull($paymentEntry);

        $payableLine = $paymentEntry->lines->firstWhere('account_id', $payableId);
        $this->assertNotNull($payableLine, 'The payment must debit Payroll Payable.');
        $this->assertGreaterThan(0, (float) $payableLine->debit);

        $bankLines = $paymentEntry->lines->where('account_id', '!=', $payableId)->values();
        $this->assertCount(1, $bankLines, 'The payment must credit exactly one bank account.');
        $this->assertGreaterThan(0, (float) $bankLines->first()->credit);
    }

    public function test_failed_payment_posting_leaves_the_run_approved(): void
    {
        $this->actingAs($this->approver)
            ->postJson("/api/v1/hrm/payroll-runs/{$this->payrollRun->id}/approve")
            ->assertOk();

        $accrualEntryId = $this->payrollRun->fresh()->journal_entry_id;
        $this->assertNotNull($accrualEntryId);

        // Force the bank leg of the payment to be unresolvable: clear the
        // configured default bank account and deactivate every `bank` mapping
        // so PostingEngine account resolution fails through its real path
        // (a still-existing, inactive mapping row also stops seedDefaults()
        // from silently re-creating it).
        CompanySettings::query()->update(['default_bank_account_id' => null]);

        $bankMapping = AccountMapping::where('mapping_key', 'bank')->first();
        if ($bankMapping) {
            $bankMapping->update(['is_active' => false]);
        } else {
            AccountMapping::create([
                'mapping_key' => 'bank',
                'document_type' => null,
                'account_id' => ChartOfAccount::query()->value('id'),
                'is_active' => false,
            ]);
        }

        if (Schema::hasTable('entity_account_roles') && Schema::hasTable('account_roles')) {
            DB::table('entity_account_roles')
                ->whereIn('account_role_id', function ($query) {
                    $query->select('id')->from('account_roles')->where('code', 'BANK_MAIN');
                })
                ->update(['is_active' => false]);
        }

        $this->assertFalse(
            (bool) DB::table('account_mappings')
                ->where('mapping_key', 'bank')
                ->where('is_active', true)
                ->exists(),
            'Precondition: no active bank mapping remains.'
        );

        $response = $this->actingAs($this->payer)
            ->postJson(
                "/api/v1/hrm/payroll-runs/{$this->payrollRun->id}/mark-paid",
                ['payment_date' => '2026-10-01']
            );

        $response->assertStatus(422);
        $response->assertJsonPath('reason_code', 'PAYROLL_POSTING_FAILED');

        $run = $this->payrollRun->fresh();
        $this->assertSame(
            'approved',
            $run->status,
            'A failed payment posting must not leave the run marked paid.'
        );
        $this->assertSame(
            (int) $accrualEntryId,
            (int) $run->journal_entry_id,
            'The accrual entry from approval must survive a failed payment posting.'
        );
        $this->assertFalse(
            JournalEntryLink::where('source_document_type', 'payroll_payment')
                ->where('source_document_id', $this->payrollRun->id)
                ->exists(),
            'A failed payment posting must not leave a payment journal link behind.'
        );
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::ADMIN->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(function (string $tenantId, string $key) use ($isEntitled) {
                return [
                    'key' => $key,
                    'is_entitled' => $isEntitled,
                    'source' => $isEntitled ? 'tier_included' : null,
                    'name' => strtoupper($key),
                ];
            });

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }
}
