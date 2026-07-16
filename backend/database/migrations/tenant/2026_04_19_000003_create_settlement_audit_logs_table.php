<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            $table->foreignId('payment_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('payment_amount', 15, 2);
            $table->decimal('before_paid_amount', 15, 2);
            $table->decimal('after_paid_amount', 15, 2);
            $table->decimal('before_balance', 15, 2);
            $table->decimal('after_balance', 15, 2);
            $table->string('before_status');
            $table->string('after_status');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('settlement_method'); // payment, refund, credit_note, etc.
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('payment_id');
            $table->index('actor_user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_audit_logs');
    }
};
