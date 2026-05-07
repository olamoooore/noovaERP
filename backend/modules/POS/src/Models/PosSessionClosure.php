<?php

namespace Modules\POS\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSessionClosure extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'payment_method',
        'payment_method_name',
        'expected_amount',
        'counted_amount',
        'difference',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'counted_amount' => 'decimal:2',
        'difference' => 'decimal:2',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }
}
