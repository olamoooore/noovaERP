<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #151: recurring/subscription sales — a recurrence definition on a
 * sales order (interval, start, optional end/cycle cap) whose scheduled job
 * generates the next order on each billing-cycle boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_order_recurrences')) {
            return;
        }

        Schema::create('sales_order_recurrences', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('interval', 20); // monthly|quarterly|annual
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('max_cycles')->nullable();
            $table->unsignedInteger('cycles_generated')->default(0);
            $table->date('next_run_date');
            $table->string('status', 20)->default('active'); // active|paused|cancelled|completed
            $table->timestamp('last_generated_at')->nullable();
            $table->json('generated_order_ids')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('sales_order_id')->references('id')->on('sales_orders')->cascadeOnDelete();
            $table->index(['status', 'next_run_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_recurrences');
    }
};
