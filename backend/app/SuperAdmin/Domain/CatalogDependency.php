<?php

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entitlement dependency between two catalog items.
 * This table is strictly for "what requires what" logic.
 * Pricing rules live in catalog_price_rules (Phase D).
 * Visibility lives in catalog_visibility_rules.
 */
class CatalogDependency extends Model
{
    protected $table = 'catalog_dependencies';

    protected $fillable = [
        'item_id',
        'depends_on_item_id',
        'type',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function item(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'item_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'depends_on_item_id');
    }
}
