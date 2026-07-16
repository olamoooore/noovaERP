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
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_program_id')->constrained('loyalty_programs')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('points_cost');
            $table->integer('stock_quantity')->nullable()->comment('NULL = unlimited');
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('loyalty_program_id');
            $table->index('is_active');
            $table->index('points_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_rewards');
    }
};
