<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\EventManagement\Services\PIIVaultService;

/**
 * Migration: Migrate Existing PII Data to Vault - Task 4.4
 *
 * Migrates existing PII data from operational tables to the secure PII vault
 * using dual-write pattern during transition with validation and rollback procedures.
 *
 * Requirements: 20.1, 20.4
 */
return new class extends Migration
{
    private PIIVaultService $piiVaultService;

    private array $migrationLog = [];

    private int $batchSize = 100;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Initialize PII vault service
        $this->piiVaultService = app(PIIVaultService::class);

        echo "Starting PII data migration to vault...\n";

        try {
            // Step 1: Create migration tracking table
            $this->createMigrationTrackingTable();

            // Step 2: Migrate customer PII data
            $this->migrateCustomerPII();

            // Step 3: Migrate attendee PII data
            $this->migrateAttendeePII();

            // Step 4: Migrate booking contact PII data
            $this->migrateBookingContactPII();

            // Step 5: Validate migration completeness
            $this->validateMigration();

            // Step 6: Log migration summary
            $this->logMigrationSummary();

            echo "PII data migration completed successfully.\n";

        } catch (\Exception $e) {
            echo 'PII data migration failed: '.$e->getMessage()."\n";

            // Log the error
            // Log failed (skipped to avoid masking original error)
            // throw $e; is sufficient

            throw $e;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        echo "Starting PII vault migration rollback...\n";

        try {
            // Step 1: Restore customer PII data
            $this->restoreCustomerPII();

            // Step 2: Restore attendee PII data
            $this->restoreAttendeePII();

            // Step 3: Restore booking contact PII data
            $this->restoreBookingContactPII();

            // Step 4: Drop migration tracking table
            Schema::dropIfExists('pii_migration_log');

            echo "PII vault migration rollback completed.\n";

        } catch (\Exception $e) {
            echo 'PII vault migration rollback failed: '.$e->getMessage()."\n";
            throw $e;
        }
    }

    /**
     * Create migration tracking table for audit and rollback purposes.
     */
    private function createMigrationTrackingTable(): void
    {
        if (! Schema::hasTable('pii_migration_log')) {
            Schema::create('pii_migration_log', function (Blueprint $table) {
                $table->id();
                $table->string('migration_step', 100);
                $table->string('entity_type', 100);
                $table->bigInteger('entity_id');
                $table->string('field_name', 100);
                $table->bigInteger('vault_id')->nullable();
                $table->enum('migration_status', ['pending', 'completed', 'failed', 'rolled_back']);
                $table->text('original_value_hash')->nullable(); // Hash for validation
                $table->string('error_message')->nullable();
                $table->timestamp('migrated_at');

                $table->index(['entity_type', 'entity_id']);
                $table->index(['migration_status']);
                $table->index(['migrated_at']);
            });
        }
    }

    /**
     * Migrate customer PII data to vault.
     */
    private function migrateCustomerPII(): void
    {
        echo "Migrating customer PII data...\n";

        $piiFields = [
            'email' => 'customer_data',
            'phone' => 'customer_data',
            'name' => 'customer_data',
            'billing_address' => 'customer_data',
            'shipping_address' => 'customer_data',
        ];

        $totalCustomers = DB::table('customers')->count();
        $processedCount = 0;
        $migratedCount = 0;
        $errorCount = 0;

        echo "Processing {$totalCustomers} customers...\n";

        DB::table('customers')->orderBy('id')->chunk($this->batchSize, function ($customers) use ($piiFields, &$processedCount, &$migratedCount, &$errorCount, $totalCustomers) {
            foreach ($customers as $customer) {
                $processedCount++;

                foreach ($piiFields as $fieldName => $retentionPolicy) {
                    if (! empty($customer->$fieldName)) {
                        try {
                            // Store PII in vault
                            $result = $this->piiVaultService->storePII(
                                'customer',
                                $customer->id,
                                $fieldName,
                                $customer->$fieldName,
                                [
                                    'retention_policy' => $retentionPolicy,
                                    'data_classification' => 'sensitive',
                                    'metadata' => [
                                        'migrated_from' => 'customers',
                                        'migration_date' => Carbon::now()->toISOString(),
                                    ],
                                ]
                            );

                            if ($result['success']) {
                                // Log successful migration
                                DB::table('pii_migration_log')->insert([
                                    'migration_step' => 'customer_pii_migration',
                                    'entity_type' => 'customer',
                                    'entity_id' => $customer->id,
                                    'field_name' => $fieldName,
                                    'vault_id' => $result['vault_id'],
                                    'migration_status' => 'completed',
                                    'original_value_hash' => hash('sha256', $customer->$fieldName),
                                    'migrated_at' => Carbon::now(),
                                ]);

                                $migratedCount++;
                            }

                        } catch (\Exception $e) {
                            $errorCount++;

                            // Log migration error
                            DB::table('pii_migration_log')->insert([
                                'migration_step' => 'customer_pii_migration',
                                'entity_type' => 'customer',
                                'entity_id' => $customer->id,
                                'field_name' => $fieldName,
                                'migration_status' => 'failed',
                                'error_message' => $e->getMessage(),
                                'migrated_at' => Carbon::now(),
                            ]);

                            echo "Error migrating customer {$customer->id} field {$fieldName}: ".$e->getMessage()."\n";
                        }
                    }
                }

                if ($processedCount % 50 === 0) {
                    echo "Processed {$processedCount}/{$totalCustomers} customers...\n";
                }
            }
        });

        echo "Customer PII migration completed: {$migratedCount} fields migrated, {$errorCount} errors\n";
    }

    /**
     * Migrate attendee PII data to vault.
     */
    private function migrateAttendeePII(): void
    {
        echo "Migrating attendee PII data...\n";

        $piiFields = [
            'attendee_name' => 'booking_data',
            'attendee_email' => 'booking_data',
            'attendee_phone' => 'booking_data',
        ];

        $totalAttendees = DB::table('booking_attendees')->count();
        $processedCount = 0;
        $migratedCount = 0;
        $errorCount = 0;

        echo "Processing {$totalAttendees} attendees...\n";

        DB::table('booking_attendees')->orderBy('id')->chunk($this->batchSize, function ($attendees) use ($piiFields, &$processedCount, &$migratedCount, &$errorCount, $totalAttendees) {
            foreach ($attendees as $attendee) {
                $processedCount++;

                foreach ($piiFields as $fieldName => $retentionPolicy) {
                    if (! empty($attendee->$fieldName)) {
                        try {
                            // Store PII in vault
                            $result = $this->piiVaultService->storePII(
                                'attendee',
                                $attendee->id,
                                $fieldName,
                                $attendee->$fieldName,
                                [
                                    'retention_policy' => $retentionPolicy,
                                    'data_classification' => 'sensitive',
                                    'metadata' => [
                                        'migrated_from' => 'booking_attendees',
                                        'booking_id' => $attendee->booking_id,
                                        'migration_date' => Carbon::now()->toISOString(),
                                    ],
                                ]
                            );

                            if ($result['success']) {
                                // Log successful migration
                                DB::table('pii_migration_log')->insert([
                                    'migration_step' => 'attendee_pii_migration',
                                    'entity_type' => 'attendee',
                                    'entity_id' => $attendee->id,
                                    'field_name' => $fieldName,
                                    'vault_id' => $result['vault_id'],
                                    'migration_status' => 'completed',
                                    'original_value_hash' => hash('sha256', $attendee->$fieldName),
                                    'migrated_at' => Carbon::now(),
                                ]);

                                $migratedCount++;
                            }

                        } catch (\Exception $e) {
                            $errorCount++;

                            // Log migration error
                            DB::table('pii_migration_log')->insert([
                                'migration_step' => 'attendee_pii_migration',
                                'entity_type' => 'attendee',
                                'entity_id' => $attendee->id,
                                'field_name' => $fieldName,
                                'migration_status' => 'failed',
                                'error_message' => $e->getMessage(),
                                'migrated_at' => Carbon::now(),
                            ]);

                            echo "Error migrating attendee {$attendee->id} field {$fieldName}: ".$e->getMessage()."\n";
                        }
                    }
                }

                if ($processedCount % 50 === 0) {
                    echo "Processed {$processedCount}/{$totalAttendees} attendees...\n";
                }
            }
        });

        echo "Attendee PII migration completed: {$migratedCount} fields migrated, {$errorCount} errors\n";
    }

    /**
     * Migrate booking contact PII data to vault.
     */
    private function migrateBookingContactPII(): void
    {
        echo "Migrating booking contact PII data...\n";

        $piiFields = [
            'buyer_name' => 'booking_data',
            'buyer_email' => 'booking_data',
            'buyer_phone' => 'booking_data',
        ];

        $totalBookings = DB::table('bookings')->count();
        $processedCount = 0;
        $migratedCount = 0;
        $errorCount = 0;

        echo "Processing {$totalBookings} bookings...\n";

        DB::table('bookings')->orderBy('id')->chunk($this->batchSize, function ($bookings) use ($piiFields, &$processedCount, &$migratedCount, &$errorCount, $totalBookings) {
            foreach ($bookings as $booking) {
                $processedCount++;

                foreach ($piiFields as $fieldName => $retentionPolicy) {
                    if (! empty($booking->$fieldName)) {
                        try {
                            // Store PII in vault
                            $result = $this->piiVaultService->storePII(
                                'booking',
                                $booking->id,
                                $fieldName,
                                $booking->$fieldName,
                                [
                                    'retention_policy' => $retentionPolicy,
                                    'data_classification' => 'sensitive',
                                    'metadata' => [
                                        'migrated_from' => 'bookings',
                                        'customer_id' => $booking->customer_id,
                                        'event_id' => $booking->event_id,
                                        'migration_date' => Carbon::now()->toISOString(),
                                    ],
                                ]
                            );

                            if ($result['success']) {
                                // Log successful migration
                                DB::table('pii_migration_log')->insert([
                                    'migration_step' => 'booking_pii_migration',
                                    'entity_type' => 'booking',
                                    'entity_id' => $booking->id,
                                    'field_name' => $fieldName,
                                    'vault_id' => $result['vault_id'],
                                    'migration_status' => 'completed',
                                    'original_value_hash' => hash('sha256', $booking->$fieldName),
                                    'migrated_at' => Carbon::now(),
                                ]);

                                $migratedCount++;
                            }

                        } catch (\Exception $e) {
                            $errorCount++;

                            // Log migration error
                            DB::table('pii_migration_log')->insert([
                                'migration_step' => 'booking_pii_migration',
                                'entity_type' => 'booking',
                                'entity_id' => $booking->id,
                                'field_name' => $fieldName,
                                'migration_status' => 'failed',
                                'error_message' => $e->getMessage(),
                                'migrated_at' => Carbon::now(),
                            ]);

                            echo "Error migrating booking {$booking->id} field {$fieldName}: ".$e->getMessage()."\n";
                        }
                    }
                }

                if ($processedCount % 50 === 0) {
                    echo "Processed {$processedCount}/{$totalBookings} bookings...\n";
                }
            }
        });

        echo "Booking PII migration completed: {$migratedCount} fields migrated, {$errorCount} errors\n";
    }

    /**
     * Validate migration completeness and data integrity.
     */
    private function validateMigration(): void
    {
        echo "Validating migration completeness...\n";

        // Count total records that should have been migrated
        $expectedMigrations = 0;
        $actualMigrations = 0;
        $failedMigrations = 0;

        // Validate customer migrations
        $customerPiiFields = ['email', 'phone', 'name', 'billing_address', 'shipping_address'];
        foreach ($customerPiiFields as $field) {
            $expectedCount = DB::table('customers')->whereNotNull($field)->where($field, '!=', '')->count();
            $actualCount = DB::table('pii_migration_log')
                ->where('entity_type', 'customer')
                ->where('field_name', $field)
                ->where('migration_status', 'completed')
                ->count();
            $failedCount = DB::table('pii_migration_log')
                ->where('entity_type', 'customer')
                ->where('field_name', $field)
                ->where('migration_status', 'failed')
                ->count();

            $expectedMigrations += $expectedCount;
            $actualMigrations += $actualCount;
            $failedMigrations += $failedCount;

            echo "Customer {$field}: Expected {$expectedCount}, Migrated {$actualCount}, Failed {$failedCount}\n";
        }

        // Validate attendee migrations
        $attendeePiiFieldsv2 = ['attendee_name', 'attendee_email', 'attendee_phone'];
        foreach ($attendeePiiFieldsv2 as $field) {
            echo "Checking attendee field: $field\n";
            $expectedCount = DB::table('booking_attendees')->whereNotNull($field)->where($field, '!=', '')->count();
            $actualCount = DB::table('pii_migration_log')
                ->where('entity_type', 'attendee')
                ->where('field_name', $field)
                ->where('migration_status', 'completed')
                ->count();
            $failedCount = DB::table('pii_migration_log')
                ->where('entity_type', 'attendee')
                ->where('field_name', $field)
                ->where('migration_status', 'failed')
                ->count();

            $expectedMigrations += $expectedCount;
            $actualMigrations += $actualCount;
            $failedMigrations += $failedCount;

            echo "Attendee {$field}: Expected {$expectedCount}, Migrated {$actualCount}, Failed {$failedCount}\n";
        }

        // Validate booking migrations
        $bookingPiiFields = ['buyer_name', 'buyer_email', 'buyer_phone'];
        foreach ($bookingPiiFields as $field) {
            $expectedCount = DB::table('bookings')->whereNotNull($field)->where($field, '!=', '')->count();
            $actualCount = DB::table('pii_migration_log')
                ->where('entity_type', 'booking')
                ->where('field_name', $field)
                ->where('migration_status', 'completed')
                ->count();
            $failedCount = DB::table('pii_migration_log')
                ->where('entity_type', 'booking')
                ->where('field_name', $field)
                ->where('migration_status', 'failed')
                ->count();

            $expectedMigrations += $expectedCount;
            $actualMigrations += $actualCount;
            $failedMigrations += $failedCount;

            echo "Booking {$field}: Expected {$expectedCount}, Migrated {$actualCount}, Failed {$failedCount}\n";
        }

        $successRate = $expectedMigrations > 0 ? ($actualMigrations / $expectedMigrations) * 100 : 100;

        echo "\nMigration Summary:\n";
        echo "Expected migrations: {$expectedMigrations}\n";
        echo "Successful migrations: {$actualMigrations}\n";
        echo "Failed migrations: {$failedMigrations}\n";
        echo 'Success rate: '.number_format($successRate, 2)."%\n";

        if ($successRate < 95) {
            throw new \RuntimeException("Migration success rate ({$successRate}%) is below acceptable threshold (95%)");
        }

        echo "Migration validation completed successfully.\n";
    }

    /**
     * Log migration summary for audit purposes.
     */
    private function logMigrationSummary(): void
    {
        $summary = [
            'migration_completed_at' => Carbon::now()->toISOString(),
            'total_records_migrated' => DB::table('pii_migration_log')->where('migration_status', 'completed')->count(),
            'total_records_failed' => DB::table('pii_migration_log')->where('migration_status', 'failed')->count(),
            'customer_records' => DB::table('pii_migration_log')->where('entity_type', 'customer')->where('migration_status', 'completed')->count(),
            'attendee_records' => DB::table('pii_migration_log')->where('entity_type', 'attendee')->where('migration_status', 'completed')->count(),
            'booking_records' => DB::table('pii_migration_log')->where('entity_type', 'booking')->where('migration_status', 'completed')->count(),
        ];

        DB::table('pii_migration_log')->insert([
            'migration_step' => 'migration_summary',
            'entity_type' => 'system',
            'entity_id' => 0,
            'field_name' => 'summary',
            'migration_status' => 'completed',
            'original_value_hash' => json_encode($summary),
            'migrated_at' => Carbon::now(),
        ]);

        echo "Migration summary logged.\n";
    }

    /**
     * Restore customer PII data from vault (rollback).
     */
    private function restoreCustomerPII(): void
    {
        echo "Restoring customer PII data...\n";

        $migrations = DB::table('pii_migration_log')
            ->where('entity_type', 'customer')
            ->where('migration_status', 'completed')
            ->get();

        foreach ($migrations as $migration) {
            try {
                // Get PII from vault
                $piiValue = $this->piiVaultService->retrievePII(
                    'customer',
                    $migration->entity_id,
                    $migration->field_name,
                    'Migration rollback'
                );

                if ($piiValue !== null) {
                    // Restore to original table
                    DB::table('customers')
                        ->where('id', $migration->entity_id)
                        ->update([$migration->field_name => $piiValue]);

                    // Mark as rolled back
                    DB::table('pii_migration_log')
                        ->where('id', $migration->id)
                        ->update(['migration_status' => 'rolled_back']);
                }

            } catch (\Exception $e) {
                echo "Error restoring customer {$migration->entity_id} field {$migration->field_name}: ".$e->getMessage()."\n";
            }
        }

        echo "Customer PII data restoration completed.\n";
    }

    /**
     * Restore attendee PII data from vault (rollback).
     */
    private function restoreAttendeePII(): void
    {
        echo "Restoring attendee PII data...\n";

        $migrations = DB::table('pii_migration_log')
            ->where('entity_type', 'attendee')
            ->where('migration_status', 'completed')
            ->get();

        foreach ($migrations as $migration) {
            try {
                // Get PII from vault
                $piiValue = $this->piiVaultService->retrievePII(
                    'attendee',
                    $migration->entity_id,
                    $migration->field_name,
                    'Migration rollback'
                );

                if ($piiValue !== null) {
                    // Restore to original table
                    DB::table('booking_attendees')
                        ->where('id', $migration->entity_id)
                        ->update([$migration->field_name => $piiValue]);

                    // Mark as rolled back
                    DB::table('pii_migration_log')
                        ->where('id', $migration->id)
                        ->update(['migration_status' => 'rolled_back']);
                }

            } catch (\Exception $e) {
                echo "Error restoring attendee {$migration->entity_id} field {$migration->field_name}: ".$e->getMessage()."\n";
            }
        }

        echo "Attendee PII data restoration completed.\n";
    }

    /**
     * Restore booking contact PII data from vault (rollback).
     */
    private function restoreBookingContactPII(): void
    {
        echo "Restoring booking contact PII data...\n";

        $migrations = DB::table('pii_migration_log')
            ->where('entity_type', 'booking')
            ->where('migration_status', 'completed')
            ->get();

        foreach ($migrations as $migration) {
            try {
                // Get PII from vault
                $piiValue = $this->piiVaultService->retrievePII(
                    'booking',
                    $migration->entity_id,
                    $migration->field_name,
                    'Migration rollback'
                );

                if ($piiValue !== null) {
                    // Restore to original table
                    DB::table('bookings')
                        ->where('id', $migration->entity_id)
                        ->update([$migration->field_name => $piiValue]);

                    // Mark as rolled back
                    DB::table('pii_migration_log')
                        ->where('id', $migration->id)
                        ->update(['migration_status' => 'rolled_back']);
                }

            } catch (\Exception $e) {
                echo "Error restoring booking {$migration->entity_id} field {$migration->field_name}: ".$e->getMessage()."\n";
            }
        }

        echo "Booking contact PII data restoration completed.\n";
    }
};
