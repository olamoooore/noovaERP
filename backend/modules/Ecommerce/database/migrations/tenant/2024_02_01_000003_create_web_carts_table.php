<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('guest_token')->nullable()->index();

            // Cart items stored as JSON
            $table->jsonb('items')->default('[]');

            // Coupon
            $table->string('coupon_code')->nullable();

            // Timestamps
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Only one cart per customer/guest
            $table->unique('customer_id');
            $table->unique('guest_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_carts');
    }
};
