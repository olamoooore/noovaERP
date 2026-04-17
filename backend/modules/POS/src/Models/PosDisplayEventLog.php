<?php

namespace Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;

class PosDisplayEventLog extends Model
{
    public $timestamps = false;

    protected $table = 'pos_display_event_log';

    protected $fillable = [
        'session_id',
        'terminal_id',
        'event_name',
        'aggregate_type',
        'aggregate_id',
        'event_json',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'event_json' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
