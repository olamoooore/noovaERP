<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->string('invoice_number')->unique(); // e.g. INV-2026-00001
            $table->enum('billing_interval', ['monthly', 'yearly']);
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('subtotal')->default(0); // cents
            $table->unsignedInteger('tax')->default(0);      // cents
            $table->unsignedInteger('total');                // cents
            $table->enum('status', ['draft', 'open', 'paid', 'void', 'uncollectible'])->default('open');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('subscription_id');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
