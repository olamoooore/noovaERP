<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #649: tenant-configurable sales approval rules, evaluated alongside the
 * amount threshold. The old single tenant-wide amount could not express a
 * rule per document type or any condition other than "amount" (discount %,
 * margin, customer credit exposure).
 *
 * Rows are read by App\Services\SalesApprovalGate; an empty table preserves
 * the pre-#649 threshold-only behaviour exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_approval_rules', function (Blueprint $table) {
            $table->id();
            // quotation | sales_order | invoice | delivery_order | * (any)
            $table->string('document_type', 32);
            // amount | discount_pct | margin | credit_exposure
            $table->string('condition_kind', 32);
            // >= | > | <= | <
            $table->string('operator', 2);
            $table->decimal('value', 15, 4);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['document_type', 'active'], 'sales_approval_rules_type_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_approval_rules');
    }
};
