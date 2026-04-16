<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pre-resolved entitlement snapshot for a tenant.
 *
 * Fully rebuilt by EntitlementService::rebuild() — never edit manually.
 * The ERP runtime queries this table for canAccess() checks.
 *
 * source:
 *   tier_included    — from plan_item_defaults (availability = included)
 *   add_on_purchased — tenant purchased this add-on
 *   override_include — admin-granted regardless of tier
 *   override_exclude — admin-blocked (is_entitled = false)
 */
class TenantEntitlement extends Model
{
    protected $table = 'tenant_entitlements';

    protected $fillable = [
        'tenant_id',
        'catalog_item_id',
        'is_entitled',
        'source',
        'resolved_at',
    ];

    protected $casts = [
        'is_entitled'  => 'boolean',
        'resolved_at'  => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    public const SOURCE_TIER_INCLUDED    = 'tier_included';
    public const SOURCE_ADD_ON_PURCHASED = 'add_on_purchased';
    public const SOURCE_OVERRIDE_INCLUDE = 'override_include';
    public const SOURCE_OVERRIDE_EXCLUDE = 'override_exclude';

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeEntitled($query)
    {
        return $query->where('is_entitled', true);
    }

    public function scopeBlocked($query)
    {
        return $query->where('is_entitled', false);
    }
}
