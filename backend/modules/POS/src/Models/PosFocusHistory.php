<?php

namespace Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosFocusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'pos_focus_history';

    protected $fillable = [
        'session_id',
        'line_id',
        'product_id',
        'focus_reason',
        'status_label',
        'image_url',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }
}
