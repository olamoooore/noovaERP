<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reversal_components', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reversal_relationship_id')->index();
            $table->unsignedBigInteger('original_ledger_entry_line_id')->nullable();
            $table->string('original_source_line_reference', 255)->nullable();

            $table->decimal('reversal_amount', 15, 2);
            $table->decimal('transaction_currency_amount', 15, 2)->nullable();
            $table->decimal('base_currency_amount', 15, 2)->nullable();
            $table->string('tax_component_reference', 255)->nullable();
            $table->json('dimensions')->nullable();

            $table->decimal('component_remaining_reversible_amount', 15, 2);
            $table->unsignedBigInteger('account_id')->nullable();

            $table->decimal('original_debit', 15, 2)->nullable();
            $table->decimal('original_credit', 15, 2)->nullable();
            $table->decimal('reversed_debit', 15, 2)->nullable();
            $table->decimal('reversed_credit', 15, 2)->nullable();

            $table->timestamps();

            $table->index(['reversal_relationship_id', 'original_ledger_entry_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reversal_components');
    }
};
