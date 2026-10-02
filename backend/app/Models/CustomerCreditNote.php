<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class CustomerCreditNote extends Model
{
    use \App\Models\Concerns\ResolvesLegalEntityContext;
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
        'discount_amount',
        'total_amount',
        'currency_code',
        'exchange_rate',
        'base_currency_code',
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
        // Issue #875: Sales documents carry their legal entity + book.
        'legal_entity_id',
        'entity_book_id',
    ];

    protected $casts = [
        'credit_note_date' => 'date',
        'due_date' => 'date',
        'posted_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
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
            // MRD FR-5.9: a credit note inherits the INVOICE currency and rate
            // snapshot; never its own defaults. Post-creation code may still
            // re-stamp from the source invoice — this hook is the safety net.
            if (! $creditNote->currency_code || $creditNote->currency_code === 'USD') {
                $invoiceCurrency = $creditNote->invoice_id
                    ? \App\Models\Invoice::query()->whereKey($creditNote->invoice_id)->value('currency_code')
                    : null;
                if ($invoiceCurrency) {
                    $creditNote->currency_code = $invoiceCurrency;
                }
            }
            if ($creditNote->exchange_rate === null && $creditNote->invoice_id) {
                $creditNote->exchange_rate = \App\Models\Invoice::query()->whereKey($creditNote->invoice_id)->value('exchange_rate') ?? 1.000000;
            }
            if (! $creditNote->base_currency_code) {
                $creditNote->base_currency_code = \App\Services\DocumentCurrencyResolver::baseFor(null, $creditNote->customer_id);
            }
            if (! $creditNote->currency_code) {
                $creditNote->currency_code = $creditNote->base_currency_code;
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
        // #512 (CN3): mirrors Invoice::calculateTotals — nets of line
        // discounts, document discount prorated into tax. Lines carry their
        // own tax_amount via the shared line arithmetic, so no second
        // formula remains.
        $this->subtotal = round((float) $this->lines->sum(
            fn ($line) => (float) $line->line_total - (float) $line->tax_amount
        ), 2);
        $lineTaxSum = round((float) $this->lines->sum('tax_amount'), 2);
        $discount = (float) ($this->discount_amount ?? 0);

        if ($discount > 0 && $this->subtotal > 0) {
            $this->tax_amount = round($lineTaxSum * (max($this->subtotal - $discount, 0) / $this->subtotal), 2);
        } else {
            $this->tax_amount = $lineTaxSum;
        }

        $this->total_amount = round($this->subtotal - $discount + $this->tax_amount, 2);
        $this->save();
    }
}
