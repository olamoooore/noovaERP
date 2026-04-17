<?php

namespace Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosDisplayPayload extends Model
{
    public $timestamps = false;

    protected $table = 'pos_display_payloads';

    protected $fillable = [
        'session_id',
        'payload_version',
        'display_state',
        'focused_line_id',
        'payload_json',
        'hash',
        'delivered_at',
        'created_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'delivered_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }
}
