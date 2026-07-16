<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('web_order_event_reservations')) {
            return;
        }

        Schema::create('web_order_event_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_order_id')->constrained('web_orders')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reservation_token', 64)->unique();
            $table->enum('status', ['active', 'fulfilled', 'released', 'expired'])->default('active');
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->timestamps();

            $table->unique(['web_order_id', 'event_id'], 'web_order_event_reservation_unique');
            $table->index(['status', 'reserved_until'], 'web_order_event_reservation_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_order_event_reservations');
    }
};
