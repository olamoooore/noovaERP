<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed payroll-related chart of accounts, account mappings, and a PAYROLL journal.
 *
 * Accounts created:
 *   5300 — Salary Expense         (expense / operating_expense)
 *   2300 — Payroll Payable        (liability / current_liability)
 *
 * Mappings created:
 *   salary_expense  → 5300  (document_type: payroll_run)
 *   payroll_payable → 2300  (document_type: payroll_run)
 *
 * Journal created:
 *   PAYROLL — Payroll Journal
 */
return new class extends Migration
{
    public function up(): void
    {
        // Skip seeding if the Accounting module tables don't exist
        if (!Schema::hasTable('chart_of_accounts')) {
            return;
        }

        // ── Salary Expense account ──
        $salaryId = DB::table('chart_of_accounts')
            ->where('code', '5300')
            ->value('id');

        if (!$salaryId) {
            $salaryId = DB::table('chart_of_accounts')->insertGetId([
                'code'        => '5300',
                'name'        => 'Salary Expense',
                'type'        => 'expense',
                'subtype'     => 'operating_expense',
                'parent_id'   => DB::table('chart_of_accounts')->where('code', '5000')->value('id'),
                'description' => 'Salary and wages expense from payroll runs',
                'is_active'   => true,
                'is_system'   => true,
                'opening_balance' => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // ── Payroll Payable account ──
        $payableId = DB::table('chart_of_accounts')
            ->where('code', '2300')
            ->value('id');

        if (!$payableId) {
            $payableId = DB::table('chart_of_accounts')->insertGetId([
                'code'        => '2300',
                'name'        => 'Payroll Payable',
                'type'        => 'liability',
                'subtype'     => 'current_liability',
                'parent_id'   => DB::table('chart_of_accounts')->where('code', '2000')->value('id'),
                'description' => 'Accrued payroll payable to employees',
                'is_active'   => true,
                'is_system'   => true,
                'opening_balance' => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // ── Account mappings ──
        DB::table('account_mappings')->updateOrInsert(
            ['mapping_key' => 'salary_expense', 'document_type' => 'payroll_run'],
            ['account_id' => $salaryId, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        DB::table('account_mappings')->updateOrInsert(
            ['mapping_key' => 'payroll_payable', 'document_type' => 'payroll_run'],
            ['account_id' => $payableId, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        // ── Payroll journal ──
        $exists = DB::table('journals')->where('code', 'PAYROLL')->exists();

        if (!$exists) {
            DB::table('journals')->insert([
                'code'                     => 'PAYROLL',
                'name'                     => 'Payroll Journal',
                'type'                     => 'payroll',
                'default_debit_account_id' => $salaryId,
                'default_credit_account_id'=> $payableId,
                'sequence_prefix'          => 'PAY',
                'sequence_number'          => 0,
                'is_active'                => true,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('chart_of_accounts')) {
            return;
        }

        DB::table('journals')->where('code', 'PAYROLL')->delete();

        DB::table('account_mappings')
            ->where('document_type', 'payroll_run')
            ->whereIn('mapping_key', ['salary_expense', 'payroll_payable'])
            ->delete();

        DB::table('chart_of_accounts')->where('code', '2300')->delete();
        DB::table('chart_of_accounts')->where('code', '5300')->delete();
    }
};
