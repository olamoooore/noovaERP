<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation schema consolidation and FK drift fix.
 *
 * Problems fixed:
 * 1. Two coexisting item tables: `reconciliation_items` (used by runtime model) and
 *    `bank_reconciliation_items` (created by a later migration, never used by code).
 *    This migration drops the unused `bank_reconciliation_items` and adds any
 *    missing columns to `reconciliation_items` that `bank_reconciliation_items` had.
 *
 * 2. `bank_reconciliations.bank_account_id` originally referenced `chart_of_accounts`
 *    (the old migration) but a later migration added a FK to `bank_accounts`.
 *    This migration normalizes the FK to reference `bank_accounts` only,
 *    and drops the stale `chart_of_accounts` FK if it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Drop the unused duplicate table ─────────────────────────────────
        if (Schema::hasTable('bank_reconciliation_items')) {
            // Check if the runtime model uses reconciliation_items instead
            // (it does — BankReconciliationService references ReconciliationItem model
            //  which maps to reconciliation_items table)
            Schema::dropIfExists('bank_reconciliation_items');
        }

        // ── 2. Add missing columns to reconciliation_items from bank_reconciliation_items ─
        // The later migration had some columns the original didn't. Add them if missing.
        if (Schema::hasTable('reconciliation_items')) {
            if (!Schema::hasColumn('reconciliation_items', 'bank_transaction_id')) {
                Schema::table('reconciliation_items', function (Blueprint $table) {
                    $table->unsignedBigInteger('bank_transaction_id')->nullable()->after('journal_entry_line_id');
                });
            }

            if (!Schema::hasColumn('reconciliation_items', 'match_type')) {
                Schema::table('reconciliation_items', function (Blueprint $table) {
                    $table->string('match_type', 50)->nullable()->after('bank_transaction_id');
                });
            }

            if (!Schema::hasColumn('reconciliation_items', 'match_confidence')) {
                Schema::table('reconciliation_items', function (Blueprint $table) {
                    $table->decimal('match_confidence', 5, 2)->nullable()->after('match_type');
                });
            }
        }

        // ── 3. Fix bank_reconciliations.bank_account_id FK ──────────────────────
        // Normalize to reference bank_accounts (not chart_of_accounts)
        if (Schema::hasTable('bank_reconciliations') && Schema::hasTable('bank_accounts')) {
            // Drop the old FK if it references chart_of_accounts
            $foreignKeyName = null;
            $foreignKeys = DB::select("
                SELECT conname
                FROM pg_constraint
                WHERE conrelid = to_regclass('bank_reconciliations')
                  AND contype = 'f'
            ");

            foreach ($foreignKeys as $fk) {
                $fkDetail = DB::selectOne("
                    SELECT pg_get_constraintdef(oid) as definition
                    FROM pg_constraint WHERE conname = ?
                ", [$fk->conname]);

                if ($fkDetail
                    && str_contains($fkDetail->definition, 'bank_account_id')
                    && str_contains($fkDetail->definition, 'chart_of_accounts')
                ) {
                    $foreignKeyName = $fk->conname;
                    break;
                }
            }

            if ($foreignKeyName) {
                Schema::table('bank_reconciliations', function (Blueprint $table) use ($foreignKeyName) {
                    $table->dropForeign($foreignKeyName);
                });
            }

            // Check if a FK to bank_accounts already exists
            $hasBankAccountsFK = false;
            $foreignKeys = DB::select("
                SELECT conname
                FROM pg_constraint
                WHERE conrelid = to_regclass('bank_reconciliations')
                  AND contype = 'f'
            ");

            foreach ($foreignKeys as $fk) {
                $fkDetail = DB::selectOne("
                    SELECT pg_get_constraintdef(oid) as definition
                    FROM pg_constraint WHERE conname = ?
                ", [$fk->conname]);

                if ($fkDetail && str_contains($fkDetail->definition, 'bank_account_id') && str_contains($fkDetail->definition, 'bank_accounts')) {
                    $hasBankAccountsFK = true;
                    break;
                }
            }

            if (!$hasBankAccountsFK) {
                Schema::table('bank_reconciliations', function (Blueprint $table) {
                    $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->cascadeOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        // Restore the unused table for rollback completeness (empty schema only)
        Schema::create('bank_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->foreignId('journal_entry_line_id')->nullable()->constrained('journal_entry_lines')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('type')->default('matched');
            $table->timestamps();
        });

        // Remove added columns
        if (Schema::hasTable('reconciliation_items')) {
            foreach (['match_confidence', 'match_type', 'bank_transaction_id'] as $col) {
                if (Schema::hasColumn('reconciliation_items', $col)) {
                    Schema::table('reconciliation_items', function (Blueprint $table) use ($col) {
                        $table->dropColumn($col);
                    });
                }
            }
        }
    }
};
