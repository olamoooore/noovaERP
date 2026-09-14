<?php

namespace Modules\AccountingFoundation\Enums;

/**
 * ACC-001 Opening Balance — controlled canonical population-scope catalogue.
 *
 * Issue #70 HR70-01: population_scope MUST be drawn from a closed, canonical
 * catalogue rather than an arbitrary free-text string. Economic-baseline
 * uniqueness is keyed on the canonical enum value, so an actor cannot evade
 * the one-baseline-per-scope rule by inventing a new free-text scope string
 * for the same underlying population.
 *
 * Each case carries a canonical key (persisted) and the semantic label used
 * in overlap reasoning. `full_trial_balance` is the widest scope; the others
 * are proper subsets. Two batches with overlapping/equal canonical scopes for
 * the same tenant/entity/book/cutover collide (enforced via scopeKey + the
 * DB-backed partial unique index).
 */
enum PopulationScope: string
{
    case FULL_TRIAL_BALANCE = 'full_trial_balance';
    case AR_ONLY = 'ar_only';
    case AP_ONLY = 'ap_only';
    case INVENTORY_ONLY = 'inventory_only';
    case FIXED_ASSETS_ONLY = 'fixed_assets_only';
    case BANK_CASH_ONLY = 'bank_cash_only';

    public function label(): string
    {
        return str_replace('_', ' ', ucwords($this->value, '_'));
    }

    /**
     * All valid canonical values (for validation rules).
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, self::cases()));
    }

    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }
}
