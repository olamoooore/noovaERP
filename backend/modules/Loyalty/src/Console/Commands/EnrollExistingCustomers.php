<?php

namespace Modules\Loyalty\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Tenant;
use Modules\Loyalty\Models\CustomerLoyalty;
use Modules\Loyalty\Models\LoyaltyProgram;

class EnrollExistingCustomers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loyalty:enroll-existing {--tenant= : Specific tenant ID to enroll customers for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enroll all existing customers in the loyalty program (runs for all tenants or specific tenant)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $specificTenant = $this->option('tenant');

        if ($specificTenant) {
            // Process specific tenant
            $this->processTenant($specificTenant);
        } else {
            // Process all tenants
            $this->processAllTenants();
        }

        return self::SUCCESS;
    }

    /**
     * Process all tenants
     */
    protected function processAllTenants(): void
    {
        $this->info('Processing all tenants for loyalty enrollment...');

        // Get all tenants using the Tenant model
        $tenants = Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return;
        }

        $this->info("Found {$tenants->count()} tenants to process.");

        foreach ($tenants as $tenant) {
            $this->newLine();
            $this->info("Processing tenant: {$tenant->id}");
            $this->processTenant($tenant->id);
        }
    }

    /**
     * Process a specific tenant
     */
    protected function processTenant(string $tenantId): void
    {
        try {
            // Get tenant model
            $tenant = Tenant::find($tenantId);
            if (!$tenant) {
                $this->error("Tenant {$tenantId} not found.");
                return;
            }

            // Run within tenant context
            $tenant->run(function () use ($tenantId) {
                // Get active loyalty program
                $program = LoyaltyProgram::where('is_active', true)->first();

                if (!$program) {
                    $this->warn("No active loyalty program found for tenant {$tenantId}. Skipping.");
                    return;
                }

                $this->line("Using loyalty program: {$program->name} (ID: {$program->id})");

                // Get starting tier (lowest sort_order)
                $startingTier = $program->tiers()
                    ->orderBy('sort_order', 'asc')
                    ->first();

                if ($startingTier) {
                    $this->line("Starting tier: {$startingTier->name}");
                }

                // Get all customers not already enrolled
                $enrolledCustomerIds = CustomerLoyalty::pluck('customer_id')->toArray();

                $customersToEnroll = Customer::whereNotIn('id', $enrolledCustomerIds)
                    ->where('is_active', true)
                    ->get();

                if ($customersToEnroll->isEmpty()) {
                    $this->info('All customers are already enrolled in the loyalty program.');
                    return;
                }

                $this->info("Found {$customersToEnroll->count()} customers to enroll.");

                $enrolledCount = 0;
                $errorCount = 0;

                foreach ($customersToEnroll as $customer) {
                    try {
                        CustomerLoyalty::create([
                            'customer_id' => $customer->id,
                            'loyalty_program_id' => $program->id,
                            'loyalty_tier_id' => $startingTier?->id,
                            'current_points' => 0,
                            'lifetime_points' => 0,
                            'points_earned' => 0,
                            'points_redeemed' => 0,
                            'points_expired' => 0,
                            'joined_at' => now(),
                        ]);

                        $enrolledCount++;
                        $this->line("  Enrolled: {$customer->name} ({$customer->email})");
                    } catch (\Exception $e) {
                        $errorCount++;
                        $this->error("  Failed to enroll {$customer->email}: {$e->getMessage()}");
                    }
                }

                $this->info("Tenant {$tenantId}: Enrolled {$enrolledCount} customers");
                
                if ($errorCount > 0) {
                    $this->warn("Tenant {$tenantId}: Failed to enroll {$errorCount} customers");
                }
            });

        } catch (\Exception $e) {
            $this->error("Failed to process tenant {$tenantId}: {$e->getMessage()}");
        }
    }
}
