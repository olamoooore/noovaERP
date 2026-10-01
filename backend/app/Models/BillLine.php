<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'bill_id',
        'line_type',
        'product_id',
        'description',
        'model_number',
        'product_notes',
        'lot_number',
        'serial_number',
        'expiry_date',
        'quantity',
        'unit_price',
        'tax_rate',
        'tax_amount',
        'line_total',
        'account_id',
        'cost_center_id',
        'project_id',
        'department_id',
        'branch_id',
        'purchase_order_item_id',
        'goods_receipt_line_id',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(\Modules\Inventory\Models\PurchaseOrderItem::class);
    }

    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }
}
