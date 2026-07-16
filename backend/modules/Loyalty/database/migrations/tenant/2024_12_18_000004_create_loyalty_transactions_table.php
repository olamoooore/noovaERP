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
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_loyalty_id')->constrained('customer_loyalty')->onDelete('cascade');
            $table->enum('type', ['earn', 'redeem', 'expire', 'adjust', 'reverse_earn', 'restore_redeem']);
            $table->integer('points')->comment('Positive for earn, negative for redeem/expire');
            $table->integer('balance_after');
            $table->string('reference_type')->nullable()->comment('invoice, payment, manual, expiry');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('expires_at')->nullable()->comment('For earn transactions');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index('customer_loyalty_id');
            $table->index('type');
            $table->index('expires_at');
            $table->index(['reference_type', 'reference_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
