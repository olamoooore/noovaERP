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
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('points_per_currency', 10, 2)->default(1.00)->comment('Points earned per $1');
            $table->decimal('currency_per_point', 10, 2)->default(0.01)->comment('$1 = 100 points');
            $table->integer('min_redemption_points')->default(100);
            $table->decimal('max_redemption_percent', 5, 2)->default(50.00)->comment('Max % of invoice that can be discounted');
            $table->integer('points_expiry_months')->default(12);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_programs');
    }
};
