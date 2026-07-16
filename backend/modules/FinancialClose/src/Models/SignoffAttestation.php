<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignoffAttestation extends Model
{
    use HasUuids;

    protected $table = 'fco_signoff_attestations';

    protected $fillable = [
        'tenant_id',
        'signoff_id',
        'attested_by',
        'status',
        'attestation_text',
        'attested_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'attested_at' => 'datetime',
    ];

    public function signoff(): BelongsTo
    {
        return $this->belongsTo(Signoff::class, 'signoff_id');
    }
}
