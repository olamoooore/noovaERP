<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('subscription_invoices')->restrictOnDelete();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unsignedInteger('amount');               // cents
            $table->char('currency', 3)->default('USD');
            $table->enum('status', ['pending', 'succeeded', 'failed', 'refunded'])->default('pending');
            $table->string('provider')->default('manual');   // manual, stripe, etc.
            $table->string('provider_reference')->nullable(); // Stripe charge ID, etc.
            $table->string('idempotency_key')->unique()->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->enum('status', ['pending', 'succeeded', 'failed'])->default('pending');
            $table->text('failure_reason')->nullable();
            $table->json('provider_response')->nullable(); // raw response from payment gateway
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index(['payment_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
    }
};
