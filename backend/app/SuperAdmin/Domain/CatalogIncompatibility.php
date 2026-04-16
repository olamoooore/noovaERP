<?php

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mutual exclusion between two catalog items.
 * Stored directionally (item_id → incompatible_item_id) but treated
 * as symmetric by CatalogService — checking both directions.
 */
class CatalogIncompatibility extends Model
{
    protected $table = 'catalog_incompatibilities';

    protected $fillable = [
        'item_id',
        'incompatible_item_id',
        'reason',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function item(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'item_id');
    }

    public function incompatibleItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'incompatible_item_id');
    }
}
