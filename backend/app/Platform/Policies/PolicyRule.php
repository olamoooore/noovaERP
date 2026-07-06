<?php

namespace App\Platform\Policies;

use Illuminate\Database\Eloquent\Model;

class PolicyRule extends Model
{
    protected $table = 'platform_policy_rules';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';
    protected $guarded = [];

    protected $casts = [
        'rules' => 'array',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEntity($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }
}
