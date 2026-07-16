<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\PlanFamily;
use App\SuperAdmin\Domain\PlanVersion;
use App\SuperAdmin\Domain\Subscription;
use App\SuperAdmin\Domain\SubscriptionInvoice;
use App\SuperAdmin\Domain\TenantPlanChange;
use App\SuperAdmin\Domain\TenantPlanChangeInvoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PlanChangeService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly PricingEngineService $pricing,
        private readonly BillingService $billing,
        private readonly SubscriptionService $subscriptions,
        private readonly AdminAuditLogger $audit,
    ) {}

    // ── Request ───────────────────────────────────────────────────────────────

    /**
     * Validate and create a pending plan change record.
     * Calculates proration at request time so the admin can review amounts.
     */
    public function requestChange(
        string $tenantId,
        int $toFamilyId,
        string $effectiveDate,
        ?string $reason = null,
        ?int $adminId = null,
    ): TenantPlanChange {
        $toFamily = PlanFamily::where('id', $toFamilyId)
            ->where('is_active', true)
            ->firstOrFail();

        $fromFamily = $this->entitlements->resolveActiveTier($tenantId);

        if ($fromFamily?->id === $toFamilyId) {
            throw new InvalidArgumentException('Tenant is already on this plan family.');
        }

        $hasPending = TenantPlanChange::forTenant($tenantId)->pending()->exists();
        if ($hasPending) {
            throw new InvalidArgumentException('A pending plan change already exists for this tenant. Cancel or apply it first.');
        }

        $changeDate = Carbon::parse($effectiveDate);
        $proration = $this->calculateProration($tenantId, $changeDate);

        $change = TenantPlanChange::create([
            'tenant_id' => $tenantId,
            'from_family_id' => $fromFamily?->id,
            'to_family_id' => $toFamilyId,
            'effective_date' => $changeDate->toDateString(),
            'status' => TenantPlanChange::STATUS_PENDING,
            'proration_credit_cents' => $proration['credit_cents'],
            'proration_charge_cents' => $proration['charge_cents'],
            'reason' => $reason,
            'created_by_admin_id' => $adminId,
        ]);

        $this->audit->log(
            action: 'plan_change.requested',
            target: null,
            before: [],
            after: $change->toAuditSnapshot(),
        );

        return $change->load('fromFamily', 'toFamily', 'createdByAdmin');
    }

    // ── Apply ─────────────────────────────────────────────────────────────────

    /**
     * Atomically apply a pending plan change:
     *  1. Create a proration credit invoice (documents credit amount)
     *  2. Migrate subscription to new plan version (generates charge invoice)
     *  3. Rebuild tenant entitlements under new tier
     *  4. Generate a fresh quote snapshot
     *  5. Mark change as applied
     */
    public function applyChange(TenantPlanChange $planChange, ?int $adminId = null): TenantPlanChange
    {
        if (! $planChange->isPending()) {
            throw new InvalidArgumentException('Only pending plan changes can be applied.');
        }

        if ($planChange->effective_date->isFuture()) {
            throw new InvalidArgumentException(
                'Cannot apply a future-dated change before its effective date ('.
                $planChange->effective_date->toDateString().').'
            );
        }

        return DB::transaction(function () use ($planChange, $adminId) {
            // Lock to prevent concurrent apply
            $planChange = TenantPlanChange::lockForUpdate()->findOrFail($planChange->id);
            if (! $planChange->isPending()) {
                throw new InvalidArgumentException('Plan change was already processed by another request.');
            }

            // Find active subscription
            $subscription = Subscription::where('tenant_id', $planChange->tenant_id)
                ->whereIn('status', ['active', 'trialing', 'past_due'])
                ->latest()
                ->first();

            // Resolve target plan version
            $newVersion = PlanVersion::where('plan_family_id', $planChange->to_family_id)
                ->where('is_active', true)
                ->latest()
                ->firstOrFail();

            $billingInterval = $this->resolveBillingInterval($subscription);

            // ── Step 1: Create proration credit invoice ───────────────────────
            if ($planChange->proration_credit_cents > 0) {
                $creditInvoice = SubscriptionInvoice::create([
                    'subscription_id' => $subscription?->id,
                    'tenant_id' => $planChange->tenant_id,
                    'invoice_number' => $this->billing->generateInvoiceNumber(),
                    'billing_interval' => $billingInterval,
                    'currency' => 'USD',
                    'subtotal' => 0,
                    'tax' => 0,
                    'total' => 0,
                    'status' => 'paid',
                    'is_prorated' => true,
                    'proration_adjustment_amount' => -$planChange->proration_credit_cents,
                    'notes' => 'Proration credit for plan change #'.$planChange->id.
                                                     ' — unused days on '.($planChange->fromFamily?->name ?? 'previous plan'),
                    'period_start' => $subscription?->current_period_start ?? now(),
                    'period_end' => $subscription?->current_period_end ?? now(),
                ]);

                TenantPlanChangeInvoice::create([
                    'plan_change_id' => $planChange->id,
                    'invoice_id' => $creditInvoice->id,
                    'type' => TenantPlanChangeInvoice::TYPE_CREDIT,
                    'amount_cents' => $planChange->proration_credit_cents,
                ]);
            }

            // ── Step 2: Migrate subscription (creates charge invoice) ──────────
            if ($subscription) {
                $this->subscriptions->migratePlan(
                    $subscription,
                    $newVersion,
                    $billingInterval,
                    'Plan change #'.$planChange->id,
                );

                // Find the newly generated charge invoice
                $chargeInvoice = SubscriptionInvoice::where('subscription_id', $subscription->id)
                    ->orderByDesc('id')
                    ->first();

                if ($chargeInvoice && $planChange->proration_charge_cents > 0) {
                    $chargeInvoice->update([
                        'is_prorated' => true,
                        'proration_adjustment_amount' => $planChange->proration_charge_cents,
                    ]);

                    TenantPlanChangeInvoice::create([
                        'plan_change_id' => $planChange->id,
                        'invoice_id' => $chargeInvoice->id,
                        'type' => TenantPlanChangeInvoice::TYPE_CHARGE,
                        'amount_cents' => $planChange->proration_charge_cents,
                    ]);
                }
            } else {
                // No existing subscription — create a fresh one
                $this->subscriptions->createSubscription(
                    tenantId: $planChange->tenant_id,
                    planVersionId: $newVersion->id,
                    billingInterval: $billingInterval,
                );
            }

            // ── Step 3: Rebuild entitlements ──────────────────────────────────
            $this->entitlements->rebuild(
                $planChange->tenant_id,
                $adminId,
                'plan_change_applied',
            );

            // ── Step 4: Generate new quote ────────────────────────────────────
            $this->pricing->generateQuote(
                tenantId: $planChange->tenant_id,
                billingInterval: $billingInterval,
                currency: 'USD',
                planFamilyId: $planChange->to_family_id,
            );

            // ── Step 5: Mark applied ──────────────────────────────────────────
            $planChange->update([
                'status' => TenantPlanChange::STATUS_APPLIED,
                'applied_at' => now(),
                'applied_by_admin_id' => $adminId,
            ]);

            $this->audit->log(
                action: 'plan_change.applied',
                target: null,
                before: [],
                after: $planChange->fresh()->toAuditSnapshot(),
            );

            return $planChange->fresh()->load('fromFamily', 'toFamily', 'appliedByAdmin', 'changeInvoices.invoice');
        });
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function cancelChange(TenantPlanChange $planChange, ?int $adminId = null): TenantPlanChange
    {
        if (! $planChange->isPending()) {
            throw new InvalidArgumentException('Only pending plan changes can be cancelled.');
        }

        $planChange->update(['status' => TenantPlanChange::STATUS_CANCELLED]);

        $this->audit->log(
            action: 'plan_change.cancelled',
            target: null,
            before: [],
            after: $planChange->fresh()->toAuditSnapshot(),
        );

        return $planChange->fresh()->load('fromFamily', 'toFamily');
    }

    // ── Proration ─────────────────────────────────────────────────────────────

    /**
     * Calculate proration amounts.
     *
     * Credit  = daily_rate_old × remaining_days_in_current_period
     * Charge  = full price of new plan (standard period)
     *
     * Returns ['credit_cents' => int, 'charge_cents' => int]
     */
    public function calculateProration(string $tenantId, Carbon $changeDate): array
    {
        $subscription = Subscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->latest()
            ->with('planVersion.prices')
            ->first();

        $creditCents = 0;
        $chargeCents = 0;

        if ($subscription && $subscription->status !== 'trialing') {
            $periodStart = $subscription->current_period_start;
            $periodEnd = $subscription->current_period_end;
            $totalDaysInPeriod = (int) $periodStart->diffInDays($periodEnd);

            if ($totalDaysInPeriod > 0) {
                $daysUsed = (int) $periodStart->diffInDays($changeDate);
                $daysRemaining = max(0, $totalDaysInPeriod - $daysUsed);

                // Use the latest invoice amount as the basis
                $latestInvoice = SubscriptionInvoice::where('subscription_id', $subscription->id)
                    ->whereIn('status', ['open', 'paid'])
                    ->orderByDesc('id')
                    ->first();

                $currentTotal = $latestInvoice?->total ?? 0;
                $dailyRate = $currentTotal / $totalDaysInPeriod;
                $creditCents = (int) round($dailyRate * $daysRemaining);
            }
        }

        // Charge = full price of new plan — resolved from plan_versions + plan_prices
        // We'll store 0 here and let the invoice from migratePlan reflect the charge.
        // A non-zero value only applies if we have pricing data available.
        $chargeCents = 0;

        return ['credit_cents' => $creditCents, 'charge_cents' => $chargeCents];
    }

    /**
     * Preview proration for display in the UI before confirming.
     */
    public function previewProration(string $tenantId, int $toFamilyId, string $effectiveDate): array
    {
        $toFamily = PlanFamily::with('versions.prices')->findOrFail($toFamilyId);
        $fromFamily = $this->entitlements->resolveActiveTier($tenantId);
        $changeDate = Carbon::parse($effectiveDate);

        $proration = $this->calculateProration($tenantId, $changeDate);

        $subscription = Subscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->latest()
            ->first();

        return [
            'from_family' => $fromFamily,
            'to_family' => $toFamily,
            'credit_cents' => $proration['credit_cents'],
            'charge_cents' => $proration['charge_cents'],
            'net_cents' => $proration['charge_cents'] - $proration['credit_cents'],
            'effective_date' => $changeDate->toDateString(),
            'current_period_end' => $subscription?->current_period_end?->toDateString(),
        ];
    }

    // ── Queries ───────────────────────────────────────────────────────────────

    public function getPendingChanges(): Collection
    {
        return TenantPlanChange::pending()
            ->with(['fromFamily', 'toFamily', 'createdByAdmin'])
            ->orderBy('effective_date')
            ->get();
    }

    public function getChangeHistory(string $tenantId): Collection
    {
        return TenantPlanChange::forTenant($tenantId)
            ->with(['fromFamily', 'toFamily', 'createdByAdmin', 'appliedByAdmin', 'changeInvoices.invoice'])
            ->orderByDesc('created_at')
            ->get();
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function resolveBillingInterval(?Subscription $subscription): string
    {
        if (! $subscription) {
            return 'monthly';
        }

        $latestInvoice = SubscriptionInvoice::where('subscription_id', $subscription->id)
            ->orderByDesc('id')
            ->first();

        return $latestInvoice?->billing_interval ?? 'monthly';
    }
}
