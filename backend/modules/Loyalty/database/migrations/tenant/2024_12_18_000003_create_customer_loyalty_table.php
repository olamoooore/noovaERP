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
        Schema::create('customer_loyalty', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('loyalty_program_id')->constrained('loyalty_programs')->onDelete('restrict');
            $table->foreignId('loyalty_tier_id')->nullable()->constrained('loyalty_tiers')->onDelete('set null');
            $table->integer('current_points')->default(0)->comment('Available points balance');
            $table->integer('lifetime_points')->default(0)->comment('Total points ever earned');
            $table->integer('points_earned')->default(0)->comment('Points earned before expiry');
            $table->integer('points_redeemed')->default(0)->comment('Points used');
            $table->integer('points_expired')->default(0)->comment('Points lost to expiry');
            $table->timestamp('tier_upgraded_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique('customer_id');
            $table->index('loyalty_program_id');
            $table->index('loyalty_tier_id');
            $table->index('current_points');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_loyalty');
    }
};
