<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class VendorCreditNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_note_number',
        'vendor_id',
        'bill_id',
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
        'vendor_name',
        'bill_number',
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VendorCreditNoteLine::class, 'credit_note_id');
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
    public function getVendorNameAttribute(): ?string
    {
        return $this->vendor?->name;
    }

    public function getBillNumberAttribute(): ?string
    {
        return $this->bill?->bill_number;
    }

    public static function generateCreditNoteNumber(): string
    {
        $latest = static::orderBy('id', 'desc')->first();
        $number = $latest ? (int) substr($latest->credit_note_number, -4) + 1 : 1;

        return 'VCN-'.date('Y').'-'.str_pad($number, 4, '0', STR_PAD_LEFT);
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
