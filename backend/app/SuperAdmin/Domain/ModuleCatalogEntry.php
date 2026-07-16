<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModuleCatalogEntry extends Model
{
    protected $table = 'module_catalog';

    protected $fillable = [
        'module_key',
        'name',
        'description',
        'type',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function planModules(): HasMany
    {
        return $this->hasMany(PlanModule::class, 'module_catalog_id');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(ModuleDependency::class, 'module_catalog_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function toAuditSnapshot(): array
    {
        return $this->only(['id', 'module_key', 'name', 'type', 'is_active']);
    }
}
