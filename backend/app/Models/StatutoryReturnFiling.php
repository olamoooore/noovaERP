<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Issue #265: a filed statutory return snapshot (F5/SST-02), used as the F7
 * baseline for error disclosure.
 */
class StatutoryReturnFiling extends Model
{
    protected $table = 'statutory_return_filings';

    protected $fillable = [
        'tenant_id',
        'country',
        'form',
        'period',
        'entity_key',
        'filing_version',
        'boxes',
        'filed_by',
        'filed_at',
        'superseded_at',
        'state',
        'authority_reference',
        'state_updated_at',
    ];

    protected $casts = [
        'boxes' => 'array',
        'filing_version' => 'integer',
        'filed_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public static function entityKey(?int $legalEntityId): int
    {
        return $legalEntityId ?? 0;
    }
}
