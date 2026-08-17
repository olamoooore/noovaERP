<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->uuid('entity_id')->nullable()->index();

            $table->string('execution_number', 50)->unique();
            $table->unsignedBigInteger('payment_id')->index();
            $table->string('execution_state', 30)->default('PREPARED')->index();

            $table->string('payment_method', 50)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('exchange_rate', 15, 8)->nullable();

            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->string('provider_name', 100)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->string('provider_idempotency_key', 255)->nullable();
            $table->string('provider_operation_reference', 255)->nullable();
            $table->string('external_settlement_reference', 255)->nullable();

            $table->integer('submission_attempts')->default(0);
            $table->text('last_submission_error')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();

            $table->boolean('approval_required')->default(false);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();

            $table->uuid('correlation_id')->nullable()->index();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'execution_state']);
            $table->index(['payment_id', 'execution_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_executions');
    }
};
