<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('operation', 40);
            $table->string('idempotency_key', 100);
            $table->char('payload_hash', 64);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('processing');
            $table->string('response_type')->nullable();
            $table->string('response_id')->nullable();
            $table->jsonb('response')->nullable();
            $table->uuid('correlation_id');
            $table->timestamps();
            $table->unique(['operation', 'idempotency_key']);
        });

        Schema::create('bank_statement_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event', 60);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignUuid('import_id')->nullable()->constrained('bank_statement_imports')->restrictOnDelete();
            $table->foreignUuid('statement_transaction_id')->nullable()->constrained('bank_statement_transactions')->restrictOnDelete();
            $table->foreignUuid('match_id')->nullable()->constrained('bank_statement_matches')->restrictOnDelete();
            $table->foreignId('reconciliation_id')->nullable()->constrained('bank_reconciliations')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->uuid('correlation_id');
            $table->timestamp('created_at');
            $table->index(['bank_account_id', 'created_at']);
            $table->index(['import_id', 'created_at']);
        });

        Schema::create('bank_statement_import_errors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_id')->constrained('bank_statement_imports')->cascadeOnDelete();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('field')->nullable();
            $table->string('code', 60);
            $table->string('severity', 12)->default('error');
            $table->text('message');
            $table->string('safe_value')->nullable();
            $table->timestamp('created_at');
            $table->index(['import_id', 'source_row']);
        });

        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->unsignedInteger('checkpoint_row')->default(0)->after('error_count');
            $table->unsignedInteger('attempt_count')->default(0)->after('checkpoint_row');
            $table->uuid('correlation_id')->nullable()->after('attempt_count');
        });

        Schema::table('bank_statement_transactions', function (Blueprint $table) {
            $table->text('exclusion_reason')->nullable();
            $table->foreignId('excluded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('excluded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('excluded_by');
            $table->dropColumn(['exclusion_reason', 'excluded_at']);
        });
        Schema::table('bank_statement_imports', fn (Blueprint $table) => $table->dropColumn(['checkpoint_row', 'attempt_count', 'correlation_id']));
        Schema::dropIfExists('bank_statement_import_errors');
        Schema::dropIfExists('bank_statement_audit_events');
        Schema::dropIfExists('bank_statement_operations');
    }
};
