<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'completed_at',
        'source',
        'external_reference',
        'po_number',
        'salesperson',
        'invoice_id',
        'quotation_id',
        'carrier',
        'tracking_number',
        'shipping_address',
        'shipping_address_overridden',
        'notes',
        'total',
        'created_by',
        'confirmed_by',
        'completed_by',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'expected_date' => 'date',
        'completed_at' => 'datetime',
        'shipping_address_overridden' => 'boolean',
        'total' => 'decimal:2',
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

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'completed_by');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Invoice::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Quotation::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'done';
    }

    public static function generateNumber(): string
    {
        try {
            return app(\App\Services\DocumentNumberingService::class)
                ->generate('delivery_order', 'DO-{YYYY}-{SEQ:4}');
        } catch (\Throwable $e) {
            // Fallback
        }

        $year = date('Y');
        $prefix = "DO-{$year}-";
        $latest = static::withTrashed()
            ->where('delivery_order_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->first();
        $number = $latest ? (int) substr($latest->delivery_order_number, -4) + 1 : 1;
        return $prefix.str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}
