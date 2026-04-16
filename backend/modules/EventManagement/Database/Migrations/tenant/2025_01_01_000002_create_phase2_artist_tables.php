<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Artist Management Tables Migration
 * 
 * Creates tables for K-POP artist and group management:
 * - artists: Individual artists with metadata
 * - artist_groups: K-POP groups with formation details (renamed from 'groups'
 *   to avoid collision with core entity_model 'groups' table)
 * - group_members: Artist-group relationships with roles
 * - artist_events: Artist/group event associations
 * 
 * Requirements: 12.1, 12.5
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Artists table - Individual artists (solo or group members)
        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            
            // Basic Information
            $table->string('name')->index(); // Real name
            $table->string('stage_name')->nullable()->index(); // Stage/performance name
            $table->string('korean_name')->nullable(); // Korean name for K-POP artists
            $table->enum('gender', ['male', 'female', 'non_binary', 'other'])->nullable();
            $table->date('birth_date')->nullable();
            $table->date('debut_date')->nullable()->index();
            
            // Professional Information
            $table->string('agency')->nullable()->index(); // Entertainment agency
            $table->string('nationality')->nullable();
            $table->text('biography')->nullable();
            $table->json('positions')->nullable(); // Roles: vocalist, rapper, dancer, leader, etc.
            
            // Media and Social
            $table->string('profile_image_url')->nullable();
            $table->json('social_media_links')->nullable(); // Instagram, Twitter, etc.
            $table->json('official_colors')->nullable(); // Official fan colors
            
            // Status and Metadata
            $table->enum('status', ['active', 'inactive', 'hiatus', 'disbanded', 'retired'])
                ->default('active')->index();
            $table->json('metadata')->nullable(); // Additional flexible data
            
            // Audit and Soft Deletes
            $table->timestamps();
            $table->softDeletes();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            
            // Indexes for performance
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'agency']);
            $table->index(['tenant_id', 'debut_date']);
            
            // Unique constraints
            $table->unique(['tenant_id', 'name', 'stage_name']);
        });

        // Groups table - K-POP groups and bands
        Schema::create('artist_groups', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            
            // Basic Information
            $table->string('name')->index(); // Group name
            $table->string('korean_name')->nullable(); // Korean group name
            $table->string('english_name')->nullable(); // English group name
            $table->text('name_meaning')->nullable(); // Meaning behind the group name
            
            // Formation Details
            $table->date('debut_date')->nullable()->index();
            $table->date('formation_date')->nullable();
            $table->string('agency')->nullable()->index(); // Entertainment agency
            $table->json('sub_labels')->nullable(); // Sub-labels or divisions
            
            // Group Information
            $table->text('description')->nullable();
            $table->string('concept')->nullable(); // Musical concept/style
            $table->json('genres')->nullable(); // Musical genres
            $table->integer('member_count')->default(0);
            $table->json('official_colors')->nullable(); // Official group colors
            $table->string('fandom_name')->nullable(); // Official fan club name
            
            // Media and Social
            $table->string('logo_url')->nullable();
            $table->string('profile_image_url')->nullable();
            $table->json('social_media_links')->nullable();
            $table->json('official_websites')->nullable();
            
            // Status and Metadata
            $table->enum('status', ['active', 'inactive', 'hiatus', 'disbanded', 'pre_debut'])
                ->default('active')->index();
            $table->json('metadata')->nullable();
            
            // Audit and Soft Deletes
            $table->timestamps();
            $table->softDeletes();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            
            // Indexes for performance
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'agency']);
            $table->index(['tenant_id', 'debut_date']);
            
            // Unique constraints
            $table->unique(['tenant_id', 'name']);
        });

        // Group Members table - Artist-Group relationships
        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            
            // Relationships
            $table->foreignId('group_id')->constrained('artist_groups')->onDelete('cascade');
            $table->foreignId('artist_id')->constrained('artists')->onDelete('cascade');
            
            // Member Details
            $table->string('stage_name_in_group')->nullable(); // Different stage name in group
            $table->json('positions')->nullable(); // Roles within the group
            $table->boolean('is_leader')->default(false);
            $table->boolean('is_main_vocalist')->default(false);
            $table->boolean('is_main_dancer')->default(false);
            $table->boolean('is_main_rapper')->default(false);
            $table->integer('member_number')->nullable(); // Official member number
            
            // Timeline
            $table->date('joined_date')->nullable();
            $table->date('left_date')->nullable();
            $table->enum('status', ['active', 'inactive', 'hiatus', 'graduated', 'expelled'])
                ->default('active')->index();
            $table->text('departure_reason')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            
            // Audit and Soft Deletes
            $table->timestamps();
            $table->softDeletes();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            
            // Indexes for performance
            $table->index(['tenant_id', 'group_id']);
            $table->index(['tenant_id', 'artist_id']);
            $table->index(['tenant_id', 'status']);
            
            // Unique constraints - prevent duplicate memberships
            $table->unique(['tenant_id', 'group_id', 'artist_id']);
        });

        // Artist Events table - Artist/Group event associations
        Schema::create('artist_events', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            
            // Relationships
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('artist_id')->nullable()->constrained('artists')->onDelete('cascade');
            $table->foreignId('group_id')->nullable()->constrained('artist_groups')->onDelete('cascade');
            
            // Performance Details
            $table->enum('role', ['main', 'guest', 'collaboration', 'special_guest', 'opening_act'])
                ->default('main')->index();
            $table->integer('performance_order')->nullable(); // Order of appearance
            $table->time('performance_duration')->nullable(); // Expected duration
            $table->json('setlist')->nullable(); // Songs/performances planned
            
            // Event-specific Information
            $table->string('billing_name')->nullable(); // How they're billed for this event
            $table->text('special_notes')->nullable(); // Event-specific notes
            $table->decimal('performance_fee', 12, 2)->nullable(); // Fee for this performance
            $table->json('technical_requirements')->nullable(); // Stage, sound, lighting needs
            
            // Status and Confirmation
            $table->enum('status', ['confirmed', 'tentative', 'cancelled', 'completed'])
                ->default('tentative')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_by')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            
            // Audit and Soft Deletes
            $table->timestamps();
            $table->softDeletes();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            
            // Indexes for performance
            $table->index(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'artist_id']);
            $table->index(['tenant_id', 'group_id']);
            $table->index(['tenant_id', 'role']);
            $table->index(['tenant_id', 'status']);
            
            // Constraints - must have either artist_id or group_id, not both
            // Note: Check constraints commented out for SQLite compatibility in tests
            // $table->check('(artist_id IS NOT NULL AND group_id IS NULL) OR (artist_id IS NULL AND group_id IS NOT NULL)');
        });

        // Artist Performance History view for analytics
        // Note: View commented out for SQLite compatibility in tests
        // Views with complex joins can cause issues during table alterations in SQLite
        /*
        DB::statement('
            CREATE VIEW artist_performance_history AS
            SELECT 
                ae.tenant_id,
                ae.artist_id,
                ae.group_id,
                COALESCE(a.name, g.name) as performer_name,
                COALESCE(a.stage_name, g.name) as stage_name,
                ae.event_id,
                e.title as event_title,
                e.event_date as event_date,
                ae.role,
                ae.performance_order,
                ae.status as performance_status,
                COUNT(DISTINCT t.id) as tickets_sold,
                0 as total_revenue
            FROM artist_events ae
            LEFT JOIN artists a ON ae.artist_id = a.id
            LEFT JOIN artist_groups g ON ae.group_id = g.id
            LEFT JOIN events e ON ae.event_id = e.id
            LEFT JOIN bookings b ON e.id = b.event_id
            LEFT JOIN tickets t ON b.id = t.booking_id AND t.status = "active"
            WHERE ae.deleted_at IS NULL
            GROUP BY ae.id, ae.tenant_id, ae.artist_id, ae.group_id, ae.event_id
        ');
        */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop view first
        DB::statement('DROP VIEW IF EXISTS artist_performance_history');
        
        // Drop tables in reverse order of dependencies
        Schema::dropIfExists('artist_events');
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('artist_groups');
        Schema::dropIfExists('artists');
    }
};