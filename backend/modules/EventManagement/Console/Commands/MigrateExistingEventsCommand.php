<?php

namespace Modules\EventManagement\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Models\EventType;

/**
 * Command to migrate existing events to Phase 4 schema
 *
 * This command ensures backward compatibility by:
 * - Setting event_type_id to Concert for existing events
 * - Setting ticket_acquisition_mode to 'buy' for existing events
 * - Setting sale_start_date and sale_end_date for existing events
 * - Verifying referential integrity is maintained
 */
class MigrateExistingEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'event-management:migrate-existing-events
                            {--dry-run : Run without making changes}
                            {--verify : Verify referential integrity after migration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate existing events to Phase 4 schema with backward compatibility';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $verify = $this->option('verify');

        $this->info('Starting migration of existing events to Phase 4 schema...');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        try {
            DB::beginTransaction();

            // Step 1: Ensure Concert event type exists
            $this->info('Step 1: Ensuring Concert event type exists...');
            $concertType = $this->ensureConcertTypeExists($dryRun);

            if (! $concertType) {
                $this->error('Failed to ensure Concert event type exists');
                DB::rollBack();

                return Command::FAILURE;
            }

            $this->info("Concert event type ID: {$concertType->id}");

            // Step 2: Find events that need migration
            $eventsToMigrate = $this->findEventsNeedingMigration();
            $count = $eventsToMigrate->count();

            $this->info("Found {$count} events that need migration");

            if ($count === 0) {
                $this->info('No events need migration. All events are already up to date.');
                DB::rollBack();

                return Command::SUCCESS;
            }

            // Step 3: Migrate event_type_id
            $this->info('Step 2: Setting event_type_id to Concert for existing events...');
            $updatedTypeCount = $this->migrateEventTypes($concertType->id, $dryRun);
            $this->info("Updated event_type_id for {$updatedTypeCount} events");

            // Step 4: Migrate ticket_acquisition_mode
            $this->info('Step 3: Setting ticket_acquisition_mode to "buy" for existing events...');
            $updatedModeCount = $this->migrateAcquisitionMode($dryRun);
            $this->info("Updated ticket_acquisition_mode for {$updatedModeCount} events");

            // Step 5: Migrate sale window dates
            $this->info('Step 4: Setting sale window dates for existing events...');
            $updatedDatesCount = $this->migrateSaleWindowDates($dryRun);
            $this->info("Updated sale window dates for {$updatedDatesCount} events");

            // Step 6: Verify referential integrity
            if ($verify || ! $dryRun) {
                $this->info('Step 5: Verifying referential integrity...');
                $integrityCheck = $this->verifyReferentialIntegrity();

                if (! $integrityCheck['valid']) {
                    $this->error('Referential integrity check failed:');
                    foreach ($integrityCheck['errors'] as $error) {
                        $this->error("  - {$error}");
                    }
                    DB::rollBack();

                    return Command::FAILURE;
                }

                $this->info('Referential integrity verified successfully');
            }

            if ($dryRun) {
                DB::rollBack();
                $this->info('DRY RUN completed - no changes were made');
            } else {
                DB::commit();
                $this->info('Migration completed successfully!');
            }

            // Display summary
            $this->displaySummary($updatedTypeCount, $updatedModeCount, $updatedDatesCount);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Migration failed: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return Command::FAILURE;
        }
    }

    /**
     * Ensure Concert event type exists
     */
    protected function ensureConcertTypeExists(bool $dryRun): ?EventType
    {
        $concertType = EventType::where('slug', 'concert')->first();

        if (! $concertType) {
            $this->warn('Concert event type does not exist. Creating it...');

            if (! $dryRun) {
                $concertType = EventType::create([
                    'name' => 'Concert',
                    'slug' => 'concert',
                    'description' => 'Live music performance',
                    'is_active' => true,
                ]);
                $this->info('Concert event type created successfully');
            } else {
                // For dry run, create a mock object
                $concertType = new EventType([
                    'id' => 'mock-concert-id',
                    'name' => 'Concert',
                    'slug' => 'concert',
                ]);
            }
        }

        return $concertType;
    }

    /**
     * Find events that need migration
     */
    protected function findEventsNeedingMigration()
    {
        return DB::table('events')
            ->where(function ($query) {
                $query->whereNull('event_type_id')
                    ->orWhereNull('ticket_acquisition_mode')
                    ->orWhere('ticket_acquisition_mode', '')
                    ->orWhereNull('sale_start_date')
                    ->orWhereNull('sale_end_date');
            })
            ->get();
    }

    /**
     * Migrate event_type_id for existing events
     */
    protected function migrateEventTypes(string $concertTypeId, bool $dryRun): int
    {
        $query = DB::table('events')
            ->whereNull('event_type_id');

        if ($dryRun) {
            return $query->count();
        }

        return $query->update([
            'event_type_id' => $concertTypeId,
            'updated_at' => now(),
        ]);
    }

    /**
     * Migrate ticket_acquisition_mode for existing events
     */
    protected function migrateAcquisitionMode(bool $dryRun): int
    {
        $query = DB::table('events')
            ->where(function ($q) {
                $q->whereNull('ticket_acquisition_mode')
                    ->orWhere('ticket_acquisition_mode', '');
            });

        if ($dryRun) {
            return $query->count();
        }

        return $query->update([
            'ticket_acquisition_mode' => 'buy',
            'updated_at' => now(),
        ]);
    }

    /**
     * Migrate sale window dates for existing events
     */
    protected function migrateSaleWindowDates(bool $dryRun): int
    {
        $query = DB::table('events')
            ->where(function ($q) {
                $q->whereNull('sale_start_date')
                    ->orWhereNull('sale_end_date');
            });

        if ($dryRun) {
            return $query->count();
        }

        // Use raw SQL for COALESCE to handle null values properly
        return DB::statement('
            UPDATE events 
            SET 
                sale_start_date = COALESCE(sale_start_date, created_at),
                sale_end_date = COALESCE(sale_end_date, event_date),
                updated_at = ?
            WHERE sale_start_date IS NULL OR sale_end_date IS NULL
        ', [now()]);
    }

    /**
     * Verify referential integrity after migration
     */
    protected function verifyReferentialIntegrity(): array
    {
        $errors = [];

        // Check 1: All events have valid event_type_id
        $invalidEventTypes = DB::table('events')
            ->leftJoin('event_types', 'events.event_type_id', '=', 'event_types.id')
            ->whereNull('event_types.id')
            ->whereNotNull('events.event_type_id')
            ->count();

        if ($invalidEventTypes > 0) {
            $errors[] = "{$invalidEventTypes} events have invalid event_type_id references";
        }

        // Check 2: All events have valid ticket_acquisition_mode
        $invalidModes = DB::table('events')
            ->whereNotIn('ticket_acquisition_mode', ['buy', 'bid'])
            ->count();

        if ($invalidModes > 0) {
            $errors[] = "{$invalidModes} events have invalid ticket_acquisition_mode values";
        }

        // Check 3: All events have sale_start_date and sale_end_date
        $missingSaleDates = DB::table('events')
            ->where(function ($q) {
                $q->whereNull('sale_start_date')
                    ->orWhereNull('sale_end_date');
            })
            ->count();

        if ($missingSaleDates > 0) {
            $errors[] = "{$missingSaleDates} events are missing sale window dates";
        }

        // Check 4: All events have sale_end_date > sale_start_date
        $invalidSaleWindows = DB::table('events')
            ->whereRaw('sale_end_date <= sale_start_date')
            ->count();

        if ($invalidSaleWindows > 0) {
            $errors[] = "{$invalidSaleWindows} events have invalid sale windows (end <= start)";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Display migration summary
     */
    protected function displaySummary(int $typeCount, int $modeCount, int $datesCount): void
    {
        $this->newLine();
        $this->info('=== Migration Summary ===');
        $this->table(
            ['Field', 'Events Updated'],
            [
                ['event_type_id', $typeCount],
                ['ticket_acquisition_mode', $modeCount],
                ['sale window dates', $datesCount],
            ]
        );
    }
}
