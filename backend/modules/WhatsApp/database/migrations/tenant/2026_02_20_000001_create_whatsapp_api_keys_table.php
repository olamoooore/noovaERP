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
        // Drop table if it exists to start fresh
        Schema::dropIfExists('whatsapp_api_keys');

        Schema::create('whatsapp_api_keys', function (Blueprint $table) {
            $table->id();
            $table->text('key')->comment('The actual API key (encrypted)');
            $table->string('name')->comment('Friendly name for identification');
            $table->text('description')->nullable()->comment('Optional notes about purpose');
            $table->string('platform_name')->nullable()->comment('E.g., WooCommerce, Shopify, Custom');
            $table->string('platform_url')->nullable()->comment('Store URL');
            $table->string('whatsapp_provider')->default('custom')->comment('e.g., custom, twilio, vonage');
            $table->text('api_endpoint')->nullable()->comment('WhatsApp API base URL');
            $table->text('webhook_secret')->nullable()->comment('Secret for webhook verification (encrypted)');
            $table->boolean('is_active')->default(true)->comment('false when revoked');
            $table->unsignedBigInteger('usage_count')->default(0)->comment('Number of API calls made');
            $table->timestamp('last_used_at')->nullable()->comment('Most recent use timestamp');
            $table->timestamp('expires_at')->nullable()->comment('Optional expiration date');
            $table->unsignedBigInteger('created_by')->nullable()->comment('User ID who created the key');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('is_active');
            $table->index('created_at');
            $table->index('expires_at');

            // Foreign key usually handled by tenancy, but strict referencing users table might fail if users are in central db. 
            // Assuming users are in tenant db or this migration logic is sound for the architecture.
            // Since I am copying, I will keep it as is, but be mindful.
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_api_keys');
    }
};
