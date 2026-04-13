<?php

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit tenant selection beyond base tier defaults.
 *
 * selection_type:
 *   add_on_purchased — tenant paid for this add-on
 *   override_include — admin granted access regardless of tier
 *   override_exclude — admin blocked access regardless of tier
 */
class TenantSelectedItem extends Model
{
    protected $table = 'tenant_selected_items';

    protected $fillable = [
        'tenant_id',
        'catalog_item_id',
        'selection_type',
        'notes',
        'selected_by_admin_id',
    ];

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    public const TYPE_ADD_ON_PURCHASED = 'add_on_purchased';
    public const TYPE_OVERRIDE_INCLUDE = 'override_include';
    public const TYPE_OVERRIDE_EXCLUDE = 'override_exclude';

    public const TYPES = [
        self::TYPE_ADD_ON_PURCHASED,
        self::TYPE_OVERRIDE_INCLUDE,
        self::TYPE_OVERRIDE_EXCLUDE,
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    public function selectedByAdmin(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'selected_by_admin_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeAddOns($query)
    {
        return $query->where('selection_type', self::TYPE_ADD_ON_PURCHASED);
    }

    public function scopeOverrideIncludes($query)
    {
        return $query->where('selection_type', self::TYPE_OVERRIDE_INCLUDE);
    }

    public function scopeOverrideExcludes($query)
    {
        return $query->where('selection_type', self::TYPE_OVERRIDE_EXCLUDE);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function toAuditSnapshot(): array
    {
        return $this->only([
            'tenant_id', 'catalog_item_id', 'selection_type', 'notes',
        ]);
    }
}
