<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Preserve the exception audit when an unresolved legacy row blocks this migration. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('bank_reconciliation_account_migration_audits')) {
            Schema::create('bank_reconciliation_account_migration_audits', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('reconciliation_id');
                $table->unsignedBigInteger('old_value');
                $table->unsignedBigInteger('resolved_bank_account_id')->nullable();
                $table->string('resolution_method', 40);
                $table->uuid('deployment_id');
                $table->text('exception')->nullable();
                $table->timestamp('audited_at');
                $table->unique(['deployment_id', 'reconciliation_id']);
            });
        }

        $deploymentId = (string) Str::uuid();
        $unresolved = [];

        if (Schema::hasTable('bank_reconciliations')) {
            foreach (DB::table('bank_reconciliations')->select('id', 'bank_account_id')->orderBy('id')->get() as $row) {
                $direct = DB::table('bank_accounts')->where('id', $row->bank_account_id)->pluck('id');
                $byGl = DB::table('bank_accounts')->where('gl_account_id', $row->bank_account_id)->pluck('id');
                $resolved = null;
                $method = 'unresolved';
                $exception = null;

                if ($direct->count() === 1 && $byGl->isEmpty()) {
                    $resolved = (int) $direct->first();
                    $method = 'bank_account_id';
                } elseif ($direct->isEmpty() && $byGl->count() === 1) {
                    $resolved = (int) $byGl->first();
                    $method = 'chart_of_account_gl_link';
                } elseif ($direct->count() === 1 && $byGl->count() === 1 && (int) $direct->first() === (int) $byGl->first()) {
                    $resolved = (int) $direct->first();
                    $method = 'both_same_bank_account';
                } else {
                    // Numeric IDs can overlap: the stored value may identify both a
                    // bank_accounts row and an unrelated chart_of_accounts row. In
                    // that case, the reconciled GL lines are authoritative evidence.
                    $lineAccountIds = Schema::hasTable('reconciliation_items')
                        ? DB::table('reconciliation_items as ri')
                            ->join('journal_entry_lines as jel', 'jel.id', '=', 'ri.journal_entry_line_id')
                            ->where('ri.reconciliation_id', $row->id)
                            ->distinct()
                            ->pluck('jel.account_id')
                        : collect();

                    $candidateIds = $direct->merge($byGl)->unique()->values();
                    $lineBackedCandidates = $candidateIds->filter(function ($bankAccountId) use ($lineAccountIds) {
                        $glAccountId = DB::table('bank_accounts')->where('id', $bankAccountId)->value('gl_account_id');

                        return $lineAccountIds->isNotEmpty()
                            && $lineAccountIds->every(fn ($accountId) => (int) $accountId === (int) $glAccountId);
                    });

                    if ($lineBackedCandidates->count() === 1) {
                        $resolved = (int) $lineBackedCandidates->first();
                        $method = 'reconciliation_item_gl_link';
                    } else {
                        $exception = ($direct->isNotEmpty() || $byGl->isNotEmpty()) ? 'ambiguous' : 'orphaned';
                        $unresolved[] = (int) $row->id;
                    }
                }

                DB::table('bank_reconciliation_account_migration_audits')->insert([
                    'id' => (string) Str::uuid(), 'reconciliation_id' => $row->id,
                    'old_value' => $row->bank_account_id, 'resolved_bank_account_id' => $resolved,
                    'resolution_method' => $method, 'deployment_id' => $deploymentId,
                    'exception' => $exception, 'audited_at' => now(),
                ]);
            }

            if ($unresolved !== []) {
                throw new RuntimeException('Bank statement import disabled: unresolved bank reconciliation account references: '.implode(', ', $unresolved));
            }

            DB::transaction(function () use ($deploymentId) {
                $constraints = DB::select("SELECT conname FROM pg_constraint WHERE conrelid = to_regclass('bank_reconciliations') AND contype = 'f' AND pg_get_constraintdef(oid) LIKE 'FOREIGN KEY (bank_account_id)%'");
                foreach ($constraints as $constraint) {
                    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $constraint->conname)) {
                        throw new RuntimeException('Unsafe reconciliation constraint name encountered.');
                    }
                    DB::statement('ALTER TABLE bank_reconciliations DROP CONSTRAINT "'.$constraint->conname.'"');
                }
                DB::statement('UPDATE bank_reconciliations r SET bank_account_id = a.resolved_bank_account_id FROM bank_reconciliation_account_migration_audits a WHERE a.deployment_id = ? AND a.reconciliation_id = r.id', [$deploymentId]);
                DB::statement('ALTER TABLE bank_reconciliations ADD CONSTRAINT bank_reconciliations_bank_account_id_foreign FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT');
            });
        }

        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->string('file_name');
            $table->char('file_hash', 64);
            $table->string('storage_path');
            $table->string('file_type', 8);
            $table->string('status', 24)->default('uploaded');
            $table->date('statement_start_date')->nullable();
            $table->date('statement_end_date')->nullable();
            $table->decimal('opening_balance', 24, 8)->nullable();
            $table->decimal('closing_balance', 24, 8)->nullable();
            $table->char('currency_code', 3);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->jsonb('mapping')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->index(['bank_account_id', 'statement_end_date', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX bank_statement_imports_active_file_unique ON bank_statement_imports (bank_account_id, file_hash) WHERE status <> 'reversed'");

        Schema::create('bank_statement_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_id')->constrained('bank_statement_imports')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->char('transaction_key', 64);
            $table->unsignedInteger('source_row');
            $table->date('value_date')->nullable();
            $table->date('transaction_date');
            $table->time('transaction_time')->nullable();
            $table->text('description');
            $table->string('bank_reference')->nullable();
            $table->string('customer_reference')->nullable();
            $table->decimal('amount', 24, 8);
            $table->char('currency_code', 3);
            $table->decimal('running_balance', 24, 8)->nullable();
            $table->string('status', 24)->default('unmatched');
            $table->decimal('matched_amount', 24, 8)->default(0);
            $table->decimal('residual_amount', 24, 8);
            $table->boolean('needs_review')->default(false);
            $table->jsonb('raw_data');
            $table->timestamps();
            $table->index(['bank_account_id', 'transaction_date', 'status']);
            $table->index(['import_id', 'source_row']);
        });
        DB::statement("CREATE UNIQUE INDEX bank_statement_transactions_active_key_unique ON bank_statement_transactions (bank_account_id, transaction_key) WHERE status <> 'reversed'");

        Schema::create('bank_statement_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_statement_transaction_id')->constrained('bank_statement_transactions')->cascadeOnDelete();
            $table->string('allocation_type', 32);
            $table->string('external_reference');
            $table->decimal('amount', 24, 8)->nullable();
            $table->unsignedInteger('sequence');
            $table->jsonb('raw_data')->nullable();
            $table->unique(['bank_statement_transaction_id', 'allocation_type', 'external_reference', 'sequence'], 'bank_stmt_alloc_unique');
        });

        Schema::create('bank_statement_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_statement_transaction_id')->constrained('bank_statement_transactions')->restrictOnDelete();
            $table->foreignId('journal_entry_line_id')->constrained('journal_entry_lines')->restrictOnDelete();
            $table->foreignId('reconciliation_id')->nullable()->constrained('bank_reconciliations')->restrictOnDelete();
            $table->decimal('matched_amount', 24, 8);
            $table->string('match_type', 20);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->jsonb('match_reasons')->nullable();
            $table->string('status', 16)->default('proposed');
            $table->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('idempotency_key')->nullable();
            $table->timestamps();
            $table->unique('idempotency_key');
            $table->index(['bank_statement_transaction_id', 'status']);
        });

        Schema::table('reconciliation_items', function (Blueprint $table) {
            $table->foreignUuid('bank_statement_transaction_id')->nullable()->constrained('bank_statement_transactions')->restrictOnDelete();
            $table->foreignUuid('bank_statement_match_id')->nullable()->constrained('bank_statement_matches')->restrictOnDelete();
        });

        Schema::create('bank_reconciliation_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('reconciliation_id')->nullable()->constrained('bank_reconciliations')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->date('previous_statement_end_date')->nullable();
            $table->date('statement_start_date');
            $table->date('statement_end_date');
            $table->jsonb('import_ids');
            $table->decimal('opening_balance', 24, 8);
            $table->decimal('closing_balance', 24, 8);
            $table->char('currency_code', 3);
            $table->decimal('currency_tolerance', 24, 8);
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_reconciliation_reversals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('reconciliation_id')->constrained('bank_reconciliations')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at');
            $table->jsonb('snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_statement_match_id');
            $table->dropConstrainedForeignId('bank_statement_transaction_id');
        });
        // Schema-qualified: unqualified DROP INDEX can leak to public via search_path.
        $schema = DB::selectOne('SELECT current_schema() AS s')->s;
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".bank_statement_transactions_active_key_unique");
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".bank_statement_imports_active_file_unique");
        Schema::dropIfExists('bank_reconciliation_reversals');
        Schema::dropIfExists('bank_reconciliation_scopes');
        Schema::dropIfExists('bank_statement_matches');
        Schema::dropIfExists('bank_statement_allocations');
        Schema::dropIfExists('bank_statement_transactions');
        Schema::dropIfExists('bank_statement_imports');
        Schema::dropIfExists('bank_reconciliation_account_migration_audits');
    }
};
