<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APTransaction — Read-only Eloquent model backed by the ap_transactions VIEW.
 *
 * View columns:
 *   transaction_type, transaction_id, document_number, vendor_id,
 *   vendor_name, transaction_date, due_date, debit, credit, amount,
 *   status, currency_code, created_at, updated_at
 *
 * NOTE: Views have no real primary key.  We use 'transaction_id' as a
 * logical key so Eloquent hydration works, but the combination
 * (transaction_type, transaction_id) is actually unique.
 */
class APTransaction extends Model
{
    protected $table = 'ap_transactions';

    /**
     * Views have no auto-incrementing surrogate key.
     */
    protected $primaryKey = 'transaction_id';

    public $incrementing = false;

    /**
     * The view is read-only — no inserts/updates.
     */
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'transaction_date' => 'date',
        'due_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    /**
     * Vendor relationship (works because the view exposes vendor_id).
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
