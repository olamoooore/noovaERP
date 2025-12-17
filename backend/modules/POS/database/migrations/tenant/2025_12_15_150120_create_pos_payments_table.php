<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_order_id')->constrained('pos_orders')->onDelete('cascade');
            $table->enum('method', ['cash', 'card', 'bank_transfer', 'other'])->default('cash');
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();
            $table->foreignId('received_by')->constrained('users');
            $table->timestamps();

            $table->index('pos_order_id');
            $table->index('method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payments');
    }
};
