<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reversal_relationships', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();

            $table->unsignedBigInteger('original_journal_entry_id')->index();
            $table->unsignedBigInteger('original_ledger_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable()->index();
            $table->unsignedBigInteger('reversal_ledger_entry_id')->nullable();

            $table->string('reversal_mode', 50);
            $table->string('reversal_basis', 50);
            $table->string('reversal_reason_code', 100)->nullable();
            $table->text('reversal_reason_text')->nullable();

            $table->string('full_or_partial', 10)->default('FULL');
            $table->decimal('reversed_amount_summary', 15, 2)->default(0);
            $table->decimal('remaining_reversible_amount_summary', 15, 2)->default(0);
            $table->integer('component_count')->default(0);

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('posted_at')->nullable();

            $table->uuid('correction_case_id')->nullable()->index();
            $table->uuid('correlation_id')->nullable()->index();

            $table->timestamps();

            $table->index(['tenant_id', 'original_journal_entry_id']);
            $table->index(['tenant_id', 'reversal_journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reversal_relationships');
    }
};
