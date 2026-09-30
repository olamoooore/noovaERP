<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StorefrontOrder extends Model
{
    use SoftDeletes;

    protected $table = 'storefront_orders';

    protected $fillable = [
        'order_number', 'account_id', 'customer_id', 'invoice_id', 'sales_order_id',
        'legal_entity_id', 'email', 'phone', 'status', 'payment_status',
        'subtotal', 'discount_amount', 'shipping_amount', 'tax_amount', 'total',
        'currency', 'shipping_address', 'billing_address', 'shipping_method',
        'tax_breakdown', 'channel', 'idempotency_key', 'guest_token_hash',
        'placed_at', 'paid_at', 'cancelled_at', 'tracking_numbers',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'shipping_method' => 'array',
        'tax_breakdown' => 'array',
        'tracking_numbers' => 'array',
        'placed_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(StorefrontAccount::class, 'account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Invoice::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrder::class, 'sales_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StorefrontOrderItem::class, 'order_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(StorefrontOrderStatusHistory::class, 'order_id')->latest('id');
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(StorefrontOrderPaymentAttempt::class, 'order_id');
    }

    public function paymentProofs(): HasMany
    {
        return $this->hasMany(StorefrontPaymentProof::class, 'order_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(StorefrontReturn::class, 'order_id');
    }
}
