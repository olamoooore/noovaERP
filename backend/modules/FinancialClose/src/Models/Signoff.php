<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Signoff extends Model
{
    use HasUuids;

    protected $table = 'fci_signoffs';

    protected $fillable = [
        'tenant_id',
        'review_id',
        'signed_by',
        'role',
        'status',
        'notes',
        'signed_at',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(AccountReview::class, 'review_id');
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(SignoffAttestation::class, 'signoff_id');
    }
}
