<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_version_id')->constrained('plan_versions')->cascadeOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->enum('billing_interval', ['monthly', 'yearly']);
            $table->unsignedInteger('unit_amount'); // stored in cents
            $table->timestamps();

            $table->unique(['plan_version_id', 'currency', 'billing_interval']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
