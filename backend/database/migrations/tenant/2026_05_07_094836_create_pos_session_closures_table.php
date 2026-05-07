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
        Schema::create('pos_session_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('pos_sessions')->onDelete('cascade');
            $table->string('payment_method');
            $table->string('payment_method_name')->nullable();
            $table->decimal('expected_amount', 12, 2)->default(0);
            $table->decimal('counted_amount', 12, 2)->default(0);
            $table->decimal('difference', 12, 2)->default(0);
            $table->timestamps();

            $table->index('session_id');
            $table->unique(['session_id', 'payment_method'], 'session_payment_method_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_session_closures');
    }
};
