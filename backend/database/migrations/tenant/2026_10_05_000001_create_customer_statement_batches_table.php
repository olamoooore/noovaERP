<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #607 (RPT-F1) — one row per batch statement run so the UI can show
 * progress while the queued job generates and sends one statement per
 * customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_statement_batches')) {
            return;
        }

        Schema::create('customer_statement_batches', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('queued'); // queued | processing | completed | failed
            $table->jsonb('filters')->nullable();
            $table->jsonb('options')->nullable();
            $table->unsignedInteger('total_customers')->default(0);
            $table->unsignedInteger('processed_customers')->default(0);
            $table->unsignedInteger('generated_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_statement_batches');
    }
};
