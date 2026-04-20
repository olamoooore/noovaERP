<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignoffReopenEvent extends Model
{
    use HasUuids;

    protected $table = 'fco_signoff_reopen_events';

    protected $fillable = [
        'tenant_id',
        'signoff_id',
        'reopened_by',
        'authorized_by',
        'reason',
        'reopened_at',
    ];

    protected $casts = [
        'reopened_at' => 'datetime',
    ];

    public function signoff(): BelongsTo
    {
        return $this->belongsTo(Signoff::class, 'signoff_id');
    }
}