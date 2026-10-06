<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issue #461 (DO9): a customer return recorded against a completed delivery
 * order. One DO can have many partial returns; each return records the
 * quantity, reason, lot/serial that actually came back and restocks exactly
 * that through the movement path (InventoryService `return` movements). The
 * delivery order itself stays `done` — a partial return is never a reversal.
 */
class DeliveryOrderReturn extends Model
{
    protected $fillable = [
        'return_number',
        'delivery_order_id',
        'customer_id',
        'warehouse_id',
        'status',
        'reason',
        'notes',
        'credit_note_id',
        'created_by',
        'returned_at',
    ];

    protected $casts = [
        'returned_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (DeliveryOrderReturn $return) {
            if (empty($return->return_number)) {
                $return->return_number = static::generateNumber();
            }
        });
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Warehouse::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(\App\Models\CustomerCreditNote::class, 'credit_note_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryOrderReturnLine::class);
    }

    public static function generateNumber(): string
    {
        return app(\App\Platform\Numbering\NumberingService::class)->nextFor(
            'delivery_order_return',
            'DOR-{YYYY}-{SEQ:4}',
            null,
            fn (string $prefix): array => static::where('return_number', 'like', $prefix.'%')
                ->pluck('return_number')
                ->all(),
        );
    }
}
