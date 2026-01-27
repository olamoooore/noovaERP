<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

/**
 * Migration: Create Operations Hardening Tables - Task 9.1
 * 
 * Creates comprehensive operations console hardening database schema with offline
 * check-in queue management, staff override tracking, conflict resolution, and
 * synchronization support for reliable event operations.
 * 
 * Requirements: 25.1, 25.2, 25.3
 */
class CreateOperationsHardeningTables extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Offline check-in queue for handling disconnected operations
        $this->createTableSafe('offline_checkin_queue', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id')->index();
            $table->bigInteger('staff_id')->index();
            $table->bigInteger('event_id')->index();
            $table->string('device_id', 255)->index();
            $table->timestamp('checkin_timestamp');
            $table->enum('sync_status', ['pending', 'synced', 'conflict', 'resolved', 'failed'])->default('pending')->index();
            $table->json('checkin_data'); // Original check-in data and context
            $table->json('conflict_data')->nullable(); // Conflict details if any
            $table->json('resolution_data')->nullable(); // Resolution details
            $table->string('conflict_type', 100)->nullable()->index(); // Type of conflict detected
            $table->bigInteger('resolved_by_staff_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();

            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();

            $table->index(['sync_status', 'created_at']);
            $table->index(['device_id', 'checkin_timestamp']);
            $table->index(['event_id', 'sync_status']);
            $table->index(['staff_id', 'sync_status']);
            $table->index(['conflict_type', 'sync_status']);
        });

        // Staff overrides with mandatory reason tracking
        $this->createTableSafe('staff_overrides', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('staff_id')->index();
            $table->bigInteger('event_id')->nullable()->index();
            $table->string('override_type', 100)->index(); // ticket_validation, capacity_override, etc.
            $table->string('target_entity_type', 100)->index(); // ticket, booking, event, etc.
            $table->bigInteger('target_entity_id')->index();
            $table->text('override_reason'); // Mandatory reason
            $table->enum('override_category', [
                'technical_issue',
                'customer_service',
                'emergency_situation',
                'system_error',
                'policy_exception',
                'security_concern',
                'other'
            ])->index();
            $table->json('override_data'); // Original and new values
            $table->json('context_data'); // Additional context and metadata
            $table->boolean('supervisor_approval_required')->default(false)->index();
            $table->bigInteger('approved_by_staff_id')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->enum('approval_status', ['pending', 'approved', 'rejected', 'auto_approved'])->default('pending')->index();
            $table->string('device_id', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('override_timestamp')->index();
            $table->boolean('is_emergency_override')->default(false)->index();
            $table->json('audit_metadata')->nullable(); // Additional audit information
            $table->timestamps();

            $table->index(['staff_id', 'override_timestamp']);
            $table->index(['target_entity_type', 'target_entity_id']);
            $table->index(['override_type', 'override_category']);
            $table->index(['supervisor_approval_required', 'approval_status']);
            $table->index(['event_id', 'override_type']);
        });

        // Device registration and management for offline operations
        $this->createTableSafe('operations_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 255)->unique()->index();
            $table->string('device_name', 255);
            $table->string('device_type', 100)->index(); // tablet, mobile, kiosk, etc.
            $table->enum('device_status', ['active', 'inactive', 'maintenance', 'offline'])->default('active')->index();
            $table->bigInteger('assigned_staff_id')->nullable()->index();
            $table->bigInteger('event_id')->nullable()->index();
            $table->string('zone_assignment', 100)->nullable()->index(); // entrance, vip, general, etc.
            $table->json('device_capabilities'); // Supported operations and features
            $table->json('device_configuration'); // Device-specific settings
            $table->timestamp('last_online_at')->nullable()->index();
            $table->timestamp('last_sync_at')->nullable()->index();
            $table->integer('pending_sync_count')->default(0);
            $table->json('sync_status_details')->nullable();
            $table->string('firmware_version', 50)->nullable();
            $table->string('app_version', 50)->nullable();
            $table->json('device_metadata')->nullable(); // Hardware specs, etc.
            $table->timestamps();

            $table->index(['device_status', 'last_online_at']);
            $table->index(['assigned_staff_id', 'device_status']);
            $table->index(['event_id', 'zone_assignment']);
        });

        // Staff assignments and scoping per event and zone
        $this->createTableSafe('staff_assignments', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('staff_id')->index();
            $table->bigInteger('event_id')->index();
            $table->string('assignment_role', 100)->index(); // check_in_staff, supervisor, security, etc.
            $table->json('zone_permissions'); // Zones this staff can access
            $table->json('operation_permissions'); // Operations this staff can perform
            $table->boolean('can_override')->default(false)->index();
            $table->boolean('can_approve_overrides')->default(false)->index();
            $table->boolean('is_supervisor')->default(false)->index();
            $table->timestamp('assignment_start')->index();
            $table->timestamp('assignment_end')->nullable()->index();
            $table->enum('assignment_status', ['active', 'inactive', 'suspended'])->default('active')->index();
            $table->bigInteger('assigned_by_staff_id')->index();
            $table->text('assignment_notes')->nullable();
            $table->json('shift_schedule')->nullable(); // Scheduled work hours
            $table->timestamps();

            $table->unique(['staff_id', 'event_id', 'assignment_role']);
            $table->index(['event_id', 'assignment_status']);
            $table->index(['assignment_role', 'assignment_status']);
            $table->index(['is_supervisor', 'can_approve_overrides']);
        });

        // Conflict resolution tracking and management
        $this->createTableSafe('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->string('conflict_id', 255)->unique()->index();
            $table->enum('conflict_type', [
                'duplicate_checkin',
                'ticket_already_used',
                'timestamp_mismatch',
                'staff_permission_change',
                'device_data_corruption',
                'concurrent_modification',
                'data_integrity_violation'
            ])->index();
            $table->string('entity_type', 100)->index(); // ticket, booking, etc.
            $table->bigInteger('entity_id')->index();
            $table->bigInteger('event_id')->nullable()->index();
            $table->string('device_id', 255)->nullable()->index();
            $table->bigInteger('staff_id')->nullable()->index();
            $table->json('offline_data'); // Data from offline operation
            $table->json('online_data'); // Current online data
            $table->json('conflict_details'); // Detailed conflict analysis
            $table->enum('resolution_strategy', [
                'offline_wins',
                'online_wins',
                'merge_data',
                'manual_resolution',
                'create_duplicate',
                'reject_offline'
            ])->nullable()->index();
            $table->json('resolution_data')->nullable(); // Resolution details
            $table->enum('conflict_status', ['detected', 'analyzing', 'resolved', 'escalated'])->default('detected')->index();
            $table->bigInteger('resolved_by_staff_id')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->integer('resolution_priority')->default(5)->index(); // 1=highest, 10=lowest
            $table->boolean('requires_manual_intervention')->default(false)->index();
            $table->timestamp('detected_at')->index();
            $table->timestamps();

            $table->index(['conflict_status', 'resolution_priority']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['conflict_type', 'conflict_status']);
            $table->index(['requires_manual_intervention', 'detected_at']);
        });

        // Synchronization status and monitoring
        $this->createTableSafe('sync_status_log', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 255)->index();
            $table->bigInteger('event_id')->nullable()->index();
            $table->enum('sync_type', ['full_sync', 'incremental_sync', 'conflict_resolution', 'emergency_sync'])->index();
            $table->enum('sync_status', ['started', 'in_progress', 'completed', 'failed', 'partial'])->index();
            $table->timestamp('sync_started_at')->index();
            $table->timestamp('sync_completed_at')->nullable();
            $table->integer('records_processed')->default(0);
            $table->integer('records_synced')->default(0);
            $table->integer('conflicts_detected')->default(0);
            $table->integer('conflicts_resolved')->default(0);
            $table->json('sync_details'); // Detailed sync information
            $table->json('error_details')->nullable(); // Error information if failed
            $table->text('sync_notes')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->decimal('success_rate', 5, 2)->nullable(); // Percentage of successful operations
            $table->timestamps();

            $table->index(['sync_status', 'sync_started_at']);
            $table->index(['device_id', 'sync_type']);
            $table->index(['event_id', 'sync_status']);
        });

        // Operations console session tracking
        $this->createTableSafe('operations_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 255)->unique()->index();
            $table->bigInteger('staff_id')->index();
            $table->bigInteger('event_id')->index();
            $table->string('device_id', 255)->index();
            $table->enum('session_status', ['active', 'inactive', 'expired', 'terminated'])->default('active')->index();
            $table->timestamp('session_started_at')->index();
            $table->timestamp('session_ended_at')->nullable();
            $table->timestamp('last_activity_at')->index();
            $table->boolean('is_offline_capable')->default(false)->index();
            $table->boolean('is_currently_offline')->default(false)->index();
            $table->json('session_permissions'); // Current session permissions
            $table->json('session_metadata'); // Additional session information
            $table->integer('operations_performed')->default(0);
            $table->integer('overrides_used')->default(0);
            $table->integer('conflicts_encountered')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'session_status']);
            $table->index(['event_id', 'session_status']);
            $table->index(['device_id', 'session_status']);
            $table->index(['is_currently_offline', 'last_activity_at']);
        });

        // Override pattern detection and alerting
        $this->createTableSafe('override_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('pattern_name', 100)->index();
            $table->string('pattern_type', 50)->index(); // frequency, time_based, staff_based, etc.
            $table->text('pattern_description');
            $table->json('pattern_rules'); // Pattern detection rules
            $table->json('pattern_thresholds'); // Thresholds for triggering alerts
            $table->boolean('is_active')->default(true)->index();
            $table->integer('detection_count')->default(0);
            $table->timestamp('last_detected_at')->nullable();
            $table->enum('alert_level', ['info', 'warning', 'critical'])->default('warning')->index();
            $table->json('alert_configuration'); // Alert settings and recipients
            $table->timestamps();

            $table->index(['pattern_type', 'is_active']);
            $table->index(['alert_level', 'is_active']);
        });

        // Zone definitions and management
        $this->createTableSafe('event_zones', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('event_id')->index();
            $table->string('zone_code', 50)->index();
            $table->string('zone_name', 255);
            $table->text('zone_description')->nullable();
            $table->enum('zone_type', ['entrance', 'vip', 'general', 'backstage', 'security', 'medical', 'exit'])->index();
            $table->json('zone_permissions'); // Required permissions for this zone
            $table->json('zone_configuration'); // Zone-specific settings
            $table->boolean('requires_supervisor')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->integer('max_concurrent_staff')->default(10);
            $table->json('zone_metadata')->nullable(); // Additional zone information
            $table->timestamps();

            $table->unique(['event_id', 'zone_code']);
            $table->index(['zone_type', 'is_active']);
            $table->index(['requires_supervisor', 'is_active']);
        });
    }

    /**
     * Helper to safely create tables
     */
    private function createTableSafe($tableName, $callback)
    {
        if (!Schema::hasTable($tableName)) {
            try {
                Schema::create($tableName, $callback);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                fwrite(STDERR, "DEBUG EXCEPTION: $tableName - $msg\n");
                // If it's an "already exists" error, ignore it
                if (str_contains($msg, 'already exists')) {
                    fwrite(STDERR, "IGNORING: Table $tableName exists.\n");
                    return;
                }
                throw $e;
            }
        } else {
            fwrite(STDERR, "INFO: Table $tableName already exists (Schema::hasTable).\n");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_zones');
        Schema::dropIfExists('override_patterns');
        Schema::dropIfExists('operations_sessions');
        Schema::dropIfExists('sync_status_log');
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('staff_assignments');
        Schema::dropIfExists('operations_devices');
        Schema::dropIfExists('staff_overrides');
        Schema::dropIfExists('offline_checkin_queue');
    }
}