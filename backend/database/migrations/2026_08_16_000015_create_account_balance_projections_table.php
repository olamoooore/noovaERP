<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_balance_projections', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->uuid('entity_id')->nullable();

            $table->uuid('accounting_book_id')->nullable();
            $table->unsignedBigInteger('ledger_account_id')->index();
            $table->string('period_id', 50)->nullable()->index();

            $table->string('currency_basis', 10)->default('USD');
            $table->json('dimensions')->nullable();
            $table->string('ledger_checkpoint', 100)->nullable();

            $table->decimal('total_debit', 15, 2)->default(0);
            $table->decimal('total_credit', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);

            $table->timestamp('computed_at')->nullable();
            $table->boolean('is_authoritative')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'ledger_account_id', 'period_id']);
            $table->index(['tenant_id', 'is_authoritative']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_balance_projections');
    }
};
