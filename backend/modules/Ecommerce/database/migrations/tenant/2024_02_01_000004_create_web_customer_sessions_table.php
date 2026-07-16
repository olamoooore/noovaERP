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
        Schema::create('web_customer_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('token')->unique();
            $table->timestamp('last_active');
            $table->timestamp('expires_at');
            $table->timestamps();

            // Indexes
            $table->index('token');
            $table->index('expires_at');
            $table->index('customer_id');

            // Foreign key
            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_customer_sessions');
    }
};
