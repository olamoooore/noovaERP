<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_loyalty_id')->constrained('customer_loyalty')->onDelete('cascade');
            $table->foreignId('loyalty_reward_id')->nullable()->constrained('loyalty_rewards')->onDelete('set null');
            $table->foreignId('loyalty_transaction_id')->constrained('loyalty_transactions')->onDelete('cascade');
            $table->integer('points_used');
            $table->decimal('discount_amount', 15, 2)->nullable()->comment('If redeemed as discount');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->onDelete('set null');
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();

            $table->index('customer_loyalty_id');
            $table->index('loyalty_reward_id');
            $table->index('invoice_id');
            $table->index('status');
            $table->index('redeemed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemptions');
    }
};
