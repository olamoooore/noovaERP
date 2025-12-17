<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feature extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_core',
        'price',
    ];

    protected $casts = [
        'is_core' => 'bool',
        'price' => 'decimal:2',
    ];

    public function tenantFeatures(): HasMany
    {
        return $this->hasMany(TenantFeature::class);
    }

    public function isEnabledForTenant(): bool
    {
        $tf = $this->tenantFeatures()->latest('id')->first();
        if (!$tf) {
            return false;
        }

        if (!$tf->is_enabled) {
            return false;
        }

        if ($tf->expires_at && $tf->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
