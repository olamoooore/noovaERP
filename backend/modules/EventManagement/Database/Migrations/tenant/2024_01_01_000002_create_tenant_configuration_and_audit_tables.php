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
        // Create event_settings table for tenant configuration
        Schema::create('event_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('currency', 3)->default('USD');
            $table->string('timezone', 50)->default('UTC');
            $table->text('cancellation_policy')->nullable();
            $table->text('refund_policy')->nullable();
            $table->integer('max_capacity_per_event')->default(10000);
            $table->boolean('require_attendee_id')->default(false);
            $table->integer('data_retention_days')->default(2555); // 7 years
            $table->timestamps();
            
            // Unique constraint on tenant_id for tenant isolation
            $table->unique('tenant_id');
        });

        // Create activity_logs table for audit logging
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->enum('actor_type', ['user', 'system']);
            $table->string('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->json('changes')->nullable();
            $table->string('request_id', 36)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes for performance and tenant isolation
            $table->index('tenant_id');
            $table->index(['subject_type', 'subject_id']);
            $table->index('request_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('event_settings');
    }
};