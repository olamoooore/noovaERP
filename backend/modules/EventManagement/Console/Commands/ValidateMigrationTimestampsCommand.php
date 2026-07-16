<?php

namespace Modules\EventManagement\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Migration Timestamp Validation Command
 *
 * Validates that all migration files have unique timestamps to prevent
 * deployment conflicts and ensures proper migration sequencing.
 */
class ValidateMigrationTimestampsCommand extends Command
{
    protected $signature = 'event-management:validate-migrations 
                           {--fix : Automatically fix timestamp conflicts}
                           {--check-dependencies : Check migration dependencies}
                           {--verbose : Show detailed output}';

    protected $description = 'Validate migration timestamps and dependencies for Event Management module';

    private array $conflicts = [];

    private array $dependencies = [];

    private array $suggestions = [];

    public function handle(): int
    {
        $this->info('🔍 Validating Event Management Migration Timestamps');
        $this->line('================================================');

        $migrationPath = base_path('backend/modules/EventManagement/Database/Migrations');

        if (! File::exists($migrationPath)) {
            $this->error('Migration directory not found: '.$migrationPath);

            return 1;
        }

        // Get all migration files
        $migrations = $this->getMigrationFiles($migrationPath);

        if ($migrations->isEmpty()) {
            $this->warn('No migration files found.');

            return 0;
        }

        // Validate timestamps
        $this->validateTimestamps($migrations);

        // Check dependencies if requested
        if ($this->option('check-dependencies')) {
            $this->validateDependencies($migrations);
        }

        // Display results
        $this->displayResults();

        // Auto-fix if requested
        if ($this->option('fix') && ! empty($this->conflicts)) {
            return $this->fixConflicts($migrationPath);
        }

        return empty($this->conflicts) ? 0 : 1;
    }

    /**
     * Get all migration files from directory
     */
    private function getMigrationFiles(string $path): Collection
    {
        $files = File::files($path);

        return collect($files)
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->filter(fn ($file) => $file->getFilename() !== '.gitkeep')
            ->map(fn ($file) => [
                'filename' => $file->getFilename(),
                'path' => $file->getPathname(),
                'timestamp' => $this->extractTimestamp($file->getFilename()),
                'name' => $this->extractMigrationName($file->getFilename()),
            ])
            ->sortBy('timestamp');
    }

    /**
     * Extract timestamp from migration filename
     */
    private function extractTimestamp(string $filename): string
    {
        if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $filename, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Extract migration name from filename
     */
    private function extractMigrationName(string $filename): string
    {
        return preg_replace('/^(\d{4}_\d{2}_\d{2}_\d{6})_(.+)\.php$/', '$2', $filename);
    }

    /**
     * Validate migration timestamps for conflicts
     */
    private function validateTimestamps(Collection $migrations): void
    {
        $timestampGroups = $migrations->groupBy('timestamp');

        foreach ($timestampGroups as $timestamp => $group) {
            if ($group->count() > 1) {
                $this->conflicts[$timestamp] = $group->pluck('filename')->toArray();

                if ($this->option('verbose')) {
                    $this->warn("⚠️  Timestamp conflict: {$timestamp}");
                    foreach ($group as $migration) {
                        $this->line("   - {$migration['filename']}");
                    }
                }
            }
        }
    }

    /**
     * Validate migration dependencies
     */
    private function validateDependencies(Collection $migrations): void
    {
        $dependencyRules = [
            'pii_vault' => ['outbox_pattern', 'immutable_facts'],
            'customer_hub' => ['pii_vault'],
            'fraud_prevention' => ['outbox_pattern'],
            'operations_hardening' => ['capacity_management'],
            'api_governance' => ['observability'],
        ];

        foreach ($migrations as $migration) {
            $migrationName = $migration['name'];

            foreach ($dependencyRules as $dependent => $dependencies) {
                if (str_contains($migrationName, $dependent)) {
                    foreach ($dependencies as $dependency) {
                        $dependencyExists = $migrations
                            ->where('timestamp', '<', $migration['timestamp'])
                            ->pluck('name')
                            ->contains(fn ($name) => str_contains($name, $dependency));

                        if (! $dependencyExists) {
                            $this->dependencies[] = [
                                'migration' => $migration['filename'],
                                'missing_dependency' => $dependency,
                                'suggestion' => "Migration '{$migrationName}' should run after '{$dependency}' migrations",
                            ];
                        }
                    }
                }
            }
        }
    }

    /**
     * Display validation results
     */
    private function displayResults(): void
    {
        $this->line('');

        if (empty($this->conflicts) && empty($this->dependencies)) {
            $this->info('✅ All migration timestamps are valid!');

            return;
        }

        if (! empty($this->conflicts)) {
            $this->error('❌ Migration Timestamp Conflicts Found:');
            foreach ($this->conflicts as $timestamp => $files) {
                $this->line("   Timestamp: {$timestamp}");
                foreach ($files as $file) {
                    $this->line("   - {$file}");
                }
                $this->line('');
            }
        }

        if (! empty($this->dependencies)) {
            $this->warn('⚠️  Potential Dependency Issues:');
            foreach ($this->dependencies as $issue) {
                $this->line("   {$issue['suggestion']}");
                $this->line("   File: {$issue['migration']}");
                $this->line('');
            }
        }

        $this->line('💡 Suggestions:');
        $this->line('   - Use --fix to automatically resolve timestamp conflicts');
        $this->line('   - Review dependency order before running migrations');
        $this->line('   - Test migrations in development environment first');
    }

    /**
     * Automatically fix timestamp conflicts
     */
    private function fixConflicts(string $migrationPath): int
    {
        $this->info('🔧 Fixing timestamp conflicts...');

        $allMigrations = $this->getMigrationFiles($migrationPath);
        $maxTimestamp = $allMigrations->max('timestamp');

        // Generate next available timestamp
        $nextTimestamp = $this->generateNextTimestamp($maxTimestamp);

        foreach ($this->conflicts as $timestamp => $files) {
            // Keep the first file, rename the others
            $filesToRename = array_slice($files, 1);

            foreach ($filesToRename as $filename) {
                $oldPath = $migrationPath.'/'.$filename;
                $newFilename = str_replace($timestamp, $nextTimestamp, $filename);
                $newPath = $migrationPath.'/'.$newFilename;

                if (File::move($oldPath, $newPath)) {
                    $this->info("✅ Renamed: {$filename} → {$newFilename}");
                    $nextTimestamp = $this->generateNextTimestamp($nextTimestamp);
                } else {
                    $this->error("❌ Failed to rename: {$filename}");

                    return 1;
                }
            }
        }

        $this->info('🎉 All timestamp conflicts resolved!');

        return 0;
    }

    /**
     * Generate next available timestamp
     */
    private function generateNextTimestamp(string $currentTimestamp): string
    {
        // Parse current timestamp
        $parts = explode('_', $currentTimestamp);
        $sequence = (int) $parts[3];

        // Increment sequence
        $sequence++;

        // Format back to timestamp
        return sprintf('%s_%s_%s_%06d', $parts[0], $parts[1], $parts[2], $sequence);
    }
}
