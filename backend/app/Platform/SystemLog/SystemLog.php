<?php

namespace App\Platform\SystemLog;

use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    protected $table = 'platform_system_logs';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';
    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'user_id' => 'integer',
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
}
