<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MRD: invoice-standardized-flow-mrd.md §5.2 — one row per delivery attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_deliveries')) {
            return;
        }

        Schema::create('invoice_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('channel', 20);          // email | whatsapp | portal
            $table->string('document_type', 32);    // acceptance_copy | final_invoice | payment_request | reminder
            $table->string('recipient');
            $table->string('status', 20)->default('queued'); // queued | sent | failed
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_deliveries');
    }
};
