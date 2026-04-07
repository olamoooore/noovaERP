<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionInvoice extends Model
{
    protected $table = 'subscription_invoices';

    protected $fillable = [
        'subscription_id',
        'tenant_id',
        'invoice_number',
        'billing_interval',
        'currency',
        'subtotal',
        'tax',
        'total',
        'status',
        'due_date',
        'paid_at',
        'period_start',
        'period_end',
        'notes',
    ];

    protected $casts = [
        'subtotal'     => 'integer',
        'tax'          => 'integer',
        'total'        => 'integer',
        'due_date'     => 'date',
        'paid_at'      => 'datetime',
        'period_start' => 'datetime',
        'period_end'   => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function totalInMajorUnits(): float
    {
        return $this->total / 100;
    }

    public function subtotalInMajorUnits(): float
    {
        return $this->subtotal / 100;
    }

    public function toAuditSnapshot(): array
    {
        return $this->only([
            'id',
            'invoice_number',
            'total',
            'status',
            'period_start',
            'period_end',
        ]);
    }
}
