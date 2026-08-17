<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correction_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->uuid('entity_id')->nullable()->index();

            $table->string('case_number', 50)->unique();
            $table->string('case_type', 50)->index();
            $table->string('overall_state', 30)->default('OPEN')->index();

            $table->string('document_correction_state', 30)->nullable();
            $table->string('accounting_correction_state', 30)->nullable();
            $table->string('regulatory_correction_state', 30)->nullable();
            $table->string('settlement_correction_state', 30)->nullable();
            $table->string('replacement_issue_state', 30)->nullable();

            $table->string('triggered_by', 100)->nullable();
            $table->string('trigger_reference_type', 100)->nullable();
            $table->unsignedBigInteger('trigger_reference_id')->nullable();

            $table->string('reason_code', 100)->nullable();
            $table->text('reason_text')->nullable();

            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->uuid('correlation_id')->nullable()->index();

            $table->json('metadata')->nullable();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'overall_state']);
            $table->index(['tenant_id', 'case_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correction_cases');
    }
};
