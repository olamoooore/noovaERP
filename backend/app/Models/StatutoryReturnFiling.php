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
        'boxes',
        'filed_by',
        'filed_at',
    ];

    protected $casts = [
        'boxes' => 'array',
        'filed_at' => 'datetime',
    ];

    public static function entityKey(?int $legalEntityId): int
    {
        return $legalEntityId ?? 0;
    }
}
