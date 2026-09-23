<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class CustomerCreditNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_note_number',
        'customer_id',
        'invoice_id',
        // Issue #154: lineage back to the originating sales order.
        'sales_order_id',
        'credit_note_date',
        'due_date',
        'status',
        'subtotal',
        'tax_amount',
        'total_amount',
        'currency_code',
        'reason',
        'notes',
        'journal_entry_id',
        'created_by',
        'posted_by',
        'posted_at',
        // Issue #228: void transition metadata.
        'voided_at',
        'voided_by',
        'reversal_journal_entry_id',
    ];

    protected $casts = [
        'credit_note_date' => 'date',
        'due_date' => 'date',
        'posted_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    protected $appends = [
        'customer_name',
        'invoice_number',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($creditNote) {
            if (! $creditNote->credit_note_number) {
                $creditNote->credit_note_number = static::generateCreditNoteNumber();
            }
            if (! $creditNote->created_by) {
                $creditNote->created_by = TenantUserHelper::resolveTenantUserId();
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrder::class, 'sales_order_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerCreditNoteLine::class, 'credit_note_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    // Accessors for frontend
    public function getCustomerNameAttribute(): ?string
    {
        return $this->customer?->name;
    }

    public function getInvoiceNumberAttribute(): ?string
    {
        return $this->invoice?->invoice_number;
    }

    public static function generateCreditNoteNumber(): string
    {
        $latest = static::orderBy('id', 'desc')->first();
        $number = $latest ? (int) substr($latest->credit_note_number, -4) + 1 : 1;

        return 'CN-'.date('Y').'-'.str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function calculateTotals(): void
    {
        $this->subtotal = $this->lines->sum('line_total');
        $this->tax_amount = $this->lines->sum(function ($line) {
            return ($line->line_total * $line->tax_rate) / 100;
        });
        $this->total_amount = $this->subtotal + $this->tax_amount;
        $this->save();
    }
}
