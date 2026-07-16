<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience Data Models Migration
 *
 * Creates tables for customer event history, reminders, feedback, and personalization.
 * Implements customer journey tracking and notification preferences.
 *
 * Requirements: 16.1, 16.3, 16.4, 16.5
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Customer Event History - tracks customer interactions with events
        Schema::create('customer_event_history', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 36)->index();
            $table->string('customer_id', 36)->index();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->enum('interaction_type', [
                'viewed', 'bookmarked', 'booked', 'attended', 'cancelled',
                'shared', 'reviewed', 'recommended',
            ]);
            $table->json('interaction_data')->nullable(); // Additional context data
            $table->timestamp('interaction_at');
            $table->string('source', 50)->nullable(); // web, mobile, api
            $table->string('user_agent')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->json('metadata')->nullable(); // Extensible data
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('set null');

            // Indexes for performance
            $table->index(['tenant_id', 'customer_id', 'interaction_at']);
            $table->index(['tenant_id', 'event_id', 'interaction_type']);
            $table->index(['tenant_id', 'interaction_type', 'interaction_at']);
        });

        // Event Reminders - scheduled notifications for customers
        Schema::create('event_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 36)->index();
            $table->string('customer_id', 36)->index();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->enum('reminder_type', [
                'event_announcement', 'booking_confirmation', 'event_reminder',
                'check_in_reminder', 'post_event_feedback', 'ticket_download',
                'event_update', 'cancellation_notice', 'refund_notice',
            ]);
            $table->timestamp('scheduled_at');
            $table->timestamp('sent_at')->nullable();
            $table->enum('status', ['scheduled', 'sent', 'failed', 'cancelled'])->default('scheduled');
            $table->enum('channel', ['email', 'sms', 'push', 'in_app'])->default('email');
            $table->string('recipient_address'); // email, phone, device_token
            $table->string('subject')->nullable();
            $table->text('message');
            $table->json('template_data')->nullable(); // Data for template rendering
            $table->json('delivery_metadata')->nullable(); // Provider response, tracking info
            $table->integer('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('cascade');

            // Indexes for performance
            $table->index(['tenant_id', 'customer_id', 'scheduled_at']);
            $table->index(['tenant_id', 'status', 'scheduled_at']);
            $table->index(['tenant_id', 'reminder_type', 'scheduled_at']);
            $table->index(['scheduled_at', 'status']); // For processing queue
        });

        // Feedback Submissions - customer reviews and ratings
        Schema::create('feedback_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 36)->index();
            $table->string('customer_id', 36)->index();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->enum('feedback_type', ['rating', 'review', 'complaint', 'suggestion', 'testimonial']);
            $table->integer('overall_rating')->nullable(); // 1-5 scale
            $table->integer('venue_rating')->nullable();
            $table->integer('organization_rating')->nullable();
            $table->integer('value_rating')->nullable();
            $table->string('title')->nullable();
            $table->text('comment')->nullable();
            $table->json('media_attachments')->nullable(); // Photos, videos
            $table->boolean('is_anonymous')->default(false);
            $table->boolean('allow_public_display')->default(true);
            $table->enum('status', ['pending', 'approved', 'rejected', 'flagged'])->default('pending');
            $table->string('moderated_by', 36)->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_notes')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->integer('helpful_votes')->default(0);
            $table->integer('total_votes')->default(0);
            $table->json('sentiment_analysis')->nullable(); // AI sentiment scores
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('set null');

            // Indexes for performance
            $table->index(['tenant_id', 'customer_id', 'created_at']);
            $table->index(['tenant_id', 'event_id', 'status']);
            $table->index(['tenant_id', 'feedback_type', 'status']);
            $table->index(['tenant_id', 'overall_rating', 'status']);
            $table->index(['status', 'created_at']); // For moderation queue
        });

        // Notification Preferences - customer communication preferences
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 36)->index();
            $table->string('customer_id', 36)->index();
            $table->enum('notification_type', [
                'event_announcements', 'booking_confirmations', 'event_reminders',
                'check_in_reminders', 'event_updates', 'cancellation_notices',
                'feedback_requests', 'promotional_offers', 'loyalty_updates',
                'artist_announcements', 'early_access_notifications',
            ]);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);
            $table->enum('frequency', ['immediate', 'daily_digest', 'weekly_digest', 'disabled'])->default('immediate');
            $table->time('preferred_time')->nullable(); // Preferred delivery time
            $table->string('timezone', 50)->default('UTC');
            $table->json('custom_settings')->nullable(); // Type-specific settings
            $table->timestamps();

            // Unique constraint - one preference per customer per type
            $table->unique(['tenant_id', 'customer_id', 'notification_type'], 'unique_customer_notification_pref');

            // Indexes
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'notification_type']);
        });

        // Event Recommendations - personalized event suggestions
        Schema::create('event_recommendations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 36)->index();
            $table->string('customer_id', 36)->index();
            $table->unsignedBigInteger('event_id');
            $table->enum('recommendation_type', [
                'similar_events', 'artist_based', 'location_based', 'price_based',
                'collaborative_filtering', 'trending', 'seasonal', 'personalized',
            ]);
            $table->decimal('confidence_score', 5, 4); // 0.0000 to 1.0000
            $table->json('recommendation_factors')->nullable(); // Why this was recommended
            $table->enum('status', ['generated', 'shown', 'clicked', 'booked', 'dismissed'])->default('generated');
            $table->timestamp('generated_at');
            $table->timestamp('shown_at')->nullable();
            $table->timestamp('interacted_at')->nullable();
            $table->string('interaction_type')->nullable(); // clicked, dismissed, booked
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');

            // Indexes for performance
            $table->index(['tenant_id', 'customer_id', 'status']);
            $table->index(['tenant_id', 'customer_id', 'confidence_score']);
            $table->index(['tenant_id', 'recommendation_type', 'generated_at']);
            $table->index(['generated_at', 'status']); // For cleanup jobs
        });

        // Customer Journey Tracking - comprehensive customer behavior analysis
        Schema::create('customer_journey_events', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 36)->index();
            $table->string('customer_id', 36)->index();
            $table->string('session_id', 100)->nullable()->index();
            $table->enum('event_type', [
                'page_view', 'event_view', 'search', 'filter_applied', 'bookmark_added',
                'booking_started', 'booking_abandoned', 'booking_completed',
                'ticket_downloaded', 'check_in_completed', 'feedback_submitted',
                'recommendation_clicked', 'share_action', 'support_contact',
            ]);
            $table->string('page_url')->nullable();
            $table->string('referrer_url')->nullable();
            $table->unsignedBigInteger('related_event_id')->nullable();
            $table->unsignedBigInteger('related_booking_id')->nullable();
            $table->json('event_properties')->nullable(); // Event-specific data
            $table->string('user_agent')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('device_type', 20)->nullable(); // mobile, tablet, desktop
            $table->string('browser', 50)->nullable();
            $table->string('os', 50)->nullable();
            $table->string('location')->nullable(); // Geographic location (lat,lng as string for SQLite compatibility)
            $table->timestamp('event_timestamp');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('related_event_id')->references('id')->on('events')->onDelete('set null');
            $table->foreign('related_booking_id')->references('id')->on('bookings')->onDelete('set null');

            // Indexes for analytics
            $table->index(['tenant_id', 'customer_id', 'event_timestamp']);
            $table->index(['tenant_id', 'event_type', 'event_timestamp']);
            $table->index(['tenant_id', 'session_id', 'event_timestamp']);
            $table->index(['event_timestamp']); // For cleanup and analytics
        });

        // Customer Preferences - personalization settings
        Schema::create('customer_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 36)->index();
            $table->string('customer_id', 36)->index();
            $table->json('preferred_genres')->nullable(); // Music genres, event types
            $table->json('preferred_artists')->nullable(); // Artist IDs
            $table->json('preferred_venues')->nullable(); // Venue preferences
            $table->json('preferred_price_range')->nullable(); // Min/max price
            $table->json('preferred_event_times')->nullable(); // Day/time preferences
            $table->integer('travel_distance_km')->nullable(); // Max travel distance
            $table->json('accessibility_needs')->nullable(); // Special requirements
            $table->json('dietary_restrictions')->nullable(); // For catering events
            $table->enum('communication_style', ['minimal', 'standard', 'detailed'])->default('standard');
            $table->string('preferred_language', 10)->default('en');
            $table->json('custom_preferences')->nullable(); // Extensible preferences
            $table->timestamps();

            // Unique constraint
            $table->unique(['tenant_id', 'customer_id'], 'unique_customer_preferences');

            // Indexes
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_preferences');
        Schema::dropIfExists('customer_journey_events');
        Schema::dropIfExists('event_recommendations');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('feedback_submissions');
        Schema::dropIfExists('event_reminders');
        Schema::dropIfExists('customer_event_history');
    }
};
