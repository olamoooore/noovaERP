<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class VendorDebitNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'debit_note_number',
        'vendor_id',
        'bill_id',
        'debit_note_date',
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
        'debit_note_date' => 'date',
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

        static::creating(function ($debitNote) {
            if (! $debitNote->debit_note_number) {
                $debitNote->debit_note_number = static::generateDebitNoteNumber();
            }
            if (! $debitNote->created_by) {
                $debitNote->created_by = TenantUserHelper::resolveTenantUserId();
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
        return $this->hasMany(VendorDebitNoteLine::class, 'debit_note_id');
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

    public function getVendorNameAttribute(): ?string
    {
        return $this->vendor?->name;
    }

    public function getBillNumberAttribute(): ?string
    {
        return $this->bill?->bill_number;
    }

    public static function generateDebitNoteNumber(): string
    {
        $latest = static::orderBy('id', 'desc')->first();
        $number = $latest ? (int) substr($latest->debit_note_number, -4) + 1 : 1;

        return 'VDN-'.date('Y').'-'.str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function calculateTotals(): void
    {
        $this->subtotal = $this->lines->sum('line_total');
        // MRD R3.1: single tax arithmetic via TaxEngineService::calculate().
        $this->tax_amount = $this->lines->sum(function ($line) {
            return \App\Services\TaxEngineService::calculate((float) $line->line_total, (float) $line->tax_rate, false)['tax'];
        });
        $this->total_amount = $this->subtotal + $this->tax_amount;
        $this->save();
    }
}
