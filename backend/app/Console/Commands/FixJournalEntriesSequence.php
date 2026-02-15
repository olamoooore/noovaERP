<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;

class FixJournalEntriesSequence extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:fix-sequences';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix all id sequences for all tenants';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Fixing sequences for all tenants...');
        
        $tenants = Tenant::all();
        
        $tables = [
            'journal_entries',
            'journal_entry_lines',
            'invoices',
            'invoice_lines',
            'payments',
            'web_orders',
            'web_order_items',
        ];
        
        foreach ($tenants as $tenant) {
            $this->info("Processing tenant: {$tenant->id}");
            
            $tenant->run(function () use ($tables) {
                foreach ($tables as $table) {
                    try {
                        // Check if table exists
                        if (!\Schema::hasTable($table)) {
                            continue;
                        }
                        
                        // Get the max id from the table
                        $maxId = DB::table($table)->max('id') ?? 0;
                        
                        if ($maxId > 0) {
                            // Reset the sequence
                            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), {$maxId})");
                            $this->info("  Fixed {$table} sequence to {$maxId}");
                        }
                    } catch (\Exception $e) {
                        $this->error("  Error fixing {$table}: {$e->getMessage()}");
                    }
                }
            });
        }
        
        $this->info('Done!');
        return Command::SUCCESS;
    }
}
