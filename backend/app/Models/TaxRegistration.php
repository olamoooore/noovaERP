<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MRD R5.3/R6.2: a legal entity's (or the tenant's, when entity_id is null)
 * registration with a tax authority — SST number, GST ID, TIN — effective on
 * dates. Feeds document registration prints, filing scope and the
 * registration-status card in the Tax Engine console.
 */
class TaxRegistration extends Model
{
    protected $fillable = [
        'legal_entity_id',
        'jurisdiction',
        'regime',
        'tax_profile_id',
        'registration_number',
        'effective_from',
        'effective_to',
        'status',
        'filing_start_month',
        'notes',
    ];

    protected $casts = [
        'filing_start_month' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
