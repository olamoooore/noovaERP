<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SettlementAuditLog
 * 
 * Tracks invoice settlement mutations: paid_amount, balance, and status changes.
 * Provides durable audit trail for all settlement operations.
 */
class SettlementAuditLog extends Model
{
    protected $table = 'settlement_audit_logs';

    protected $fillable = [
        'invoice_id',
        'payment_id',
        'payment_amount',
        'before_paid_amount',
        'after_paid_amount',
        'before_balance',
        'after_balance',
        'before_status',
        'after_status',
        'actor_user_id',
        'settlement_method',
        'notes',
    ];

    protected $casts = [
        'payment_amount' => 'decimal:2',
        'before_paid_amount' => 'decimal:2',
        'after_paid_amount' => 'decimal:2',
        'before_balance' => 'decimal:2',
        'after_balance' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}