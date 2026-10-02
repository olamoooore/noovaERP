<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;

/**
 * Single runtime tax engine for sales, purchases, POS and commerce.
 *
 * Rates are configuration, not legal advice. Country defaults are only used
 * when the tenant has not configured a more specific Tax record.
 */
class TaxEngineService
{
    public function __construct(private ?TaxPackRegistry $registry = null) {}

    /**
     * #744 T11: country facts come from the pack registry, never a constant.
     */
    private function packs(): TaxPackRegistry
    {
        return $this->registry ??= app(TaxPackRegistry::class);
    }

    /**
     * Resolve the effective tax for a product/document context.
     *
     * Per-product inclusivity override: for every mode EXCEPT 'custom' (which
     * owns its own `tax_included` flag), a non-null
     * products.tax_inclusive_override forces inclusive/exclusive pricing
     * regardless of the resolved rule's flag.
     */
    public function resolve(
        ?Product $product = null,
        ?string $jurisdiction = null,
        string $direction = 'output',
        ?string $taxType = null,
        ?string $date = null,
    ): array {
        $result = $this->resolveRaw($product, $jurisdiction, $direction, $taxType, $date);

        return $this->applyProductInclusiveOverride($result, $product);
    }

    /**
     * The resolution ladder without the per-product inclusive override.
     */
    private function resolveRaw(
        ?Product $product = null,
        ?string $jurisdiction = null,
        string $direction = 'output',
        ?string $taxType = null,
        ?string $date = null,
    ): array {
        $jurisdiction = strtoupper($jurisdiction ?? $this->tenantJurisdiction());
        $direction = $direction === 'input' ? 'input' : 'output';
        $date = $date ?? now()->toDateString();

        if (! $this->isEnabled()) {
            return $this->none($jurisdiction, 'Tax is disabled in tenant settings.');
        }

        // G-22: an explicit 'none' tax mode is a full opt-out of every tier —
        // tenant defaults and localized rules must not silently tax it.
        if ($product && $product->tax_mode === 'none') {
            return $this->none($jurisdiction, 'This product is configured as tax-exempt (tax_mode=none).');
        }

        if ($product && $product->tax_mode === 'custom' && $product->tax_rate !== null) {
            $rate = (float) $product->tax_rate;

            return [
                'rate' => $rate,
                'is_inclusive' => (bool) $product->tax_included,
                'tax_code' => 'PRODUCT-CUSTOM-'.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'),
                'tax_id' => null,
                'tax_name' => 'Product custom tax',
                'jurisdiction' => $jurisdiction,
                'regime' => $this->packs()->regime($jurisdiction),
                'scope' => $direction,
                'treatment' => $rate > 0 ? 'standard_rated' : 'out_of_scope',
                'source' => 'product_custom',
            ];
        }

        $explicitTax = $product?->tax_id
            ? Tax::query()->whereKey($product->tax_id)->where('is_active', true)->where('lifecycle_state', 'ACTIVE')->first()
            : null;

        // G-23: a directly linked rule still honors its scope — an
        // output-only rule must not surface on purchase-side resolution.
        if ($explicitTax && $this->isValidOn($explicitTax, $date) && $this->scopeMatches($explicitTax, $direction)) {
            return $this->fromTax($explicitTax, $jurisdiction, $direction, 'product');
        }

        // #746 T13: an expired explicit rule follows its successor chain with
        // the same treatment — never a silent default/newest-rule fallback.
        // Without a successor the line resolves blocked (fail closed), not
        // silently charged at whatever rate the fallback happens to carry.
        if ($explicitTax && ! $this->isValidOn($explicitTax, $date)) {
            $successor = $this->resolveSuccessor($explicitTax, $date, $direction);

            if ($successor !== null) {
                return $this->fromTax($successor, $jurisdiction, $direction, 'product_successor');
            }

            return $this->blockedExpiredRule($explicitTax, $jurisdiction, $direction);
        }

        $setting = Setting::getValue('tax', []);
        $defaultTax = ! empty($setting['default_tax_id'])
            ? Tax::query()->whereKey($setting['default_tax_id'])->where('is_active', true)->where('lifecycle_state', 'ACTIVE')->first()
            : null;
        // MRD R2.2: a default is direction-aware — an output (sales) default
        // must never auto-apply to purchase-side documents, and vice versa.
        if ($defaultTax && $this->isValidOn($defaultTax, $date) && $this->scopeMatches($defaultTax, $direction)) {
            return $this->fromTax($defaultTax, $jurisdiction, $direction, 'tenant_default');
        }

        $tax = Tax::query()
            ->where('is_active', true)->where('lifecycle_state', 'ACTIVE')
            ->where(function ($query) use ($jurisdiction) {
                $query->where('jurisdiction', $jurisdiction)->orWhereNull('jurisdiction');
            })
            ->where(function ($query) use ($direction) {
                $query->where('scope', $direction)->orWhere('scope', 'both')->orWhereNull('scope');
            })
            ->when($taxType, fn ($query) => $query->where(function ($q) use ($taxType) {
                $q->whereRaw('LOWER(type) = ?', [strtolower($taxType)])
                    ->orWhereRaw('LOWER(regime) = ?', [strtolower($taxType)]);
            }))
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('is_default')
            ->orderByDesc('effective_from')
            // G-24: never resolve ambiguity by insertion order. A charging
            // (standard-rated) rule beats zero/exempt declarations, then higher
            // rate, then newest id — deterministic without guessing.
            ->orderByRaw("case when coalesce(treatment,'standard_rated') = 'standard_rated' then 0 else 1 end")
            ->orderByDesc('rate')
            ->orderByDesc('id')
            ->first();

        if ($tax) {
            return $this->fromTax($tax, $jurisdiction, $direction, 'localized_rule');
        }

        // Legacy product-level rate (products.tax_rate without tax_mode custom).
        // An explicit tax_mode of 'none' opts the product out of tax entirely.
        if ($product && $product->tax_mode !== 'none'
            && $product->tax_rate !== null && (float) $product->tax_rate > 0) {
            $rate = (float) $product->tax_rate;

            return [
                'rate' => $rate,
                'is_inclusive' => (bool) $product->tax_included,
                'tax_code' => 'PRODUCT-'.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'),
                'tax_id' => null,
                'tax_name' => 'Product tax',
                'jurisdiction' => $jurisdiction,
                'regime' => $this->packs()->regime($jurisdiction),
                'scope' => $direction,
                'treatment' => 'standard_rated',
                'source' => 'product',
            ];
        }

        // MRD TAX-REQ-02 (D5): never silently apply a hardcoded country rate to
        // an unconfigured tenant. A document charges tax only when the tenant
        // has an explicit rule (or an explicit product-level configuration);
        // otherwise it resolves to no-tax with a deterministic warning.
        return $this->none($jurisdiction, 'No applicable tax rule was found. Configure a tax rule before charging tax in this jurisdiction/direction.');
    }

    /**
     * THE single tax arithmetic for the entire platform (MRD R3.1/R3.4):
     * exclusive, inclusive and line-discount handling live ONLY here.
     * Static so model/DTO layer math delegates instead of re-implementing.
     */
    public static function calculate(float $amount, float $rate, bool $inclusive, float $discount = 0.0): array
    {
        // G-25: negative inputs (correction/credit-style bases) must keep their
        // sign — clamping to zero silently dropped real tax.
        $amount = round($amount, 2);
        $discount = round(min(max($discount, 0.0), max($amount, 0.0)), 2);
        $grossOrNet = round($amount - $discount, 2);

        if ($rate <= 0) {
            return ['net' => $grossOrNet, 'tax' => 0.0, 'gross' => $grossOrNet];
        }

        if ($inclusive) {
            $net = round($grossOrNet / (1 + ($rate / 100)), 2);

            $tax = (float) $grossOrNet >= 0
                ? round($grossOrNet - $net, 2) // G-25b
                : -round(abs((float) $grossOrNet) - abs((float) $net), 2);

            return ['net' => $net, 'tax' => $tax, 'gross' => $grossOrNet];
        }

        $tax = round($grossOrNet * ($rate / 100), 2);

        return ['net' => $grossOrNet, 'tax' => $tax, 'gross' => round($grossOrNet + $tax, 2)];
    }

    public function countryDefaults(string $jurisdiction, ?string $date = null): array
    {
        return $this->packs()->defaultsFor($jurisdiction, $date);
    }

    /**
     * Tenant home jurisdiction: explicit Setting tax.jurisdiction first, then
     * the jurisdiction of the tenant's default/active configured rule, then SG
     * as a label-only legacy default. This never charges a rate by itself.
     */
    public function tenantJurisdiction(): string
    {
        $fromSetting = strtoupper(trim((string) (Setting::getValue('tax', [])['jurisdiction'] ?? '')));

        if (preg_match('/^[A-Z]{2}$/', $fromSetting) === 1) {
            return $fromSetting;
        }

        $fromRules = Tax::query()
            ->where('is_active', true)->where('lifecycle_state', 'ACTIVE')
            ->whereNotNull('jurisdiction')
            ->orderByDesc('is_default')
            ->value('jurisdiction');

        if ($fromRules) {
            return strtoupper((string) $fromRules);
        }

        return 'SG';
    }

    /**
     * Apply the per-product inclusive/exclusive override (NULL = inherit).
     * 'custom' mode keeps its own tax_included flag; no-tax resolutions stay
     * untouched.
     */
    private function applyProductInclusiveOverride(array $result, ?Product $product): array
    {
        if (! $product || $product->tax_inclusive_override === null || $product->tax_mode === 'custom') {
            return $result;
        }

        if ((float) ($result['rate'] ?? 0.0) <= 0.0 && empty($result['tax_id'])) {
            return $result;
        }

        $result['is_inclusive'] = (bool) $product->tax_inclusive_override;

        return $result;
    }

    private function fromTax(Tax $tax, string $jurisdiction, string $direction, string $source): array
    {
        $effectiveJurisdiction = strtoupper((string) ($tax->jurisdiction ?: $jurisdiction));
        $regime = strtoupper((string) ($tax->regime ?: $this->packs()->regime($effectiveJurisdiction)));

        return [
            'rate' => (float) $tax->rate,
            'is_inclusive' => (bool) $tax->is_inclusive,
            'tax_code' => $tax->code ?: $effectiveJurisdiction.'-'.$regime.'-'.rtrim(rtrim(number_format((float) $tax->rate, 2, '.', ''), '0'), '.'),
            'tax_id' => $tax->id,
            'tax_name' => $tax->name,
            'jurisdiction' => $effectiveJurisdiction,
            'regime' => $regime,
            'scope' => $tax->scope ?: $direction,
            'tax_type' => $tax->type,
            'treatment' => $tax->treatment ?: ($tax->rate > 0 ? 'standard_rated' : 'zero_rated'),
            'source' => $source,
            // MRD R4.2: recoverability travels with the resolved rule.
            'creditable' => $tax->creditable !== null ? (bool) $tax->creditable : true,
            'blocked_account_id' => $tax->blocked_expense_account_id ? (int) $tax->blocked_expense_account_id : null,
        ];
    }

    private function none(string $jurisdiction, string $reason): array
    {
        return [
            'rate' => 0.0, 'is_inclusive' => false, 'tax_code' => 'NO-TAX',
            'tax_id' => null, 'tax_name' => 'No Tax', 'jurisdiction' => $jurisdiction,
            'regime' => $this->packs()->regime($jurisdiction),
            'scope' => 'both', 'treatment' => 'out_of_scope', 'source' => 'none', 'reason' => $reason,
        ];
    }

    private function isEnabled(): bool
    {
        return (bool) (Setting::getValue('tax', ['enabled' => true])['enabled'] ?? true);
    }

    /**
     * #746 T13: walk superseded_by_id until a rule that is valid on $date and
     * direction-compatible is found. Null when the chain ends without one.
     */
    private function resolveSuccessor(Tax $tax, string $date, string $direction): ?Tax
    {
        $seen = [];
        $current = $tax;

        while ($current->superseded_by_id !== null && ! isset($seen[$current->id])) {
            $seen[$current->id] = true;

            $next = Tax::query()
                ->whereKey($current->superseded_by_id)
                ->where('is_active', true)
                ->where('lifecycle_state', 'ACTIVE')
                ->first();

            if ($next === null) {
                return null;
            }

            if ($this->isValidOn($next, $date) && $this->scopeMatches($next, $direction)) {
                return $next;
            }

            $current = $next;
        }

        return null;
    }

    /**
     * #746 T13: a resolution that must stop the document, carrying the reason
     * instead of a silent zero or a fallback rate. The determination marks
     * the result TAX_RULE_EXPIRED and TaxGate refuses to post it.
     */
    private function blockedExpiredRule(Tax $tax, string $jurisdiction, string $direction): array
    {
        $expired = $tax->effective_to?->toDateString() ?? 'unknown date';

        return [
            'rate' => 0.0,
            'is_inclusive' => false,
            'tax_code' => null,
            'tax_id' => null,
            'tax_name' => 'Expired tax rule',
            'jurisdiction' => strtoupper((string) ($tax->jurisdiction ?: $jurisdiction)),
            'regime' => strtoupper((string) ($tax->regime ?: $this->packs()->regime($jurisdiction))),
            'scope' => $direction,
            'treatment' => 'unresolved',
            'source' => 'expired_rule_no_successor',
            'blocked' => true,
            'warning' => sprintf(
                'TAX_RULE_EXPIRED_NO_SUCCESSOR: product tax rule %s expired on %s and has no successor; link a successor rule or set an explicit tax on the product.',
                $tax->code ?: (string) $tax->id,
                $expired,
            ),
        ];
    }

    /**
     * #746 T13 AC3: products pinned to a rule that can no longer resolve —
     * expired, inactive, deleted or missing — with the successor status so
     * the fix is actionable.
     *
     * @return array<int, array<string, mixed>>
     */
    public function expiredRuleProducts(): array
    {
        $today = now()->toDateString();
        $rows = [];

        foreach (Product::query()->whereNotNull('tax_id')->orderBy('name')->get(['id', 'name', 'sku', 'tax_id']) as $product) {
            $rule = Tax::withTrashed()->find($product->tax_id);

            $valid = $rule !== null
                && ! $rule->trashed()
                && $rule->is_active
                && $this->isValidOn($rule, $today)
                && $this->scopeMatches($rule, 'output');

            if ($valid) {
                continue;
            }

            $successor = $rule !== null ? $this->resolveSuccessor($rule, $today, 'output') : null;

            $rows[] = [
                'product_id' => (int) $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'tax_rule_id' => (int) $product->tax_id,
                'tax_rule_code' => $rule?->code,
                'effective_to' => $rule?->effective_to?->toDateString(),
                'has_successor' => $successor !== null,
                'successor_rule_id' => $successor?->id,
                'reason' => $rule === null
                    ? 'rule_missing'
                    : ($rule->trashed() ? 'rule_deleted' : (! $rule->is_active ? 'rule_inactive' : 'rule_expired')),
            ];
        }

        return $rows;
    }

    private function isValidOn(Tax $tax, string $date): bool
    {
        return (! $tax->effective_from || $tax->effective_from->toDateString() <= $date)
            && (! $tax->effective_to || $tax->effective_to->toDateString() >= $date);
    }

    private function scopeMatches(Tax $tax, string $direction): bool
    {
        return ! $tax->scope
            || $tax->scope === 'both'
            || $tax->scope === $direction;
    }
}
