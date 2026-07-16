<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Enhance Artist-Event Relationships - Task 6.3
 *
 * Modifies artist_events table to support many-to-many relationships with roles,
 * performance order, collaboration types, and historical association preservation.
 *
 * Requirements: 22.2, 22.5
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if artist_events table exists, if not create it
        if (! Schema::hasTable('artist_events')) {
            Schema::create('artist_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('artist_id');
                $table->unsignedBigInteger('event_id');
                $table->timestamps();

                // Basic indexes
                $table->index(['artist_id']);
                $table->index(['event_id']);
                $table->unique(['artist_id', 'event_id']);
            });
        }

        // Enhance the artist_events table with new columns
        // Note: Only add columns if they don't already exist to prevent conflicts
        if (Schema::hasTable('artist_events')) {
            Schema::table('artist_events', function (Blueprint $table) {
                // Performance role and details - check if columns exist first
                if (! Schema::hasColumn('artist_events', 'performance_role')) {
                    $table->enum('performance_role', [
                        'headliner',
                        'co_headliner',
                        'opening_act',
                        'supporting_act',
                        'special_guest',
                        'featured_artist',
                        'collaborator',
                        'session_musician',
                        'producer',
                        'dj',
                        'mc_host',
                        'backing_vocalist',
                        'conductor',
                        'composer',
                    ])->default('headliner')->after('event_id');
                    $table->index(['performance_role']);
                }

                if (! Schema::hasColumn('artist_events', 'performance_order')) {
                    $table->integer('performance_order')->default(1)->after('performance_role');
                    $table->index(['performance_order']);
                }

                if (! Schema::hasColumn('artist_events', 'set_duration_minutes')) {
                    $table->integer('set_duration_minutes')->nullable()->after('performance_order');
                }

                if (! Schema::hasColumn('artist_events', 'scheduled_start_time')) {
                    $table->time('scheduled_start_time')->nullable()->after('set_duration_minutes');
                }

                if (! Schema::hasColumn('artist_events', 'actual_start_time')) {
                    $table->time('actual_start_time')->nullable()->after('scheduled_start_time');
                }

                if (! Schema::hasColumn('artist_events', 'actual_end_time')) {
                    $table->time('actual_end_time')->nullable()->after('actual_start_time');
                }

                // Collaboration and relationship details
                if (! Schema::hasColumn('artist_events', 'collaboration_type')) {
                    $table->enum('collaboration_type', [
                        'solo_performance',
                        'band_performance',
                        'collaboration',
                        'guest_appearance',
                        'tribute_performance',
                        'cover_performance',
                        'original_composition',
                        'improvisation',
                        'acoustic_set',
                        'electronic_set',
                        'live_recording',
                        'virtual_performance',
                    ])->default('solo_performance')->after('actual_end_time');
                }

                if (! Schema::hasColumn('artist_events', 'performance_metadata')) {
                    $table->json('performance_metadata')->nullable()->after('collaboration_type');
                }

                if (! Schema::hasColumn('artist_events', 'performance_notes')) {
                    $table->text('performance_notes')->nullable()->after('performance_metadata');
                }

                // Historical preservation
                if (! Schema::hasColumn('artist_events', 'is_historical')) {
                    $table->boolean('is_historical')->default(false)->after('performance_notes');
                }

                if (! Schema::hasColumn('artist_events', 'historical_date')) {
                    $table->date('historical_date')->nullable()->after('is_historical');
                }

                if (! Schema::hasColumn('artist_events', 'historical_source')) {
                    $table->string('historical_source', 255)->nullable()->after('historical_date');
                }

                if (! Schema::hasColumn('artist_events', 'historical_confidence')) {
                    $table->decimal('historical_confidence', 3, 2)->default(1.00)->after('historical_source');
                }

                // Status and visibility
                if (! Schema::hasColumn('artist_events', 'association_status')) {
                    $table->enum('association_status', [
                        'confirmed',
                        'tentative',
                        'cancelled',
                        'replaced',
                        'historical',
                        'disputed',
                    ])->default('confirmed')->after('historical_confidence');
                    $table->index(['association_status']);
                }

                if (! Schema::hasColumn('artist_events', 'is_public')) {
                    $table->boolean('is_public')->default(true)->after('association_status');
                }

                if (! Schema::hasColumn('artist_events', 'is_featured')) {
                    $table->boolean('is_featured')->default(false)->after('is_public');
                }

                // Billing and contract information
                if (! Schema::hasColumn('artist_events', 'performance_fee')) {
                    $table->decimal('performance_fee', 10, 2)->nullable()->after('is_featured');
                }

                if (! Schema::hasColumn('artist_events', 'fee_currency')) {
                    $table->enum('fee_currency', ['USD', 'EUR', 'GBP', 'CAD', 'AUD'])->default('USD')->after('performance_fee');
                }

                if (! Schema::hasColumn('artist_events', 'payment_status')) {
                    $table->enum('payment_status', [
                        'not_applicable',
                        'pending',
                        'partial',
                        'paid',
                        'overdue',
                        'disputed',
                    ])->default('not_applicable')->after('fee_currency');
                    $table->index(['payment_status']);
                }

                // Technical requirements
                if (! Schema::hasColumn('artist_events', 'technical_requirements')) {
                    $table->json('technical_requirements')->nullable()->after('payment_status');
                }

                if (! Schema::hasColumn('artist_events', 'rider_requirements')) {
                    $table->json('rider_requirements')->nullable()->after('technical_requirements');
                }

                // Audit and tracking
                if (! Schema::hasColumn('artist_events', 'created_by')) {
                    $table->string('created_by', 100)->nullable()->after('rider_requirements');
                }

                if (! Schema::hasColumn('artist_events', 'updated_by')) {
                    $table->string('updated_by', 100)->nullable()->after('created_by');
                }

                if (! Schema::hasColumn('artist_events', 'confirmed_at')) {
                    $table->timestamp('confirmed_at')->nullable()->after('updated_by');
                    $table->index(['confirmed_at']);
                }

                if (! Schema::hasColumn('artist_events', 'confirmed_by_user_id')) {
                    $table->unsignedBigInteger('confirmed_by_user_id')->nullable()->after('confirmed_at');
                }

            });

            // Add remaining indexes only if they don't already exist
            $indexChecks = [
                'artist_events_created_at_association_status_index' => 'CREATE INDEX IF NOT EXISTS "artist_events_created_at_association_status_index" ON "artist_events" ("created_at", "association_status")',
                'artist_events_is_historical_historical_date_index' => 'CREATE INDEX IF NOT EXISTS "artist_events_is_historical_historical_date_index" ON "artist_events" ("is_historical", "historical_date")',
                'artist_events_is_public_is_featured_index' => 'CREATE INDEX IF NOT EXISTS "artist_events_is_public_is_featured_index" ON "artist_events" ("is_public", "is_featured")',
            ];
            foreach ($indexChecks as $sql) {
                \DB::statement($sql);
            }
        }

        // Create artist performance history tracking table
        if (! Schema::hasTable('artist_performance_history')) {
            Schema::create('artist_performance_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('canonical_artist_id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('artist_event_id'); // Reference to artist_events
                $table->date('performance_date');
                $table->string('venue_name', 255)->nullable();
                $table->string('venue_city', 100)->nullable();
                $table->string('venue_country', 3)->nullable(); // ISO 3166-1 alpha-3
                $table->enum('performance_type', [
                    'concert',
                    'festival',
                    'private_event',
                    'corporate_event',
                    'charity_event',
                    'recording_session',
                    'tv_appearance',
                    'radio_appearance',
                    'podcast_appearance',
                    'virtual_event',
                    'livestream',
                ]);
                $table->integer('estimated_attendance')->nullable();
                $table->decimal('ticket_price_min', 8, 2)->nullable();
                $table->decimal('ticket_price_max', 8, 2)->nullable();
                $table->string('currency', 3)->default('USD');
                $table->enum('performance_quality', [
                    'excellent',
                    'good',
                    'average',
                    'poor',
                    'cancelled',
                    'unknown',
                ])->default('unknown');
                $table->text('performance_review')->nullable();
                $table->json('setlist')->nullable();
                $table->json('performance_metrics')->nullable(); // Engagement, sales, etc.
                $table->json('media_coverage')->nullable(); // Reviews, photos, videos
                $table->decimal('revenue_share', 10, 2)->nullable();
                $table->boolean('is_milestone')->default(false); // First show, biggest venue, etc.
                $table->string('milestone_description', 255)->nullable();
                $table->timestamps();

                // Foreign key constraints
                $table->foreign('canonical_artist_id')->references('id')->on('artist_canonical')->onDelete('cascade');
                $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
                $table->foreign('artist_event_id')->references('id')->on('artist_events')->onDelete('cascade');

                // Indexes for performance history queries
                $table->index(['canonical_artist_id', 'performance_date']);
                $table->index(['event_id']);
                $table->index(['performance_date']);
                $table->index(['venue_city', 'venue_country']);
                $table->index(['performance_type']);
                $table->index(['estimated_attendance']);
                $table->index(['performance_quality']);
                $table->index(['is_milestone']);
                $table->index(['created_at']);
            });
        }

        // Create artist collaboration tracking table
        if (! Schema::hasTable('artist_collaborations')) {
            Schema::create('artist_collaborations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('primary_artist_id');
                $table->unsignedBigInteger('collaborating_artist_id');
                $table->unsignedBigInteger('event_id')->nullable(); // Null for general collaborations
                $table->enum('collaboration_nature', [
                    'one_time',
                    'recurring',
                    'tour_based',
                    'album_based',
                    'project_based',
                    'permanent_group',
                    'temporary_group',
                ]);
                $table->string('collaboration_name', 255)->nullable(); // Name of the collaboration/project
                $table->text('collaboration_description')->nullable();
                $table->date('collaboration_start')->nullable();
                $table->date('collaboration_end')->nullable();
                $table->integer('total_performances')->default(0);
                $table->decimal('collaboration_strength', 3, 2)->default(1.00); // 0.00 to 1.00
                $table->json('collaboration_metadata')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                // Foreign key constraints
                $table->foreign('primary_artist_id')->references('id')->on('artist_canonical')->onDelete('cascade');
                $table->foreign('collaborating_artist_id')->references('id')->on('artist_canonical')->onDelete('cascade');
                $table->foreign('event_id')->references('id')->on('events')->onDelete('set null');

                // Prevent duplicate collaborations
                $table->unique(['primary_artist_id', 'collaborating_artist_id', 'event_id'], 'unique_collaboration');

                // Indexes for collaboration queries
                $table->index(['primary_artist_id']);
                $table->index(['collaborating_artist_id']);
                $table->index(['event_id']);
                $table->index(['collaboration_nature']);
                $table->index(['is_active']);
                $table->index(['collaboration_start', 'collaboration_end']);
                $table->index(['collaboration_strength']);
            });
        }

        // Create artist event roles table for complex role assignments
        if (! Schema::hasTable('artist_event_roles')) {
            Schema::create('artist_event_roles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('artist_event_id');
                $table->enum('role_type', [
                    'performer',
                    'composer',
                    'lyricist',
                    'arranger',
                    'producer',
                    'sound_engineer',
                    'lighting_designer',
                    'stage_manager',
                    'tour_manager',
                    'merchandise_manager',
                    'security',
                    'photographer',
                    'videographer',
                    'interviewer',
                    'presenter',
                    'announcer',
                ]);
                $table->string('role_description', 255)->nullable();
                $table->boolean('is_primary_role')->default(false);
                $table->integer('role_priority')->default(1); // 1 = highest priority
                $table->decimal('role_compensation', 8, 2)->nullable();
                $table->string('compensation_currency', 3)->default('USD');
                $table->json('role_metadata')->nullable();
                $table->timestamps();

                // Foreign key constraint
                $table->foreign('artist_event_id')->references('id')->on('artist_events')->onDelete('cascade');

                // Unique constraint for primary roles
                $table->unique(['artist_event_id', 'role_type'], 'unique_artist_event_role');

                // Indexes for role queries
                $table->index(['artist_event_id']);
                $table->index(['role_type']);
                $table->index(['is_primary_role']);
                $table->index(['role_priority']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artist_event_roles');
        Schema::dropIfExists('artist_collaborations');
        Schema::dropIfExists('artist_performance_history');

        // Remove added columns from artist_events table
        Schema::table('artist_events', function (Blueprint $table) {
            $table->dropColumn([
                'performance_role',
                'performance_order',
                'set_duration_minutes',
                'scheduled_start_time',
                'actual_start_time',
                'actual_end_time',
                'collaboration_type',
                'performance_metadata',
                'performance_notes',
                'is_historical',
                'historical_date',
                'historical_source',
                'historical_confidence',
                'association_status',
                'is_public',
                'is_featured',
                'performance_fee',
                'fee_currency',
                'payment_status',
                'technical_requirements',
                'rider_requirements',
                'created_by',
                'updated_by',
                'confirmed_at',
                'confirmed_by_user_id',
            ]);
        });
    }
};
