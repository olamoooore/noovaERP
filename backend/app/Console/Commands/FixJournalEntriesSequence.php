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
    protected $signature = 'db:fix-sequences {--tenant= : Specific tenant ID (required)}';

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
        $tenantId = $this->option('tenant');
        
        if (!$tenantId) {
            $this->error('The --tenant flag is required for this command.');
            $this->info('Usage: php artisan db:fix-sequences --tenant=<tenant-id>');
            return Command::FAILURE;
        }
        
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("Tenant not found: {$tenantId}");
            return Command::FAILURE;
        }
        
        $this->info("Fixing sequences for tenant: {$tenant->id}");
        
        $tables = [
            'journal_entries',
            'journal_entry_lines',
            'invoices',
            'invoice_lines',
            'payments',
            'web_orders',
            'web_order_items',
            'products',
            'product_attributes',
            'product_attribute_values',
            'product_features',
            'product_feature_options',
            'product_custom_fields',
            'product_categories',
            'product_units',
        ];
        
        $tenant->run(function () use ($tables) {
            foreach ($tables as $table) {
                try {
                    if (!\Schema::hasTable($table)) {
                        continue;
                    }
                    
                    $maxId = DB::table($table)->max('id') ?? 0;
                    
                    if ($maxId > 0) {
                        DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), {$maxId})");
                        $this->info("  Fixed {$table} sequence to {$maxId}");
                    }
                } catch (\Exception $e) {
                    $this->error("  Error fixing {$table}: {$e->getMessage()}");
                }
            }
        });
        
        $this->info('Done!');
        return Command::SUCCESS;
    }
}
