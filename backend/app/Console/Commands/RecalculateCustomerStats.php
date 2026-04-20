<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Facades\Tenancy;
use App\Models\Tenant;
use App\Models\Customer;

class RecalculateCustomerStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customers:recalculate-stats {--tenant= : Specific tenant ID (required)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate purchase statistics for all customers';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantId = $this->option('tenant');

        if (!$tenantId) {
            $this->error('The --tenant flag is required for this command.');
            $this->info('Usage: php artisan customers:recalculate-stats --tenant=<tenant-id>');
            return Command::FAILURE;
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("Tenant not found: {$tenantId}");
            return Command::FAILURE;
        }

        $this->processTenant($tenant);

        $this->info('Customer stats recalculation completed!');
        return Command::SUCCESS;
    }

    private function processTenant(Tenant $tenant)
    {
        $this->info("Processing tenant: {$tenant->id}");

        Tenancy::initialize($tenant);

        try {
            $customers = Customer::all();
            $count = 0;

            foreach ($customers as $customer) {
                try {
                    $customer->updatePurchaseStats();
                    $count++;
                } catch (\Exception $e) {
                    $this->warn("  Error updating customer {$customer->id}: {$e->getMessage()}");
                }
            }

            $this->info("  Updated {$count} customers");
        } catch (\Exception $e) {
            $this->error("  Error: {$e->getMessage()}");
        } finally {
            Tenancy::end();
        }
    }
}
