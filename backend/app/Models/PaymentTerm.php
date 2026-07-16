<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Payment Term - Defines payment terms for invoices and bills
 */
class PaymentTerm extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'type',
        'days',
        'discount_percentage',
        'discount_days',
        'is_default',
        'is_active',
        'description',
    ];

    protected $casts = [
        'days' => 'integer',
        'discount_days' => 'integer',
        'discount_percentage' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Calculate due date based on payment terms
     */
    public function calculateDueDate($invoiceDate): Carbon
    {
        $date = $invoiceDate instanceof Carbon ? $invoiceDate : Carbon::parse($invoiceDate);

        switch ($this->type) {
            case 'immediate':
                return $date;

            case 'end_of_month':
                return $date->endOfMonth()->addDays($this->days);

            case 'net_days':
            default:
                return $date->addDays($this->days);
        }
    }

    /**
     * Calculate discount due date
     */
    public function calculateDiscountDueDate($invoiceDate): ?Carbon
    {
        if (! $this->discount_percentage || ! $this->discount_days) {
            return null;
        }

        $date = $invoiceDate instanceof Carbon ? $invoiceDate : Carbon::parse($invoiceDate);

        return $date->addDays($this->discount_days);
    }

    /**
     * Calculate discount amount
     */
    public function calculateDiscountAmount(float $amount): float
    {
        if (! $this->discount_percentage) {
            return 0;
        }

        return $amount * ((float) $this->discount_percentage / 100);
    }

    /**
     * Check if discount is applicable for a payment date
     */
    public function isDiscountApplicable($invoiceDate, $paymentDate): bool
    {
        $discountDueDate = $this->calculateDiscountDueDate($invoiceDate);

        if (! $discountDueDate) {
            return false;
        }

        $payment = $paymentDate instanceof Carbon ? $paymentDate : Carbon::parse($paymentDate);

        return $payment->lte($discountDueDate);
    }

    /**
     * Get display name with details
     */
    public function getDisplayNameAttribute(): string
    {
        $name = $this->name;

        if ($this->discount_percentage && $this->discount_days) {
            $name .= " ({$this->discount_percentage}% discount if paid within {$this->discount_days} days)";
        }

        return $name;
    }

    /**
     * Scope to get only active payment terms
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get default payment term
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Get the default payment term
     */
    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->where('is_active', true)->first()
            ?? static::where('is_active', true)->first();
    }
}
