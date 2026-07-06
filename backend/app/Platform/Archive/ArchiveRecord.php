<?php

namespace App\Platform\Archive;

use Illuminate\Database\Eloquent\Model;

class ArchiveRecord extends Model
{
    protected $table = 'platform_archive_records';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';
    protected $guarded = [];

    protected $casts = [
        'archived_at' => 'datetime',
        'restored_at' => 'datetime',
        'retention_days' => 'integer',
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
