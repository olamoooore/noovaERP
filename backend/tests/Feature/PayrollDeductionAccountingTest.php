<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\Tenant;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\HRM\Models\Employee;
use Modules\HRM\Models\Payroll;
use Modules\HRM\Models\PayrollItem;
use Modules\HRM\Models\PayrollRun;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #791 (Y3): the payroll accrual must reflect the payslip's deduction lines.
 *
 *  - unpaid leave (category leave_deduction) reduces what the employee earned,
 *    so it lowers salary expense and never becomes a third-party liability;
 *  - every other deduction is an amount withheld from the employee's pay and
 *    owed onward, so it is credited to Payroll Deductions Payable
 *    (`payroll_deductions_payable` account mapping) instead of being netted
 *    into Payroll Payable;
 *  - when a withholding exists but the deductions-payable mapping is missing,
 *    approval fails closed with a clear reason instead of silently posting the
 *    wrong entry.
 *
 * Router-level: approve/mark-paid through middleware -> controller.
 */
class PayrollDeductionAccountingTest extends TenantTestCase
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
                'name' => 'Payroll Deduction Accounting Tenant',
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

    public function test_withheld_deductions_credit_their_own_liability_and_expense_keeps_gross(): void
    {
        $deductionsPayable = $this->configureDeductionsPayableAccount();
        $this->addPayrollWithDeductionItems(['custom' => 500]);

        $this->actingAs($this->approver)
            ->postJson("/api/v1/hrm/payroll-runs/{$this->payrollRun->id}/approve")
            ->assertOk();

        $entry = $this->accrualEntry();

        $expenseId = ChartOfAccount::where('code', '5300')->value('id');
        $payableId = ChartOfAccount::where('code', '2300')->value('id');
        $this->assertNotNull($expenseId);
        $this->assertNotNull($payableId);

        $expenseLine = $entry->lines->firstWhere('account_id', $expenseId);
        $this->assertNotNull($expenseLine, 'The accrual must debit Salary Expense.');
        $this->assertSame(
            5000.0,
            (float) $expenseLine->debit,
            'A withheld deduction does not reduce earned gross: expense must stay at total_gross.'
        );
        $this->assertSame(0.0, (float) $expenseLine->credit);

        $payableLine = $entry->lines->firstWhere('account_id', $payableId);
        $this->assertNotNull($payableLine, 'The accrual must credit Payroll Payable.');
        $this->assertSame(
            4500.0,
            (float) $payableLine->credit,
            'Employees are owed total_net — Payroll Payable is credited net, not gross.'
        );

        $deductionsLine = $entry->lines->firstWhere('account_id', $deductionsPayable->id);
        $this->assertNotNull(
            $deductionsLine,
            'Withheld deductions must be credited to Payroll Deductions Payable, not netted into Payroll Payable.'
        );
        $this->assertSame(
            500.0,
            (float) $deductionsLine->credit,
            'The withholding liability must carry the withheld amount.'
        );
        $this->assertSame(0.0, (float) $deductionsLine->debit);

        $this->assertSame(
            round((float) $entry->lines->sum('debit'), 2),
            round((float) $entry->lines->sum('credit'), 2),
            'The accrual entry must balance: expense(gross) = net payable + deductions liabilities.'
        );
    }

    public function test_unpaid_leave_reduces_earned_expense_and_never_becomes_a_liability(): void
    {
        $this->addPayrollWithDeductionItems(['leave_deduction' => 500]);

        $this->actingAs($this->approver)
            ->postJson("/api/v1/hrm/payroll-runs/{$this->payrollRun->id}/approve")
            ->assertOk();

        $entry = $this->accrualEntry();

        $expenseId = ChartOfAccount::where('code', '5300')->value('id');
        $payableId = ChartOfAccount::where('code', '2300')->value('id');

        $expenseLine = $entry->lines->firstWhere('account_id', $expenseId);
        $this->assertSame(
            4500.0,
            (float) $expenseLine->debit,
            'Unpaid leave reduces what was earned, so Salary Expense is net of the leave deduction.'
        );

        $payableLine = $entry->lines->firstWhere('account_id', $payableId);
        $this->assertSame(
            4500.0,
            (float) $payableLine->credit,
            'Unpaid leave is not owed to a third party — Payroll Payable carries total_net only.'
        );

        $this->assertCount(
            2,
            $entry->lines,
            'A leave-only run produces expense + net payable; no deductions-payable line.'
        );

        $this->actingAs($this->payer)
            ->postJson("/api/v1/hrm/payroll-runs/{$this->payrollRun->id}/mark-paid")
            ->assertOk();

        $paymentLink = JournalEntryLink::where('source_document_type', 'payroll_payment')
            ->where('source_document_id', $this->payrollRun->id)
            ->where('relation_type', 'post')
            ->first();
        $this->assertNotNull($paymentLink, 'Mark-paid must post the payment entry.');

        $payableBalance = (float) DB::table('journal_entry_lines')
            ->whereIn('journal_entry_id', [$entry->id, $paymentLink->journal_entry_id])
            ->where('account_id', $payableId)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS balance')
            ->value('balance');
        $this->assertSame(
            0.0,
            $payableBalance,
            'After approve -> pay, Payroll Payable for a leave-only run must be cleared to zero.'
        );
    }

    public function test_withheld_deduction_without_deductions_payable_mapping_fails_closed(): void
    {
        $this->addPayrollWithDeductionItems(['custom' => 500]);

        $this->assertFalse(
            AccountMapping::query()
                ->where('mapping_key', 'payroll_deductions_payable')
                ->where('is_active', true)
                ->exists(),
            'Precondition: no deductions-payable account is configured.'
        );

        $response = $this->actingAs($this->approver)
            ->postJson("/api/v1/hrm/payroll-runs/{$this->payrollRun->id}/approve");

        $response->assertStatus(422);
        $response->assertJsonPath('reason_code', 'PAYROLL_POSTING_FAILED');
        $this->assertStringContainsString(
            'payroll_deductions_payable',
            (string) $response->json('message'),
            'The error must name the mapping the tenant has to configure.'
        );

        $run = $this->payrollRun->fresh();
        $this->assertSame('calculated', $run->status, 'A failed accrual must not leave the run approved.');
        $this->assertNull($run->journal_entry_id);
        $this->assertFalse(
            JournalEntryLink::where('source_document_type', 'payroll_run')
                ->where('source_document_id', $this->payrollRun->id)
                ->exists(),
            'A failed posting must not leave an accrual journal link behind.'
        );
    }

    /**
     * Create a tenant "Payroll Deductions Payable" account + the mapping the
     * journal service resolves. The account is tenant configuration, not a
     * statutory account invented by the application.
     */
    private function configureDeductionsPayableAccount(): ChartOfAccount
    {
        $account = ChartOfAccount::create([
            'code' => '2310',
            'name' => 'Payroll Deductions Payable',
            'type' => 'liability',
            'subtype' => 'current_liability',
            'is_active' => true,
            'is_system' => false,
            'opening_balance' => 0,
        ]);

        AccountMapping::create([
            'mapping_key' => 'payroll_deductions_payable',
            'document_type' => 'payroll_run',
            'account_id' => $account->id,
            'is_active' => true,
        ]);

        return $account;
    }

    /**
     * @param  array<string, float>  $deductionItems  category => amount
     */
    private function addPayrollWithDeductionItems(array $deductionItems): void
    {
        $employee = Employee::create([
            'employee_number' => 'EMP-'.Str::upper(Str::random(6)),
            'name' => 'Deduction Tester',
            'email' => 'emp-'.uniqid().'@hrm.test',
            'status' => 'active',
        ]);

        $payroll = Payroll::create([
            'payroll_run_id' => $this->payrollRun->id,
            'employee_id' => $employee->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'base_monthly_salary' => 5000,
            'net_pay' => 4500,
            'status' => 'finalized',
            'generated_by' => $this->creator->id,
        ]);

        foreach ($deductionItems as $category => $amount) {
            PayrollItem::create([
                'payroll_id' => $payroll->id,
                'type' => 'deduction',
                'category' => $category,
                'amount' => $amount,
                'description' => "Test deduction ({$category})",
            ]);
        }
    }

    private function accrualEntry(): JournalEntry
    {
        $accrualLink = JournalEntryLink::where('source_document_type', 'payroll_run')
            ->where('source_document_id', $this->payrollRun->id)
            ->where('relation_type', 'post')
            ->first();

        $this->assertNotNull($accrualLink, 'Approval must post the payroll accrual entry.');

        $entry = JournalEntry::with('lines')->find($accrualLink->journal_entry_id);
        $this->assertNotNull($entry);

        return $entry;
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
