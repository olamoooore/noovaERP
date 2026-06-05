<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'payment_number',
        'customer_id',
        'vendor_id',
        'invoice_id',
        'bill_id',
        'payment_date',
        'amount',
        'discount_amount',
        'method',
        'reference',
        'notes',
        'bank_account_id',
        'journal_entry_id',
        'received_by',
        'tenant_id',
        'payment_status',
        'original_payment_id',
        'refund_payment_id',
        'correction_group_id',
        'correction_type',
        'correction_reason_code',
        'correction_reason_text',
        'unapplied_at',
        'refunded_at',
        'reversal_journal_entry_id',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'unapplied_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($payment) {
            if (!$payment->payment_number) {
                $payment->payment_number = static::generatePaymentNumber();
            }
            if (!$payment->payment_status) {
                $payment->payment_status = 'recorded';
            }
        });

        // Workstream 2 (2026-04-15): Prevent deletion of posted payments
        static::deleting(function ($payment) {
            if ($payment->journal_entry_id !== null) {
                throw new \RuntimeException(
                    "Cannot delete posted payment #{$payment->payment_number}. " .
                    "Posted payments must be reversed, not deleted. " .
                    "Use the refund endpoint to reverse this payment."
                );
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function generatePaymentNumber(): string
    {
        $latest = static::orderBy('id', 'desc')->first();
        $number = $latest ? (int) substr($latest->payment_number, -4) + 1 : 1;
        return 'PAY-' . date('Y') . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}
