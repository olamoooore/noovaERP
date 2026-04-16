<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Models\PurchaseOrder;

class Bill extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'bill_number',
        'vendor_id',
        'purchase_order_id',
        'goods_receipt_id',
        'bill_date',
        'due_date',
        'vendor_reference',
        'internal_reference',
        'attention_to',
        'contact_email',
        'contact_phone',
        'vendor_address_snapshot',
        'remittance_address_snapshot',
        'company_registration_number',
        'tax_registration_number',
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'paid_amount',
        'balance',
        'notes',
        'terms',
        'journal_entry_id',
        'received_at',
        'created_by',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'received_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($bill) {
            if (!$bill->bill_number) {
                $bill->bill_number = static::generateBillNumber();
            }
        });

        // Workstream 2 (2026-04-15): Prevent deletion of posted bills
        static::deleting(function ($bill) {
            if ($bill->journal_entry_id !== null) {
                throw new \RuntimeException(
                    "Cannot delete posted bill #{$bill->bill_number}. " .
                    "Posted bills must be reversed or credited, not deleted. " .
                    "Create a vendor credit note to cancel this bill."
                );
            }

            // Prevent deletion if payments exist
            if ($bill->payments()->exists()) {
                $paymentCount = $bill->payments()->count();
                throw new \RuntimeException(
                    "Cannot delete bill #{$bill->bill_number} because {$paymentCount} payment(s) exist. " .
                    "Posted bills must be reversed or credited, not deleted."
                );
            }
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateBillNumber(): string
    {
        $latest = static::orderBy('id', 'desc')->first();
        $number = $latest ? (int) substr($latest->bill_number, -4) + 1 : 1;
        return 'BILL-' . date('Y') . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function calculateTotals(): void
    {
        $this->subtotal = $this->lines->sum('line_total');
        $this->tax_amount = $this->lines->sum('tax_amount');
        $this->total = $this->subtotal + $this->tax_amount - $this->discount_amount;
        $this->balance = $this->total - $this->paid_amount;
        $this->save();
    }
}
