<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanFamily extends Model
{
    protected $table = 'plan_families';

    protected $fillable = [
        'key',
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class, 'plan_family_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function toAuditSnapshot(): array
    {
        return $this->only(['id', 'key', 'name', 'is_active', 'sort_order']);
    }
}
