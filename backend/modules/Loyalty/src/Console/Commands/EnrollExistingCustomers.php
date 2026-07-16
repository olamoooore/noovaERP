<?php

namespace Modules\Loyalty\Console\Commands;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\Loyalty\Models\CustomerLoyalty;
use Modules\Loyalty\Models\LoyaltyProgram;

class EnrollExistingCustomers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loyalty:enroll-existing {--tenant= : Specific tenant ID (required)}';

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
        $tenantId = $this->option('tenant');

        if (! $tenantId) {
            $this->error('The --tenant flag is required for this command.');
            $this->info('Usage: php artisan loyalty:enroll-existing --tenant=<tenant-id>');

            return self::FAILURE;
        }

        $this->processTenant($tenantId);

        return self::SUCCESS;
    }

    /**
     * Process a specific tenant
     */
    protected function processTenant(string $tenantId): void
    {
        try {
            // Get tenant model
            $tenant = Tenant::find($tenantId);
            if (! $tenant) {
                $this->error("Tenant {$tenantId} not found.");

                return;
            }

            // Run within tenant context
            $tenant->run(function () use ($tenantId) {
                // Get active loyalty program
                $program = LoyaltyProgram::where('is_active', true)->first();

                if (! $program) {
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
