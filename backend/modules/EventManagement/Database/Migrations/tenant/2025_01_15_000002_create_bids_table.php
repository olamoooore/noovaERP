<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->decimal('bid_amount', 10, 2);
            $table->integer('quantity')->default(1);
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->timestamps();

            // Indexes for performance
            $table->index('event_id');
            $table->index('customer_id');
            $table->index('status');
        });

        // Note: Check constraints commented out for SQLite compatibility in tests
        // In production with MySQL/PostgreSQL, these would be:
        // DB::statement('ALTER TABLE bids ADD CONSTRAINT check_bid_amount CHECK (bid_amount > 0)');
        // DB::statement('ALTER TABLE bids ADD CONSTRAINT check_quantity CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bids');
    }
};
