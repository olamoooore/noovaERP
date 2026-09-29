<?php

namespace Modules\Inventory\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'line_type',
        'sequence',
        'purchase_order_id',
        'product_id',
        'description',
        'quantity_ordered',
        'quantity_received',
        'unit_cost',
        'tax_rate',
        'tax_amount',
        'discount_percent',
        'line_total',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'quantity_ordered' => 'integer',
        'quantity_received' => 'integer',
        'unit_cost' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function calculateLineTotal(): void
    {
        if (in_array($this->line_type, ['note', 'section'], true)) {
            $this->product_id = null;
            $this->quantity_ordered = 0;
            $this->unit_cost = 0;
            $this->tax_rate = 0;
            $this->tax_amount = 0;
            $this->discount_percent = 0;
            $this->line_total = 0;

            return;
        }
        // MRD R3.1: engine-owned arithmetic.
        $calc = \App\Services\TaxEngineService::calculate(
            (float) ($this->quantity_ordered * $this->unit_cost),
            (float) $this->tax_rate,
            false,
            round((float) ($this->quantity_ordered * $this->unit_cost) * ((float) $this->discount_percent / 100), 2),
        );
        $this->tax_amount = $calc['tax'];
        $this->line_total = $calc['gross'];
    }

    public function getRemainingQuantityAttribute(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    public function isFullyReceived(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }
}
