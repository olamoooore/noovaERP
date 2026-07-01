<?php

namespace App\Features\ActivityLogging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLogEntry extends Model
{
    protected $table = 'activity_log';

    protected $fillable = [
        'causer_type',
        'causer_id',
        'subject_type',
        'subject_id',
        'event',
        'properties',
        'batch_uuid',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
