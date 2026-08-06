<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonusSchemeUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_bonus_scheme_id',
        'sales_order_item_id',
        'invoice_item_id',
        'medical_case_item_id',
        'qualifying_qty',
        'discount_amount',
        'foc_qty',
        'status',
        'applied_at',
    ];

    protected $casts = [
        'qualifying_qty' => 'decimal:4',
        'discount_amount' => 'decimal:2',
        'foc_qty' => 'decimal:4',
        'applied_at' => 'datetime',
    ];

    // Status helpers
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApplied(): bool
    {
        return $this->status === 'applied';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    // Relationships
    public function customerBonusScheme(): BelongsTo
    {
        return $this->belongsTo(CustomerBonusScheme::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrderLine::class, 'sales_order_item_id');
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class, 'invoice_item_id');
    }

    public function medicalCaseItem(): BelongsTo
    {
        return $this->belongsTo(MedicalCaseItem::class, 'medical_case_item_id');
    }

    // Methods
    public function apply(): bool
    {
        if ($this->isApplied()) {
            return false;
        }

        $this->update([
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        return true;
    }

    public function reverse(): bool
    {
        if ($this->isReversed()) {
            return false;
        }

        $this->update([
            'status' => 'reversed',
        ]);

        return true;
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApplied($query)
    {
        return $query->where('status', 'applied');
    }

    public function scopeReversed($query)
    {
        return $query->where('status', 'reversed');
    }

    public function scopeForCustomer($query, $customerId)
    {
        return $query->whereHas('customerBonusScheme', function ($q) use ($customerId) {
            $q->where('customer_id', $customerId);
        });
    }
}
