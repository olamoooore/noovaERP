<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\PlanVersion;
use App\SuperAdmin\Domain\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Models\Tenant;

class SubscriptionService
{
    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
        private readonly BillingService $billingService,
        private readonly EntitlementService $entitlements,
    ) {}

    public function createSubscription(
        string $tenantId,
        int $planVersionId,
        string $billingInterval,
        ?Carbon $trialEndsAt = null,
    ): Subscription {
        Tenant::findOrFail($tenantId);
        $planVersion = PlanVersion::findOrFail($planVersionId);

        $now = now();
        $status = $trialEndsAt && $trialEndsAt->isFuture() ? 'trialing' : 'active';

        $periodEnd = match ($billingInterval) {
            'yearly' => $now->copy()->addYear(),
            default => $now->copy()->addMonth(),
        };

        return DB::transaction(function () use (
            $tenantId, $planVersionId, $billingInterval,
            $trialEndsAt, $now, $status, $periodEnd,
        ) {
            $subscription = Subscription::create([
                'tenant_id' => $tenantId,
                'plan_version_id' => $planVersionId,
                'status' => $status,
                'trial_starts_at' => $trialEndsAt ? $now : null,
                'trial_ends_at' => $trialEndsAt,
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
            ]);

            // Generate opening invoice for active (non-trial) subscriptions
            if ($status === 'active') {
                $this->billingService->generateInvoice($subscription, $billingInterval);
            }

            // Rebuild entitlements to reflect new subscription tier
            $this->entitlements->rebuild($tenantId, null, 'subscription_created');

            $this->auditLogger->log(
                action: 'subscription.created',
                target: $subscription,
                before: [],
                after: $subscription->toAuditSnapshot(),
            );

            return $subscription;
        });
    }

    public function cancelSubscription(
        Subscription $subscription,
        ?string $reason = null,
    ): Subscription {
        $before = $subscription->toAuditSnapshot();

        $subscription->update([
            'canceled_at' => now(),
            'cancellation_reason' => $reason,
            // Status stays 'active' — access continues until current_period_end
        ]);

        $this->auditLogger->log(
            action: 'subscription.canceled',
            target: $subscription,
            before: $before,
            after: $subscription->fresh()->toAuditSnapshot(),
            reason: $reason,
        );

        return $subscription->fresh();
    }

    public function reactivateSubscription(Subscription $subscription): Subscription
    {
        $before = $subscription->toAuditSnapshot();

        $subscription->update([
            'canceled_at' => null,
            'cancellation_reason' => null,
            'status' => 'active',
        ]);

        $this->auditLogger->log(
            action: 'subscription.reactivated',
            target: $subscription,
            before: $before,
            after: $subscription->fresh()->toAuditSnapshot(),
        );

        return $subscription->fresh();
    }

    public function migratePlan(
        Subscription $subscription,
        PlanVersion $newVersion,
        string $billingInterval,
        ?string $reason = null,
    ): Subscription {
        $before = $subscription->toAuditSnapshot();

        return DB::transaction(function () use (
            $subscription, $newVersion, $billingInterval, $before, $reason,
        ) {
            $now = now();

            $periodEnd = match ($billingInterval) {
                'yearly' => $now->copy()->addYear(),
                default => $now->copy()->addMonth(),
            };

            $subscription->update([
                'plan_version_id' => $newVersion->id,
                'status' => 'active',
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
                'canceled_at' => null,
                'cancellation_reason' => null,
            ]);

            // Generate invoice for the new plan
            $this->billingService->generateInvoice($subscription->fresh(), $billingInterval);

            $this->auditLogger->log(
                action: 'subscription.plan_migrated',
                target: $subscription->fresh(),
                before: $before,
                after: $subscription->fresh()->toAuditSnapshot(),
                reason: $reason,
            );

            return $subscription->fresh();
        });
    }
}
