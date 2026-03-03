<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnsureEventManagementSchema extends Command
{
    protected $signature = 'event:ensure-schema';
    protected $description = 'Ensure EventManagement columns exist on the events table (idempotent)';

    public function handle(): int
    {
        $columns = [
            'price'      => 'ALTER TABLE events ADD COLUMN IF NOT EXISTS price NUMERIC(10,2) DEFAULT 0 NOT NULL',
            'image_url'  => 'ALTER TABLE events ADD COLUMN IF NOT EXISTS image_url TEXT NULL',
            'start_date' => 'ALTER TABLE events ADD COLUMN IF NOT EXISTS start_date TIMESTAMP NULL',
            'end_date'   => 'ALTER TABLE events ADD COLUMN IF NOT EXISTS end_date TIMESTAMP NULL',
        ];

        foreach ($columns as $column => $sql) {
            DB::statement($sql);
            $this->line("  ✓ {$column}");
        }

        $this->info('EventManagement schema ensured.');
        return 0;
    }
}
