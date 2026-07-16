<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnsureEventManagementSchema extends Command
{
    protected $signature = 'event:ensure-schema {--tenant= : Specific tenant ID (required)}';

    protected $description = 'Ensure EventManagement columns exist on the events table (idempotent)';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        if (! $tenantId) {
            $this->error('The --tenant flag is required for this command.');
            $this->info('Usage: php artisan event:ensure-schema --tenant=<tenant-id>');

            return Command::FAILURE;
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant not found: {$tenantId}");

            return Command::FAILURE;
        }

        $this->info("Processing tenant: {$tenant->id}");

        return $tenant->run(function () {
            return $this->processSchema();
        });
    }

    /**
     * Process schema within tenant context.
     */
    protected function processSchema(): int
    {
        $columns = [
            'price' => 'ALTER TABLE events ADD COLUMN IF NOT EXISTS price NUMERIC(10,2) DEFAULT 0 NOT NULL',
            'image_url' => 'ALTER TABLE events ADD COLUMN IF NOT EXISTS image_url TEXT NULL',
            'start_date' => 'ALTER TABLE events ADD COLUMN IF NOT EXISTS start_date TIMESTAMP NULL',
            'end_date' => 'ALTER TABLE events ADD COLUMN IF NOT EXISTS end_date TIMESTAMP NULL',
        ];

        foreach ($columns as $column => $sql) {
            DB::statement($sql);
            $this->line("  ✓ {$column}");
        }

        $this->info('EventManagement schema ensured.');

        return 0;
    }
}
