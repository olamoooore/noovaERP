<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #92: no Budget entity existed anywhere — the Financial Dashboard
 * fabricated "budget" as 110% of actual. This creates real budget headers and
 * account-level budget lines with an approval state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('budgets')) {
            Schema::create('budgets', function (Blueprint $table) {
                $table->id();
                $table->uuid('tenant_id')->nullable()->index();
                $table->string('name');
                $table->unsignedSmallInteger('fiscal_year');
                $table->date('period_start');
                $table->date('period_end');
                $table->string('status', 20)->default('draft'); // draft|approved|archived
                $table->unsignedInteger('version')->default(1);
                $table->unsignedBigInteger('legal_entity_id')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->index(['fiscal_year', 'status']);
            });
        }

        if (! Schema::hasTable('budget_lines')) {
            Schema::create('budget_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('budget_id');
                $table->unsignedBigInteger('account_id');
                $table->unsignedBigInteger('cost_center_id')->nullable();
                $table->decimal('amount', 15, 2);
                $table->string('notes')->nullable();
                $table->timestamps();

                $table->foreign('budget_id')->references('id')->on('budgets')->cascadeOnDelete();
                $table->index(['budget_id', 'account_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
