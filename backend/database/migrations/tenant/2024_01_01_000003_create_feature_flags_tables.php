<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant: Feature flags system
     * Enables modular add-ons (POS, Loyalty, WhatsApp, etc.)
     */
    public function up(): void
    {
        if (Schema::hasTable('features')) {
            return;
        }

        // In testing, skip - central migrations already created these tables
        if (app()->environment('testing')) {
            return;
        }

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // pos, loyalty, whatsapp, ai
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_core')->default(false); // Core features always enabled
            $table->decimal('price', 10, 2)->default(0); // Monthly add-on price
            $table->timestamps();
        });

        Schema::create('tenant_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_id')->constrained()->onDelete('cascade');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing')) {
            Schema::dropIfExists('tenant_features');
            Schema::dropIfExists('features');
        }
    }
};
