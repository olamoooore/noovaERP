<?php

namespace Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSessionStateHistory extends Model
{
    public $timestamps = false;

    protected $table = 'pos_session_state_history';

    protected $fillable = [
        'session_id',
        'from_state',
        'event_name',
        'to_state',
        'reason_code',
        'metadata_json',
        'created_at',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }
}
