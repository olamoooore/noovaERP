<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #495: an application of a posted credit note's remaining credit to
 * another open invoice of the same customer (the credit-note counterpart of
 * PaymentApplication, #155).
 */
class CustomerCreditNoteApplication extends Model
{
    protected $table = 'customer_credit_note_applications';

    protected $fillable = [
        'credit_note_id',
        'invoice_id',
        'applied_amount',
        'currency_code',
        'reversed_at',
        'created_by',
    ];

    protected $casts = [
        'applied_amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditNote::class, 'credit_note_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
