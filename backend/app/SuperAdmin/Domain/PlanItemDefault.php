<?php

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tier Matrix row — one record per (plan_family × catalog_item) pair.
 *
 * Availability:
 *   included    — bundled with tier, no extra charge
 *   add_on      — purchasable separately on top of tier
 *   hidden      — not shown or offered for this tier
 *   custom_only — enterprise negotiation only, not self-serve
 *
 * Missing row = effective availability is 'hidden'.
 */
class PlanItemDefault extends Model
{
    protected $table = 'plan_item_defaults';

    protected $fillable = [
        'plan_family_id',
        'catalog_item_id',
        'availability',
        'notes',
    ];

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    public const AVAILABILITY_INCLUDED    = 'included';
    public const AVAILABILITY_ADD_ON      = 'add_on';
    public const AVAILABILITY_HIDDEN      = 'hidden';
    public const AVAILABILITY_CUSTOM_ONLY = 'custom_only';

    public const AVAILABILITIES = [
        self::AVAILABILITY_INCLUDED,
        self::AVAILABILITY_ADD_ON,
        self::AVAILABILITY_HIDDEN,
        self::AVAILABILITY_CUSTOM_ONLY,
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function planFamily(): BelongsTo
    {
        return $this->belongsTo(PlanFamily::class, 'plan_family_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForTier($query, int $planFamilyId)
    {
        return $query->where('plan_family_id', $planFamilyId);
    }

    public function scopeForItem($query, int $catalogItemId)
    {
        return $query->where('catalog_item_id', $catalogItemId);
    }

    public function scopeIncluded($query)
    {
        return $query->where('availability', self::AVAILABILITY_INCLUDED);
    }

    public function scopeAddOn($query)
    {
        return $query->where('availability', self::AVAILABILITY_ADD_ON);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function toAuditSnapshot(): array
    {
        return $this->only(['plan_family_id', 'catalog_item_id', 'availability', 'notes']);
    }
}
