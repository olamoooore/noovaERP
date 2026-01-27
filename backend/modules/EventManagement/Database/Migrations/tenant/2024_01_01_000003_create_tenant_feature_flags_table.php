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
        Schema::create('tenant_feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('feature', 100);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            
            // Unique constraint to prevent duplicate feature flags per tenant
            $table->unique(['tenant_id', 'feature']);
            
            // Indexes for performance
            $table->index('tenant_id');
            $table->index('feature');
            $table->index('enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_feature_flags');
    }
};