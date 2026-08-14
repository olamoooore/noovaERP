<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('entity_id')->index();
            $table->string('finding_type', 100)->index(); // anomaly, missing_support, unposted_entries, overdue_invoices, unreconciled_transactions, control_weakness
            $table->string('severity', 20)->default('medium'); // low, medium, high, critical
            $table->string('status', 20)->default('open')->index(); // open, acknowledged, investigating, resolved, dismissed, escalated
            $table->string('source_type', 100)->nullable(); // journal_entry, invoice, bill, payment, bank_reconciliation, manual
            $table->uuid('source_id')->nullable();
            $table->string('account_code', 50)->nullable()->index();
            $table->string('reference_number', 100)->nullable();
            $table->text('title');
            $table->text('description');
            $table->text('recommendation')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->float('confidence')->default(0);
            $table->json('metadata')->nullable();
            $table->string('assigned_to')->nullable()->index();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'finding_type']);
            $table->index(['tenant_id', 'status', 'severity']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_findings');
    }
};
