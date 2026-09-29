<?php

namespace Modules\PaymentGateway\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSettlement extends Model
{
    protected $table = 'payment_settlements';

    protected $fillable = [
        'payment_transaction_id',
        'settlement_reference',
        'gateway_txn_id',
        'gross_amount',
        'fee_amount',
        'net_amount',
        'currency',
        'settled_at',
        'journal_entry_id',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'settled_at' => 'date',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }
}
