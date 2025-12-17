<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantFeature extends Model
{
    protected $fillable = [
        'feature_id',
        'is_enabled',
        'enabled_at',
        'expires_at',
    ];

    protected $casts = [
        'is_enabled' => 'bool',
        'enabled_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
