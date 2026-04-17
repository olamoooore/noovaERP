<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BillPayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'payment_number',
        'vendor_id',
        'bill_id',
        'payment_date',
        'amount',
        'payment_method',
        'bank_account_id',
        'reference',
        'memo',
        'journal_entry_id',
        'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();

        // Workstream 2 (2026-04-15): Prevent deletion of posted bill payments
        static::deleting(function ($payment) {
            if ($payment->journal_entry_id !== null) {
                throw new \RuntimeException(
                    "Cannot delete posted bill payment #{$payment->payment_number}. " .
                    "Posted payments must be reversed, not deleted. " .
                    "Create a reversing entry to cancel this payment."
                );
            }
        });
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Generate next payment number
     */
    public static function generatePaymentNumber(): string
    {
        $year = date('Y');
        $lastPayment = self::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastPayment && preg_match('/BP-(\d{4})-(\d+)/', $lastPayment->payment_number, $matches)) {
            $nextNumber = intval($matches[2]) + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('BP-%s-%04d', $year, $nextNumber);
    }
}
