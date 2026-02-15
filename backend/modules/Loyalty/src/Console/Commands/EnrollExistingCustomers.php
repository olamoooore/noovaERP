<?php

namespace Modules\Loyalty\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use Modules\Loyalty\Models\CustomerLoyalty;
use Modules\Loyalty\Models\LoyaltyProgram;

class EnrollExistingCustomers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loyalty:enroll-existing {--tenant= : Tenant ID to enroll customers for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enroll all existing customers in the loyalty program';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting loyalty enrollment for existing customers...');

        // Get active loyalty program
        $program = LoyaltyProgram::where('is_active', true)->first();

        if (!$program) {
            $this->error('No active loyalty program found. Please create one first.');
            return self::FAILURE;
        }

        $this->info("Using loyalty program: {$program->name} (ID: {$program->id})");

        // Get starting tier (lowest sort_order)
        $startingTier = $program->tiers()
            ->orderBy('sort_order', 'asc')
            ->first();

        if ($startingTier) {
            $this->info("Starting tier: {$startingTier->name}");
        } else {
            $this->warn('No tiers found in the loyalty program. Customers will be enrolled without a tier.');
        }

        // Get all customers not already enrolled
        $enrolledCustomerIds = CustomerLoyalty::pluck('customer_id')->toArray();

        $customersToEnroll = Customer::whereNotIn('id', $enrolledCustomerIds)
            ->where('is_active', true)
            ->get();

        if ($customersToEnroll->isEmpty()) {
            $this->info('All customers are already enrolled in the loyalty program.');
            return self::SUCCESS;
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
                $this->line("Enrolled: {$customer->name} ({$customer->email})");
            } catch (\Exception $e) {
                $errorCount++;
                $this->error("Failed to enroll {$customer->email}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Enrollment complete!");
        $this->info("Successfully enrolled: {$enrolledCount}");
        
        if ($errorCount > 0) {
            $this->warn("Failed: {$errorCount}");
        }

        return self::SUCCESS;
    }
}
