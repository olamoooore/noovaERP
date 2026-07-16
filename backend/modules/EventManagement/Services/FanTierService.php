<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Models\Customer;
use Modules\EventManagement\Models\CustomerTier;
use Modules\EventManagement\Models\EarlyAccessWindow;
use Modules\EventManagement\Models\FanTier;
use Modules\EventManagement\Models\TierBenefit;

class FanTierService
{
    /**
     * Create a new fan tier.
     */
    public function createFanTier(string $tenantId, array $data): FanTier
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $fanTier = FanTier::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'minimum_points' => $data['minimum_points'] ?? 0,
                'minimum_events_attended' => $data['minimum_events_attended'] ?? 0,
                'minimum_total_spent' => $data['minimum_total_spent'] ?? 0,
                'priority_level' => $data['priority_level'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
                'metadata' => $data['metadata'] ?? [],
            ]);

            // Create default benefits if provided
            if (isset($data['benefits'])) {
                foreach ($data['benefits'] as $benefitData) {
                    $this->createTierBenefit($tenantId, $fanTier->id, $benefitData);
                }
            }

            return $fanTier;
        });
    }

    /**
     * Update an existing fan tier.
     */
    public function updateFanTier(string $tenantId, int $fanTierId, array $data): FanTier
    {
        $fanTier = FanTier::forTenant($tenantId)->findOrFail($fanTierId);

        $fanTier->update($data);

        return $fanTier->fresh();
    }

    /**
     * Delete a fan tier (soft delete).
     */
    public function deleteFanTier(string $tenantId, int $fanTierId): bool
    {
        $fanTier = FanTier::forTenant($tenantId)->findOrFail($fanTierId);

        return $fanTier->delete();
    }

    /**
     * Get all fan tiers for a tenant.
     */
    public function getFanTiers(string $tenantId, bool $activeOnly = false): Collection
    {
        $query = FanTier::forTenant($tenantId)->byPriority();

        if ($activeOnly) {
            $query->active();
        }

        return $query->get();
    }

    /**
     * Get a specific fan tier.
     */
    public function getFanTier(string $tenantId, int $fanTierId): FanTier
    {
        return FanTier::forTenant($tenantId)->findOrFail($fanTierId);
    }

    /**
     * Assign a customer to a fan tier.
     */
    public function assignCustomerToTier(string $tenantId, int $customerId, int $fanTierId, array $qualificationData = []): CustomerTier
    {
        return DB::transaction(function () use ($tenantId, $customerId, $fanTierId, $qualificationData) {
            // Remove existing tier assignment
            CustomerTier::forTenant($tenantId)
                ->where('customer_id', $customerId)
                ->delete();

            // Get customer stats
            $customer = Customer::findOrFail($customerId);
            $stats = $this->calculateCustomerStats($tenantId, $customerId);

            // Create new tier assignment
            return CustomerTier::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'fan_tier_id' => $fanTierId,
                'current_points' => $stats['current_points'],
                'lifetime_points' => $stats['lifetime_points'],
                'events_attended' => $stats['events_attended'],
                'total_spent' => $stats['total_spent'],
                'tier_assigned_at' => now(),
                'tier_expires_at' => null, // No expiration by default
                'qualification_data' => $qualificationData,
            ]);
        });
    }

    /**
     * Calculate tier progression for a customer.
     */
    public function calculateTierProgression(string $tenantId, int $customerId): array
    {
        $stats = $this->calculateCustomerStats($tenantId, $customerId);
        $tiers = $this->getFanTiers($tenantId, true);

        $currentTier = $this->getCustomerTier($tenantId, $customerId);
        $eligibleTiers = $tiers->filter(function ($tier) use ($stats) {
            return $tier->customerQualifies(
                $stats['current_points'],
                $stats['events_attended'],
                $stats['total_spent']
            );
        });

        $highestEligibleTier = $eligibleTiers->sortByDesc('priority_level')->first();
        $nextTier = $currentTier ? $currentTier->fanTier->getNextTier() : $tiers->first();

        return [
            'current_tier' => $currentTier,
            'highest_eligible_tier' => $highestEligibleTier,
            'next_tier' => $nextTier,
            'customer_stats' => $stats,
            'progression_requirements' => $nextTier ? [
                'points_needed' => max(0, $nextTier->minimum_points - $stats['current_points']),
                'events_needed' => max(0, $nextTier->minimum_events_attended - $stats['events_attended']),
                'spending_needed' => max(0, $nextTier->minimum_total_spent - $stats['total_spent']),
            ] : null,
        ];
    }

    /**
     * Process automatic tier progression for a customer.
     */
    public function processAutomaticTierProgression(string $tenantId, int $customerId): ?CustomerTier
    {
        $progression = $this->calculateTierProgression($tenantId, $customerId);

        if (! $progression['highest_eligible_tier']) {
            return null;
        }

        $currentTier = $progression['current_tier'];
        $eligibleTier = $progression['highest_eligible_tier'];

        // Check if customer should be upgraded
        if (! $currentTier || $eligibleTier->priority_level > $currentTier->fanTier->priority_level) {
            return $this->assignCustomerToTier(
                $tenantId,
                $customerId,
                $eligibleTier->id,
                [
                    'auto_upgraded' => true,
                    'previous_tier_id' => $currentTier?->fan_tier_id,
                    'qualification_stats' => $progression['customer_stats'],
                    'upgraded_at' => now()->toISOString(),
                ]
            );
        }

        return $currentTier;
    }

    /**
     * Get customer's current tier assignment.
     */
    public function getCustomerTier(string $tenantId, int $customerId): ?CustomerTier
    {
        return CustomerTier::forTenant($tenantId)
            ->where('customer_id', $customerId)
            ->active()
            ->with('fanTier')
            ->first();
    }

    /**
     * Calculate customer statistics for tier qualification.
     */
    public function calculateCustomerStats(string $tenantId, int $customerId): array
    {
        // Get existing customer tier if any
        $customerTier = $this->getCustomerTier($tenantId, $customerId);

        if ($customerTier) {
            return [
                'current_points' => $customerTier->current_points,
                'lifetime_points' => $customerTier->lifetime_points,
                'events_attended' => $customerTier->events_attended,
                'total_spent' => (float) $customerTier->total_spent,
            ];
        }

        // Calculate from scratch if no tier exists
        // This would typically query bookings, payments, etc.
        // For now, return defaults
        return [
            'current_points' => 0,
            'lifetime_points' => 0,
            'events_attended' => 0,
            'total_spent' => 0.0,
        ];
    }

    /**
     * Create early access window for an event and tier.
     */
    public function createEarlyAccessWindow(string $tenantId, array $data): EarlyAccessWindow
    {
        return EarlyAccessWindow::create([
            'tenant_id' => $tenantId,
            'event_id' => $data['event_id'],
            'fan_tier_id' => $data['fan_tier_id'],
            'access_starts_at' => $data['access_starts_at'],
            'access_ends_at' => $data['access_ends_at'],
            'max_tickets_per_customer' => $data['max_tickets_per_customer'] ?? null,
            'eligible_ticket_types' => $data['eligible_ticket_types'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Get early access windows for an event.
     */
    public function getEarlyAccessWindows(string $tenantId, int $eventId): Collection
    {
        return EarlyAccessWindow::forTenant($tenantId)
            ->forEvent($eventId)
            ->active()
            ->with('fanTier')
            ->orderBy('access_starts_at')
            ->get();
    }

    /**
     * Check if a customer has early access to an event.
     */
    public function hasEarlyAccess(string $tenantId, int $customerId, int $eventId): array
    {
        $customerTier = $this->getCustomerTier($tenantId, $customerId);

        if (! $customerTier) {
            return [
                'has_access' => false,
                'reason' => 'No tier assigned',
            ];
        }

        $earlyAccessWindows = EarlyAccessWindow::forTenant($tenantId)
            ->forEvent($eventId)
            ->forFanTier($customerTier->fan_tier_id)
            ->active()
            ->get();

        $currentWindow = $earlyAccessWindows->first(fn ($window) => $window->isCurrentlyOpen());
        $upcomingWindow = $earlyAccessWindows->first(fn ($window) => $window->isUpcoming());

        if ($currentWindow) {
            return [
                'has_access' => true,
                'window' => $currentWindow,
                'status' => 'open',
            ];
        }

        if ($upcomingWindow) {
            return [
                'has_access' => true,
                'window' => $upcomingWindow,
                'status' => 'upcoming',
                'opens_in_seconds' => $upcomingWindow->getTimeUntilOpen(),
            ];
        }

        return [
            'has_access' => false,
            'reason' => 'No early access window available for tier',
        ];
    }

    /**
     * Create a tier benefit.
     */
    public function createTierBenefit(string $tenantId, int $fanTierId, array $data): TierBenefit
    {
        return TierBenefit::create([
            'tenant_id' => $tenantId,
            'fan_tier_id' => $fanTierId,
            'benefit_type' => $data['benefit_type'],
            'benefit_name' => $data['benefit_name'],
            'description' => $data['description'] ?? null,
            'configuration' => $data['configuration'] ?? [],
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Get benefits for a fan tier.
     */
    public function getTierBenefits(string $tenantId, int $fanTierId): Collection
    {
        return TierBenefit::forTenant($tenantId)
            ->where('fan_tier_id', $fanTierId)
            ->active()
            ->get();
    }

    /**
     * Get all benefits available to a customer.
     */
    public function getCustomerBenefits(string $tenantId, int $customerId): Collection
    {
        $customerTier = $this->getCustomerTier($tenantId, $customerId);

        if (! $customerTier) {
            return collect();
        }

        return $this->getTierBenefits($tenantId, $customerTier->fan_tier_id);
    }
}
