<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #882 (SA9) — deferred revenue.
 *
 * A product can be marked deferred with a default recognition period; invoice
 * lines carry an optional service period. The schedule row is the subledger
 * of what is still unearned for a line: the invoice credits deferred revenue
 * for `amount`, the monthly recognition job moves the earned portion to the
 * line's income account and accumulates `recognized_amount`; a credit note or
 * a cancellation accumulates `reversed_amount` / closes the schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_deferred')->default(false);
            $table->unsignedSmallInteger('deferred_months')->nullable();
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->boolean('is_deferred')->default(false);
            $table->date('service_start_date')->nullable();
            $table->date('service_end_date')->nullable();
        });

        Schema::create('deferred_revenue_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('invoice_line_id')->unique()->constrained('invoice_lines')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->foreignId('deferred_account_id')->constrained('chart_of_accounts');
            $table->foreignId('income_account_id')->constrained('chart_of_accounts');
            $table->decimal('amount', 15, 2);
            $table->decimal('recognized_amount', 15, 2)->default(0);
            $table->decimal('reversed_amount', 15, 2)->default(0);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('last_recognized_on')->nullable();
            $table->unsignedBigInteger('last_journal_entry_id')->nullable();
            $table->timestamps();

            $table->index(['legal_entity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deferred_revenue_schedules');

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['is_deferred', 'service_start_date', 'service_end_date']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_deferred', 'deferred_months']);
        });
    }
};
