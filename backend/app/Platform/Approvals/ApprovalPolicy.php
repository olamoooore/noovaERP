<?php

namespace App\Platform\Approvals;

use Illuminate\Database\Eloquent\Model;

class ApprovalPolicy extends Model
{
    protected $table = 'platform_approval_policies';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'threshold_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'escalation_hours' => 'integer',
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

    public function scopeForType($query, string $type)
    {
        return $query->where('request_type', $type);
    }
}
