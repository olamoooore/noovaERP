<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_transaction_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id');
            $table->string('source_document_type', 50);
            $table->unsignedBigInteger('source_document_id');
            $table->string('tax_code', 30)->nullable();
            $table->unsignedBigInteger('tax_rule_id')->nullable();
            $table->decimal('taxable_amount', 15, 2);
            $table->decimal('tax_amount', 15, 2);
            $table->string('jurisdiction', 80)->nullable();
            $table->string('reporting_period', 20)->nullable();
            $table->timestamps();

            $table->index('journal_entry_id');
            $table->index(['source_document_type', 'source_document_id'], 'ttd_source_idx');
            $table->index('reporting_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_transaction_details');
    }
};
