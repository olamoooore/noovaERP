<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\CatalogPrice;
use App\SuperAdmin\Domain\CatalogPriceRule;
use App\SuperAdmin\Domain\TenantEntitlement;
use App\SuperAdmin\Domain\TenantQuoteSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class PricingEngineService
{
    // ── Price CRUD ────────────────────────────────────────────────────────────

    public function listPrices(?int $catalogItemId = null): Collection
    {
        $query = CatalogPrice::with('catalogItem')
            ->orderBy('catalog_item_id')
            ->orderBy('billing_interval');

        if ($catalogItemId !== null) {
            $query->where('catalog_item_id', $catalogItemId);
        }

        return $query->get();
    }

    public function upsertPrice(int $catalogItemId, string $billingInterval, string $currency, int $unitAmount): CatalogPrice
    {
        return CatalogPrice::updateOrCreate(
            [
                'catalog_item_id' => $catalogItemId,
                'billing_interval' => $billingInterval,
                'currency' => strtoupper($currency),
            ],
            ['unit_amount' => $unitAmount]
        );
    }

    public function deletePrice(int $priceId): void
    {
        CatalogPrice::findOrFail($priceId)->delete();
    }

    // ── Rule CRUD ─────────────────────────────────────────────────────────────

    public function listRules(?int $catalogItemId = null): Collection
    {
        $query = CatalogPriceRule::with('catalogItem')
            ->orderByDesc('priority')
            ->orderBy('name');

        if ($catalogItemId !== null) {
            $query->where('catalog_item_id', $catalogItemId);
        }

        return $query->get();
    }

    public function createRule(array $data): CatalogPriceRule
    {
        return CatalogPriceRule::create([
            'catalog_item_id' => $data['catalog_item_id'] ?? null,
            'name' => $data['name'],
            'rule_type' => $data['rule_type'],
            'condition' => $data['condition'] ?? [],
            'modifier_value' => $data['modifier_value'],
            'priority' => $data['priority'] ?? 0,
            'is_exclusive' => $data['is_exclusive'] ?? false,
            'is_active' => $data['is_active'] ?? true,
            'effective_from' => $data['effective_from'] ?? now()->toDateString(),
            'effective_to' => $data['effective_to'] ?? null,
        ]);
    }

    public function updateRule(CatalogPriceRule $rule, array $data): CatalogPriceRule
    {
        $rule->update(array_filter([
            'name' => $data['name'] ?? $rule->name,
            'rule_type' => $data['rule_type'] ?? $rule->rule_type,
            'condition' => $data['condition'] ?? $rule->condition,
            'modifier_value' => $data['modifier_value'] ?? $rule->modifier_value,
            'priority' => $data['priority'] ?? $rule->priority,
            'is_exclusive' => $data['is_exclusive'] ?? $rule->is_exclusive,
            'is_active' => $data['is_active'] ?? $rule->is_active,
            'effective_from' => $data['effective_from'] ?? $rule->effective_from,
            'effective_to' => array_key_exists('effective_to', $data) ? $data['effective_to'] : $rule->effective_to,
        ], fn ($v) => $v !== null));

        return $rule->fresh();
    }

    public function deleteRule(int $ruleId): void
    {
        CatalogPriceRule::findOrFail($ruleId)->delete();
    }

    // ── Quote generation ──────────────────────────────────────────────────────

    /**
     * Generate a full price quote for a tenant given their current entitlements.
     *
     * Resolution order:
     *  1. Collect all entitled items (tier_included + add_on_purchased + override_include)
     *  2. Look up base prices for the requested billing_interval + currency
     *  3. Apply price rules in priority order (highest first)
     *  4. Build line items
     *  5. Persist a TenantQuoteSnapshot and return it
     */
    public function generateQuote(
        string $tenantId,
        string $billingInterval = 'monthly',
        string $currency = 'USD',
        ?int $planFamilyId = null,
    ): TenantQuoteSnapshot {
        $currency = strtoupper($currency);

        // ── Step 1: Entitled items ────────────────────────────────────────────
        $entitlements = TenantEntitlement::forTenant($tenantId)
            ->entitled()
            ->with('catalogItem')
            ->get();

        // ── Step 2: Fetch all base prices for this interval + currency ────────
        $itemIds = $entitlements->pluck('catalog_item_id')->unique()->values()->all();

        $prices = CatalogPrice::whereIn('catalog_item_id', $itemIds)
            ->where('billing_interval', $billingInterval)
            ->where('currency', $currency)
            ->get()
            ->keyBy('catalog_item_id');

        // ── Step 3: Fetch effective rules (global + item-specific) ────────────
        $rules = CatalogPriceRule::effective()
            ->byPriority()
            ->where(function ($q) use ($itemIds) {
                $q->whereNull('catalog_item_id')
                    ->orWhereIn('catalog_item_id', $itemIds);
            })
            ->get();

        // ── Step 4: Build line items ──────────────────────────────────────────
        $lineItems = [];
        $subtotalCents = 0;
        $totalDiscount = 0;

        // Count purchased add-ons for volume tier rules
        $addOnCount = $entitlements->where('source', 'add_on_purchased')->count();

        foreach ($entitlements as $entitlement) {
            $item = $entitlement->catalogItem;
            $price = $prices->get($entitlement->catalog_item_id);

            // Items with no price row are included at $0
            $unitAmount = $price ? $price->unit_amount : 0;
            $quantity = 1;

            // Tier-included items are always shown at $0 in quotes
            if ($entitlement->source === 'tier_included') {
                $unitAmount = 0;
            }

            $lineSubtotal = $unitAmount * $quantity;
            $subtotalCents += $lineSubtotal;

            // Apply rules
            [$discountCents, $rulesApplied] = $this->applyRules(
                rules: $rules,
                itemId: $entitlement->catalog_item_id,
                billingInterval: $billingInterval,
                baseAmount: $lineSubtotal,
                entitlementSource: $entitlement->source,
                addOnCount: $addOnCount,
            );

            $totalDiscount += $discountCents;
            $lineTotalCents = max(0, $lineSubtotal - $discountCents);

            $lineItems[] = [
                'catalog_item_id' => $entitlement->catalog_item_id,
                'item_key' => $item?->key ?? '',
                'item_name' => $item?->name ?? 'Unknown',
                'source' => $entitlement->source,
                'unit_amount' => $unitAmount,
                'quantity' => $quantity,
                'discount_cents' => $discountCents,
                'line_total_cents' => $lineTotalCents,
                'rules_applied' => $rulesApplied,
            ];
        }

        $totalCents = max(0, $subtotalCents - $totalDiscount);

        // ── Step 5: Persist snapshot ──────────────────────────────────────────
        $snapshot = TenantQuoteSnapshot::create([
            'tenant_id' => $tenantId,
            'plan_family_id' => $planFamilyId,
            'billing_interval' => $billingInterval,
            'currency' => $currency,
            'subtotal_cents' => $subtotalCents,
            'discount_cents' => $totalDiscount,
            'total_cents' => $totalCents,
            'line_items' => $lineItems,
            'generated_by_admin_id' => Auth::id(),
            'generated_at' => now(),
        ]);

        return $snapshot;
    }

    /**
     * Return the most recent quote snapshot for a tenant.
     */
    public function latestQuote(string $tenantId): ?TenantQuoteSnapshot
    {
        return TenantQuoteSnapshot::forTenant($tenantId)
            ->latest()
            ->with('planFamily', 'generatedByAdmin')
            ->first();
    }

    /**
     * Return all quote snapshots for a tenant (paginated).
     */
    public function quoteHistory(string $tenantId, int $perPage = 10)
    {
        return TenantQuoteSnapshot::forTenant($tenantId)
            ->latest()
            ->with('planFamily', 'generatedByAdmin')
            ->paginate($perPage);
    }

    // ── Rule application ──────────────────────────────────────────────────────

    /**
     * Apply price rules to a line item and return [discountCents, rulesApplied[]].
     */
    private function applyRules(
        Collection $rules,
        int $itemId,
        string $billingInterval,
        int $baseAmount,
        string $entitlementSource,
        int $addOnCount,
    ): array {
        $totalDiscount = 0;
        $rulesApplied = [];
        $exclusiveHit = false;

        foreach ($rules as $rule) {
            if ($exclusiveHit) {
                break;
            }

            // Skip item-specific rules that don't apply to this item
            if ($rule->catalog_item_id !== null && $rule->catalog_item_id !== $itemId) {
                continue;
            }

            $discount = $this->evaluateRule($rule, $billingInterval, $baseAmount, $entitlementSource, $addOnCount);

            if ($discount > 0) {
                $totalDiscount += $discount;
                $rulesApplied[] = $rule->name;

                if ($rule->is_exclusive) {
                    $exclusiveHit = true;
                }
            }
        }

        return [$totalDiscount, $rulesApplied];
    }

    /**
     * Evaluate a single rule and return the discount in cents.
     */
    private function evaluateRule(
        CatalogPriceRule $rule,
        string $billingInterval,
        int $baseAmount,
        string $entitlementSource,
        int $addOnCount,
    ): int {
        $condition = $rule->condition ?? [];

        switch ($rule->rule_type) {

            case CatalogPriceRule::TYPE_PERCENTAGE_DISCOUNT:
                // Optionally scoped to a tier key via condition
                if (isset($condition['tier_key'])) {
                    // Would check tenant's tier — skipped for now, match all
                }

                return (int) round($baseAmount * ($rule->modifier_value / 100));

            case CatalogPriceRule::TYPE_FIXED_DISCOUNT:
                return min($baseAmount, (int) $rule->modifier_value);

            case CatalogPriceRule::TYPE_YEARLY_DISCOUNT:
                if ($billingInterval !== 'yearly') {
                    return 0;
                }

                return (int) round($baseAmount * ($rule->modifier_value / 100));

            case CatalogPriceRule::TYPE_VOLUME_TIER:
                $minItems = (int) ($condition['min_items'] ?? 0);
                if ($addOnCount < $minItems) {
                    return 0;
                }
                // Only applies to add-on items
                if ($entitlementSource !== 'add_on_purchased') {
                    return 0;
                }

                return (int) round($baseAmount * ($rule->modifier_value / 100));

            default:
                return 0;
        }
    }
}
