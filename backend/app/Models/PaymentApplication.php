<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #155: an explicit application of a payment amount to an invoice.
 * A payment may have many applications (split payment) or none (unapplied
 * credit on account).
 */
class PaymentApplication extends Model
{
    protected $table = 'payment_applications';

    protected $fillable = [
        'tenant_id',
        'payment_id',
        'invoice_id',
        'applied_amount',
        'currency_code',
        'created_by',
    ];

    protected $casts = [
        'applied_amount' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
