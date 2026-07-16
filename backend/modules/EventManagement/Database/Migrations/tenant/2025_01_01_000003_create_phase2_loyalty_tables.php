<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Phase 2 Loyalty System.
     * Creates fan tiers, customer tiers, tier benefits, and early access windows.
     */
    public function up(): void
    {
        // Fan tier definitions table
        Schema::create('fan_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('name'); // General, Member, VIP
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('minimum_points')->default(0);
            $table->integer('minimum_events_attended')->default(0);
            $table->decimal('minimum_total_spent', 10, 2)->default(0);
            $table->integer('priority_level')->default(0); // Higher = better priority
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable(); // Additional tier configuration
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance and tenant isolation
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'priority_level']);
            $table->unique(['tenant_id', 'slug']);
        });

        // Customer tier assignments table
        Schema::create('customer_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('fan_tier_id')->constrained('fan_tiers')->onDelete('cascade');
            $table->integer('current_points')->default(0);
            $table->integer('lifetime_points')->default(0);
            $table->integer('events_attended')->default(0);
            $table->decimal('total_spent', 10, 2)->default(0);
            $table->timestamp('tier_assigned_at');
            $table->timestamp('tier_expires_at')->nullable();
            $table->json('qualification_data')->nullable(); // How they qualified for this tier
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance and tenant isolation
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'fan_tier_id']);
            $table->unique(['tenant_id', 'customer_id']); // One tier per customer per tenant
        });

        // Tier benefits definition table
        Schema::create('tier_benefits', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('fan_tier_id')->constrained('fan_tiers')->onDelete('cascade');
            $table->string('benefit_type'); // early_access, discount, exclusive_tickets, priority_support
            $table->string('benefit_name');
            $table->text('description')->nullable();
            $table->json('configuration'); // Benefit-specific settings
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance and tenant isolation
            $table->index(['tenant_id', 'fan_tier_id']);
            $table->index(['tenant_id', 'benefit_type']);
        });

        // Early access windows for events
        Schema::create('early_access_windows', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('fan_tier_id')->constrained('fan_tiers')->onDelete('cascade');
            $table->timestamp('access_starts_at');
            $table->timestamp('access_ends_at');
            $table->integer('max_tickets_per_customer')->nullable();
            $table->json('eligible_ticket_types')->nullable(); // Array of ticket type IDs
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance and tenant isolation
            $table->index(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'fan_tier_id']);
            $table->index(['tenant_id', 'access_starts_at', 'access_ends_at']);
            $table->unique(['tenant_id', 'event_id', 'fan_tier_id']);
        });

        // Loyalty points transactions table
        Schema::create('loyalty_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->string('transaction_type'); // earned, redeemed, expired, adjusted
            $table->integer('points_amount'); // Positive for earned, negative for redeemed
            $table->string('source_type'); // booking, purchase, manual, expiration
            $table->unsignedBigInteger('source_id')->nullable(); // ID of related record
            $table->text('description')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable(); // Additional transaction data
            $table->timestamps();

            // Indexes for performance and tenant isolation
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'transaction_type']);
            $table->index(['tenant_id', 'expires_at']);
            $table->index(['source_type', 'source_id']);
        });

        // Tier benefit usage tracking
        Schema::create('tier_benefit_usage', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('tier_benefit_id')->constrained('tier_benefits')->onDelete('cascade');
            $table->string('usage_type'); // early_access_used, discount_applied, etc.
            $table->string('source_type'); // booking, purchase, etc.
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('usage_data')->nullable(); // Benefit-specific usage details
            $table->timestamp('used_at');
            $table->timestamps();

            // Indexes for performance and tenant isolation
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'tier_benefit_id']);
            $table->index(['tenant_id', 'used_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tier_benefit_usage');
        Schema::dropIfExists('loyalty_point_transactions');
        Schema::dropIfExists('early_access_windows');
        Schema::dropIfExists('tier_benefits');
        Schema::dropIfExists('customer_tiers');
        Schema::dropIfExists('fan_tiers');
    }
};
