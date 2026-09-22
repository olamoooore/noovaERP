<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #94: impairment of a fixed asset (ACC-007). Carrying amount is reduced
 * to the recoverable amount and the loss is posted through PostingEngine.
 */
class ImpairmentRecord extends Model
{
    protected $fillable = [
        'tenant_id',
        'fixed_asset_id',
        'impairment_date',
        'carrying_amount_before',
        'recoverable_amount',
        'impairment_loss',
        'reason',
        'journal_entry_id',
        'created_by',
    ];

    protected $casts = [
        'impairment_date' => 'date',
        'carrying_amount_before' => 'decimal:2',
        'recoverable_amount' => 'decimal:2',
        'impairment_loss' => 'decimal:2',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
