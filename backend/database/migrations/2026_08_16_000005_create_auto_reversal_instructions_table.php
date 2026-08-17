<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_reversal_instructions', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();

            $table->string('original_posting_reference', 255)->nullable();
            $table->unsignedBigInteger('original_journal_entry_id')->index();

            $table->date('scheduled_reversal_date');
            $table->string('reversal_reason_code', 100)->nullable();
            $table->text('reversal_reason_text')->nullable();

            $table->string('reversal_policy_id', 100)->nullable();
            $table->string('reversal_policy_version', 50)->nullable();

            $table->string('execution_state', 30)->default('SCHEDULED')->index();
            $table->timestamp('executed_at')->nullable();
            $table->unsignedBigInteger('executed_journal_entry_id')->nullable();
            $table->text('failure_reason')->nullable();

            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'execution_state', 'scheduled_reversal_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reversal_instructions');
    }
};
