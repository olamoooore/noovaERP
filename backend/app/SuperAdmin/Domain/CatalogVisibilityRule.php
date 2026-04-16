<?php

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visibility and eligibility rules for catalog items.
 *
 * rule_type / condition examples:
 *   tier_whitelist   → {"tiers": ["enterprise", "professional"]}
 *   tier_blacklist   → {"tiers": ["trial"]}
 *   region_blacklist → {"regions": ["US", "CA"]}
 *   min_plan_key     → {"plan_key": "professional"}
 *   beta_only        → {"flag": "beta_catalog_2026"}
 *
 * Intentionally open-ended — new rule_types can be added without schema changes.
 */
class CatalogVisibilityRule extends Model
{
    protected $table = 'catalog_visibility_rules';

    protected $fillable = [
        'item_id',
        'rule_type',
        'condition',
    ];

    protected $casts = [
        'condition' => 'array',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function item(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'item_id');
    }
}
