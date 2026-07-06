<?php

namespace App\Platform\Exceptions;

use Illuminate\Database\Eloquent\Model;

class ExceptionQueue extends Model
{
    protected $table = 'platform_exception_queue';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';
    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
        'next_retry_at' => 'datetime',
        'resolved_at' => 'datetime',
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
