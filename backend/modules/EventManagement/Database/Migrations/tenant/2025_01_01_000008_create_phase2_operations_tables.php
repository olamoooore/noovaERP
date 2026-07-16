<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Event Operations and Staff Management Tables Migration
 *
 * Creates tables for live event operations and staff management:
 * - staff_assignments: Staff role assignments per event
 * - operational_zones: Event venue zones and areas
 * - check_in_counters: Check-in points and queue management
 * - incident_logs: Operational incidents and administrative overrides
 * - event_status_logs: Real-time event status tracking
 *
 * Requirements: 15.1, 15.2, 15.3, 15.4, 15.5
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Staff Assignments table - Staff role assignments per event
        Schema::create('staff_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            // Relationships
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->string('staff_user_id')->index(); // Reference to user system

            // Assignment Details
            $table->string('role')->index(); // manager, coordinator, security, usher, technical, medical
            $table->string('zone')->nullable()->index(); // Assigned zone/area
            $table->text('responsibilities')->nullable(); // Specific responsibilities
            $table->json('permissions')->nullable(); // Zone-specific permissions

            // Schedule and Availability
            $table->datetime('shift_start')->nullable();
            $table->datetime('shift_end')->nullable();
            $table->boolean('is_lead')->default(false); // Lead staff for the role/zone
            $table->boolean('can_override')->default(false); // Can make administrative overrides

            // Contact and Emergency
            $table->string('contact_number', 20)->nullable();
            $table->string('emergency_contact', 20)->nullable();
            $table->text('special_instructions')->nullable();

            // Status and Performance
            $table->enum('status', ['assigned', 'checked_in', 'active', 'break', 'checked_out', 'absent'])
                ->default('assigned')->index();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->integer('performance_rating')->nullable(); // 1-5 rating
            $table->text('performance_notes')->nullable();

            // Metadata
            $table->json('metadata')->nullable();

            // Audit and Soft Deletes
            $table->timestamps();
            $table->softDeletes();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();

            // Indexes for performance
            $table->index(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'staff_user_id']);
            $table->index(['tenant_id', 'role']);
            $table->index(['tenant_id', 'zone']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'event_id', 'role']);

            // Unique constraints - prevent duplicate assignments
            $table->unique(['tenant_id', 'event_id', 'staff_user_id', 'role']);
        });

        // Operational Zones table - Event venue zones and areas
        Schema::create('operational_zones', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            // Relationships
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');

            // Zone Information
            $table->string('zone_code', 20)->index(); // Short code: VIP, GA, MERCH, ENTRY, etc.
            $table->string('zone_name')->index(); // Human-readable name
            $table->text('description')->nullable();
            $table->enum('zone_type', ['entry', 'seating', 'standing', 'vip', 'merchandise', 'food', 'restroom', 'emergency', 'staff', 'backstage'])
                ->index();

            // Capacity and Layout
            $table->integer('max_capacity')->nullable();
            $table->integer('current_occupancy')->default(0);
            $table->decimal('occupancy_percentage', 5, 2)->default(0.00);
            $table->json('layout_coordinates')->nullable(); // For venue mapping
            $table->json('access_points')->nullable(); // Entry/exit points

            // Operational Settings
            $table->boolean('requires_special_access')->default(false);
            $table->boolean('has_age_restriction')->default(false);
            $table->integer('min_age')->nullable();
            $table->json('required_permissions')->nullable(); // Staff permissions needed

            // Status and Monitoring
            $table->enum('status', ['inactive', 'preparing', 'open', 'full', 'closed', 'emergency'])
                ->default('inactive')->index();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('status_notes')->nullable();

            // Safety and Emergency
            $table->integer('emergency_capacity')->nullable(); // Emergency evacuation capacity
            $table->json('emergency_procedures')->nullable();
            $table->boolean('has_emergency_exit')->default(false);

            // Metadata
            $table->json('metadata')->nullable();

            // Audit and Soft Deletes
            $table->timestamps();
            $table->softDeletes();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();

            // Indexes for performance
            $table->index(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'zone_code']);
            $table->index(['tenant_id', 'zone_type']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'event_id', 'zone_type']);

            // Unique constraints
            $table->unique(['tenant_id', 'event_id', 'zone_code']);
        });

        // Check-in Counters table - Check-in points and queue management
        Schema::create('check_in_counters', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            // Relationships
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('zone_id')->nullable()->constrained('operational_zones')->onDelete('set null');

            // Counter Information
            $table->string('counter_code', 20)->index(); // A1, B2, VIP1, etc.
            $table->string('counter_name');
            $table->text('location_description')->nullable();
            $table->json('coordinates')->nullable(); // Physical location coordinates

            // Operational Details
            $table->enum('counter_type', ['general', 'vip', 'group', 'accessibility', 'express', 'will_call'])
                ->default('general')->index();
            $table->json('supported_ticket_types')->nullable(); // Which ticket types can use this counter
            $table->boolean('supports_group_checkin')->default(false);
            $table->integer('max_group_size')->nullable();

            // Queue Management
            $table->integer('current_queue_length')->default(0);
            $table->integer('max_queue_length')->nullable();
            $table->decimal('average_processing_time', 5, 2)->nullable(); // Minutes per check-in
            $table->integer('total_processed')->default(0);
            $table->timestamp('last_activity_at')->nullable();

            // Staff Assignment
            $table->string('assigned_staff_id')->nullable()->index(); // Current staff member
            $table->integer('staff_count')->default(0); // Number of staff at counter
            $table->boolean('requires_supervisor')->default(false);

            // Status and Performance
            $table->enum('status', ['closed', 'opening', 'active', 'busy', 'break', 'closing', 'maintenance'])
                ->default('closed')->index();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('status_notes')->nullable();

            // Performance Metrics
            $table->integer('checkins_per_hour')->default(0);
            $table->decimal('efficiency_rating', 3, 2)->nullable(); // 0.00 to 5.00
            $table->json('performance_metrics')->nullable(); // Additional metrics

            // Equipment and Technical
            $table->json('equipment_list')->nullable(); // Scanners, tablets, etc.
            $table->boolean('has_technical_issues')->default(false);
            $table->text('technical_notes')->nullable();

            // Metadata
            $table->json('metadata')->nullable();

            // Audit and Soft Deletes
            $table->timestamps();
            $table->softDeletes();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();

            // Indexes for performance
            $table->index(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'counter_code']);
            $table->index(['tenant_id', 'counter_type']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'assigned_staff_id']);
            $table->index(['tenant_id', 'event_id', 'status']);

            // Unique constraints
            $table->unique(['tenant_id', 'event_id', 'counter_code']);
        });

        // Incident Logs table - Operational incidents and administrative overrides
        Schema::create('incident_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            // Relationships
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('zone_id')->nullable()->constrained('operational_zones')->onDelete('set null');
            $table->foreignId('counter_id')->nullable()->constrained('check_in_counters')->onDelete('set null');

            // Incident Classification
            $table->enum('incident_type', [
                'security', 'medical', 'technical', 'crowd_control', 'weather',
                'equipment_failure', 'staff_issue', 'customer_complaint',
                'administrative_override', 'emergency', 'other',
            ])->index();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium')->index();
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal')->index();

            // Incident Details
            $table->string('title');
            $table->text('description');
            $table->text('initial_assessment')->nullable();
            $table->json('affected_areas')->nullable(); // Zones/counters affected
            $table->integer('people_affected')->nullable();

            // Reporting and Assignment
            $table->string('reported_by')->index(); // Staff member who reported
            $table->string('assigned_to')->nullable()->index(); // Staff member assigned to handle
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('assigned_at')->nullable();

            // Resolution and Follow-up
            $table->enum('status', ['open', 'acknowledged', 'in_progress', 'resolved', 'closed', 'escalated'])
                ->default('open')->index();
            $table->text('resolution_notes')->nullable();
            $table->text('actions_taken')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by')->nullable();

            // Administrative Override Details (if applicable)
            $table->boolean('is_administrative_override')->default(false)->index();
            $table->string('override_type')->nullable(); // capacity, access, refund, etc.
            $table->text('override_justification')->nullable();
            $table->string('authorized_by')->nullable(); // Who authorized the override
            $table->json('override_details')->nullable(); // Specific override data

            // Follow-up and Prevention
            $table->boolean('requires_follow_up')->default(false);
            $table->date('follow_up_date')->nullable();
            $table->text('prevention_measures')->nullable();
            $table->boolean('policy_update_required')->default(false);

            // Communication and Escalation
            $table->json('notifications_sent')->nullable(); // Who was notified
            $table->boolean('escalated_to_management')->default(false);
            $table->timestamp('escalated_at')->nullable();
            $table->text('escalation_reason')->nullable();

            // Metadata and Attachments
            $table->json('metadata')->nullable();
            $table->json('attachments')->nullable(); // Photos, documents, etc.

            // Audit and Soft Deletes
            $table->timestamps();
            $table->softDeletes();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();

            // Indexes for performance
            $table->index(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'incident_type']);
            $table->index(['tenant_id', 'severity']);
            $table->index(['tenant_id', 'priority']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'reported_by']);
            $table->index(['tenant_id', 'assigned_to']);
            $table->index(['tenant_id', 'is_administrative_override']);
            $table->index(['tenant_id', 'event_id', 'status']);
            $table->index(['tenant_id', 'event_id', 'incident_type']);
        });

        // Event Status Logs table - Real-time event status tracking
        Schema::create('event_status_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            // Relationships
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');

            // Status Information
            $table->enum('status_type', [
                'event_status', 'checkin_status', 'capacity_status', 'zone_status',
                'security_status', 'technical_status', 'weather_status', 'emergency_status',
            ])->index();
            $table->string('previous_status')->nullable();
            $table->string('current_status')->index();
            $table->text('status_description')->nullable();

            // Context and Details
            $table->string('changed_by')->index(); // Staff member who made the change
            $table->string('change_reason')->nullable();
            $table->json('status_data')->nullable(); // Additional status-specific data
            $table->json('affected_components')->nullable(); // What was affected by this change

            // Timing and Duration
            $table->timestamp('status_changed_at')->useCurrent();
            $table->timestamp('effective_until')->nullable(); // When status expires (if applicable)
            $table->integer('duration_minutes')->nullable(); // How long status was active

            // Impact Assessment
            $table->enum('impact_level', ['none', 'low', 'medium', 'high', 'critical'])->default('none');
            $table->integer('attendees_affected')->nullable();
            $table->json('zones_affected')->nullable();
            $table->text('impact_description')->nullable();

            // Communication and Notifications
            $table->boolean('public_announcement_made')->default(false);
            $table->text('announcement_text')->nullable();
            $table->json('notification_channels')->nullable(); // app, email, sms, pa_system
            $table->timestamp('announced_at')->nullable();

            // Operational Response
            $table->boolean('requires_action')->default(false);
            $table->text('recommended_actions')->nullable();
            $table->json('staff_assignments_changed')->nullable();
            $table->boolean('escalation_triggered')->default(false);

            // Metadata
            $table->json('metadata')->nullable();

            // Audit (no soft deletes - status logs are permanent)
            $table->timestamps();
            $table->string('created_by')->nullable();

            // Indexes for performance
            $table->index(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'status_type']);
            $table->index(['tenant_id', 'current_status']);
            $table->index(['tenant_id', 'changed_by']);
            $table->index(['tenant_id', 'impact_level']);
            $table->index(['tenant_id', 'event_id', 'status_type']);
            $table->index(['tenant_id', 'event_id', 'status_changed_at']);
            $table->index(['status_changed_at']); // For time-series queries
        });

        // Create operational dashboard view for real-time monitoring
        // Note: View commented out for SQLite compatibility in tests
        /*
        DB::statement('
            CREATE VIEW operational_dashboard AS
            SELECT
                e.tenant_id,
                e.id as event_id,
                e.title as event_title,
                e.event_date,
                e.status as event_status,

                -- Staff Summary
                COUNT(DISTINCT sa.id) as total_staff_assigned,
                COUNT(DISTINCT CASE WHEN sa.status = "active" THEN sa.id END) as active_staff_count,
                COUNT(DISTINCT CASE WHEN sa.is_lead = 1 THEN sa.id END) as lead_staff_count,

                -- Zone Summary
                COUNT(DISTINCT oz.id) as total_zones,
                COUNT(DISTINCT CASE WHEN oz.status = "open" THEN oz.id END) as open_zones,
                COUNT(DISTINCT CASE WHEN oz.status = "full" THEN oz.id END) as full_zones,
                SUM(oz.current_occupancy) as total_occupancy,
                AVG(oz.occupancy_percentage) as avg_occupancy_percentage,

                -- Check-in Summary
                COUNT(DISTINCT cc.id) as total_counters,
                COUNT(DISTINCT CASE WHEN cc.status = "active" THEN cc.id END) as active_counters,
                SUM(cc.current_queue_length) as total_queue_length,
                SUM(cc.total_processed) as total_checkins,
                AVG(cc.average_processing_time) as avg_processing_time,

                -- Incident Summary
                COUNT(DISTINCT il.id) as total_incidents,
                COUNT(DISTINCT CASE WHEN il.status IN ("open", "in_progress") THEN il.id END) as active_incidents,
                COUNT(DISTINCT CASE WHEN il.severity = "critical" THEN il.id END) as critical_incidents,
                COUNT(DISTINCT CASE WHEN il.is_administrative_override = 1 THEN il.id END) as admin_overrides,

                -- Latest Status
                (SELECT current_status FROM event_status_logs esl
                 WHERE esl.event_id = e.id AND esl.tenant_id = e.tenant_id
                 ORDER BY esl.status_changed_at DESC LIMIT 1) as latest_status,
                (SELECT status_changed_at FROM event_status_logs esl
                 WHERE esl.event_id = e.id AND esl.tenant_id = e.tenant_id
                 ORDER BY esl.status_changed_at DESC LIMIT 1) as latest_status_time

            FROM events e
            LEFT JOIN staff_assignments sa ON e.id = sa.event_id AND sa.deleted_at IS NULL
            LEFT JOIN operational_zones oz ON e.id = oz.event_id AND oz.deleted_at IS NULL
            LEFT JOIN check_in_counters cc ON e.id = cc.event_id AND cc.deleted_at IS NULL
            LEFT JOIN incident_logs il ON e.id = il.event_id AND il.deleted_at IS NULL
            WHERE e.deleted_at IS NULL
            GROUP BY e.id, e.tenant_id
        ');
        */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop view first
        DB::statement('DROP VIEW IF EXISTS operational_dashboard');

        // Drop tables in reverse order of dependencies
        Schema::dropIfExists('event_status_logs');
        Schema::dropIfExists('incident_logs');
        Schema::dropIfExists('check_in_counters');
        Schema::dropIfExists('operational_zones');
        Schema::dropIfExists('staff_assignments');
    }
};
