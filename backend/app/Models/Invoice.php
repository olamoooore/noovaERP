<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'customer_id',
        'invoice_date',
        'due_date',
        'customer_reference',
        'internal_reference',
        'attention_to',
        'contact_email',
        'contact_phone',
        'billing_address_snapshot',
        'shipping_address_snapshot',
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
        'pos_order_id',
        'web_order_id',
        'sales_order_id',
        'sent_at',
        'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'sent_at' => 'datetime',
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

        static::creating(function ($invoice) {
            if (!$invoice->invoice_number) {
                $invoice->invoice_number = static::generateInvoiceNumber();
            }
        });

        // Workstream 2 (2026-04-15): Prevent deletion of posted invoices
        static::deleting(function ($invoice) {
            if ($invoice->journal_entry_id !== null) {
                throw new \RuntimeException(
                    "Cannot delete posted invoice #{$invoice->invoice_number}. " .
                    "Posted invoices must be reversed or credited, not deleted. " .
                    "Create a credit note to cancel this invoice."
                );
            }

            // Prevent deletion if payments exist
            if ($invoice->payments()->exists()) {
                $paymentCount = $invoice->payments()->count();
                throw new \RuntimeException(
                    "Cannot delete invoice #{$invoice->invoice_number} because {$paymentCount} payment(s) exist. " .
                    "Posted invoices must be reversed or credited, not deleted."
                );
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function posOrder(): BelongsTo
    {
        return $this->belongsTo(\Modules\POS\Models\PosOrder::class, 'pos_order_id');
    }

    public function webOrder(): BelongsTo
    {
        return $this->belongsTo(\Modules\Ecommerce\Models\WebOrder::class, 'web_order_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrder::class, 'sales_order_id');
    }

    public static function generateInvoiceNumber(): string
    {
        $latest = static::orderBy('id', 'desc')->first();
        $number = $latest ? (int) substr($latest->invoice_number, -4) + 1 : 1;
        return 'INV-' . date('Y') . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
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
