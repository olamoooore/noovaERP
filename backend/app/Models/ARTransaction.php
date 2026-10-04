<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ARTransaction — Read-only Eloquent model backed by the ar_transactions VIEW.
 *
 * View columns:
 *   transaction_type, transaction_id, document_number, customer_id,
 *   customer_name, transaction_date, due_date, debit, credit, amount,
 *   status, currency_code, created_at, updated_at, legal_entity_id
 *
 * NOTE: Views have no real primary key.  We use 'transaction_id' as a
 * logical key so Eloquent hydration works, but the combination
 * (transaction_type, transaction_id) is actually unique.
 */
class ARTransaction extends Model
{
    protected $table = 'ar_transactions';

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
     * Customer relationship (works because the view exposes customer_id).
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
