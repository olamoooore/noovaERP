<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subledger_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('allocation_type', 30); // payment, credit_note, write_off
            $table->string('source_document_type', 50);
            $table->unsignedBigInteger('source_document_id');
            $table->string('target_document_type', 50);
            $table->unsignedBigInteger('target_document_id');
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 3)->default('USD');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['source_document_type', 'source_document_id'], 'sa_source_idx');
            $table->index(['target_document_type', 'target_document_id'], 'sa_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subledger_allocations');
    }
};
