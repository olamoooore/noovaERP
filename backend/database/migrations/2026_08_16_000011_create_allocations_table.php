<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();

            $table->unsignedBigInteger('settlement_id')->index();
            $table->string('allocation_type', 50)->nullable();

            $table->string('source_record_type', 100);
            $table->unsignedBigInteger('source_record_id')->index();

            $table->decimal('allocated_amount', 15, 2);
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('exchange_rate', 15, 8)->nullable();

            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->uuid('correlation_id')->nullable();

            $table->timestamps();

            $table->index(['settlement_id', 'source_record_type', 'source_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocations');
    }
};
