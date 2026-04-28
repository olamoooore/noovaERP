<?php

namespace Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class PosPayment extends Model
{
    protected $table = 'pos_payments';

    protected $fillable = [
        'pos_order_id',
        'payment_method_id',
        'method',
        'amount',
        'reference',
        'surcharge',
        'received_by',
        'notes',
        'journal_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'surcharge' => 'decimal:2',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(\App\Models\JournalEntry::class, 'journal_entry_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PosPaymentMethod::class, 'payment_method_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
