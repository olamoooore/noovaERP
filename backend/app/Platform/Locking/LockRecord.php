<?php

namespace App\Platform\Locking;

use Illuminate\Database\Eloquent\Model;

class LockRecord extends Model
{
    protected $table = 'platform_lock_records';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'acquired_at' => 'datetime',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
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
