<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #913 (SALES-DIFF) — explicit customer hold state.
 *
 * There was no customer hold / credit-hold mechanism in the codebase (grep
 * for hold/credit_hold only finds unrelated FieldService work orders), so the
 * automation action `hold_customer` needs an explicit flag. `on_hold` is
 * defaulted off, so existing customers and existing tests are unaffected.
 * `hold_reason`/`held_at` record why and when, and the customer model is
 * covered by the shared AuditTrailObserver (SalesServiceProvider) so the
 * change is audited field-by-field with the actor.
 *
 * `CreditCheckService::checkCreditLimit` treats a held customer as a failed
 * credit check, which is what "pause new orders for the customer" means in
 * the issue example.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('on_hold')->default(false)->index();
            $table->string('hold_reason', 500)->nullable();
            $table->timestamp('held_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['on_hold', 'hold_reason', 'held_at']);
        });
    }
};
