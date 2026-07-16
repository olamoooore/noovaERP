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
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_program_id')->constrained('loyalty_programs')->onDelete('cascade');
            $table->string('name'); // Bronze, Silver, Gold, Platinum
            $table->integer('min_points');
            $table->integer('max_points')->nullable();
            $table->decimal('earning_multiplier', 5, 2)->default(1.00)->comment('1.0x, 1.2x, 1.5x, 2.0x');
            $table->decimal('discount_percent', 5, 2)->default(0.00);
            $table->json('benefits')->nullable()->comment('Additional benefits like priority support');
            $table->string('color', 7)->nullable()->comment('Hex color for UI');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('loyalty_program_id');
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};
