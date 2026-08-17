<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->uuid('entity_id')->nullable()->index();

            $table->string('settlement_number', 50)->unique();
            $table->string('settlement_state', 30)->default('UNRECOGNISED')->index();
            $table->string('settlement_type', 50)->nullable();

            $table->unsignedBigInteger('payment_execution_id')->nullable()->index();
            $table->string('source_record_type', 100)->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();

            $table->decimal('allocated_amount', 15, 2)->default(0);
            $table->decimal('unapplied_amount', 15, 2)->default(0);
            $table->string('currency_code', 10)->default('USD');

            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->json('metadata')->nullable();

            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'settlement_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
