<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRD Phase 3 (R1.1 + R4.2 + D8): recoverability on the rule, and the split of
 * the tax control accounts.
 *
 *  - `taxes.creditable` / `taxes.blocked_expense_account_id`: whether input
 *    tax on this rule can be claimed (SG GST) or must be expensed (MY SST).
 *  - Repoints the `input_tax` mapping from the shared 2200 (D8) to a distinct
 *    asset account 2210, and seeds 5210 (input tax blocked, expense) and
 *    2220 (WHT payable, liability). Historical postings are untouched —
 *    only NEW postings land on the split accounts; the per-code tax report
 *    keeps reconciling across the cutover because it classifies by rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            if (! Schema::hasColumn('taxes', 'creditable')) {
                $table->boolean('creditable')->default(true)->after('treatment');
            }

            if (! Schema::hasColumn('taxes', 'blocked_expense_account_id')) {
                $table->unsignedBigInteger('blocked_expense_account_id')->nullable()->after('creditable');
            }
        });

        foreach (
            [
                ['input_tax', '2210', 'Input Tax (Recoverable)', 'asset'],
                ['input_tax_blocked', '5210', 'Input Tax Blocked (Expense)', 'expense'],
                ['wht_payable', '2220', 'Withholding Tax Payable', 'liability'],
            ] as [$key, $code, $name, $type]
        ) {
            $account = DB::table('chart_of_accounts')->where('code', $code)->whereNull('deleted_at')->first();

            if (! $account) {
                $id = DB::table('chart_of_accounts')->insertGetId([
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'account_type' => $type,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $account = (object) ['id' => $id];
            }

            $existing = DB::table('account_mappings')->where('mapping_key', $key)->whereNull('document_type')->first();

            if ($existing) {
                if ($key === 'input_tax' && (int) $existing->account_id !== (int) $account->id) {
                    DB::table('account_mappings')->where('id', $existing->id)->update([
                        'account_id' => $account->id,
                        'updated_at' => now(),
                    ]);
                }
            } else {
                DB::table('account_mappings')->insert([
                    'mapping_key' => $key,
                    'account_id' => $account->id,
                    'document_type' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Forward-only: columns and mappings remain (harmless if rolled back).
    }
};
