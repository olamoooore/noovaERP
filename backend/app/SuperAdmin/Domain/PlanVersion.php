<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanVersion extends Model
{
    protected $table = 'plan_versions';

    protected $fillable = [
        'plan_family_id',
        'version_label',
        'description',
        'is_active',
        'is_publicly_listed',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_publicly_listed' => 'boolean',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(PlanFamily::class, 'plan_family_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class, 'plan_version_id');
    }

    public function planModules(): HasMany
    {
        return $this->hasMany(PlanModule::class, 'plan_version_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query->where('is_publicly_listed', true);
    }

    public function toAuditSnapshot(): array
    {
        return $this->only(['id', 'plan_family_id', 'version_label', 'is_active', 'is_publicly_listed']);
    }
}
