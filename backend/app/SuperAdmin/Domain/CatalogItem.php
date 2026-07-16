<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogItem extends Model
{
    protected $table = 'catalog_items';

    protected $fillable = [
        'key',
        'name',
        'description',
        'type_id',
        'parent_id',
        'is_active',
        'sort_order',
        'metadata',
        'effective_from',
        'effective_to',
        'source',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function type(): BelongsTo
    {
        return $this->belongsTo(CatalogItemType::class, 'type_id');
    }

    /**
     * Parent is for UI grouping only — never use parent_id for business logic.
     * Business logic uses catalog_dependencies and catalog_incompatibilities.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(CatalogItem::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(CatalogDependency::class, 'item_id');
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(CatalogDependency::class, 'depends_on_item_id');
    }

    public function incompatibilities(): HasMany
    {
        return $this->hasMany(CatalogIncompatibility::class, 'item_id');
    }

    public function visibilityRules(): HasMany
    {
        return $this->hasMany(CatalogVisibilityRule::class, 'item_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEffective($query)
    {
        $today = now()->toDateString();

        return $query
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $today);
            });
    }

    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isEffective(): bool
    {
        $today = now()->toDateString();

        return $this->effective_from->toDateString() <= $today
            && ($this->effective_to === null || $this->effective_to->toDateString() >= $today);
    }

    public function toAuditSnapshot(): array
    {
        return $this->only([
            'id', 'key', 'name', 'type_id', 'parent_id',
            'is_active', 'effective_from', 'effective_to', 'source',
        ]);
    }
}
