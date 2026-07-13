<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'delivery_order_number',
        'sales_order_id',
        'customer_id',
        'warehouse_id',
        'status',
        'delivery_date',
        'expected_date',
        'carrier',
        'tracking_number',
        'shipping_address',
        'notes',
        'created_by',
        'confirmed_by',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'expected_date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->delivery_order_number)) {
                $order->delivery_order_number = static::generateNumber();
            }
        });
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryOrderLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'confirmed_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public static function generateNumber(): string
    {
        $year = date('Y');
        $prefix = "DO-{$year}-";

        $latest = static::withTrashed()
            ->where('delivery_order_number', 'like', $prefix . '%')
            ->orderBy('id', 'desc')
            ->first();

        $number = $latest
            ? (int) substr($latest->delivery_order_number, -4) + 1
            : 1;

        return $prefix . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}
