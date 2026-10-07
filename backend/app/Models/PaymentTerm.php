<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Issue #324: optional instalment lines (percent / balance).
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PaymentTermLine::class)->orderBy('sequence')->orderBy('id');
    }

    /**
     * Calculate due date based on payment terms. With instalment lines the
     * document's due date is the last instalment's due date.
     */
    public function calculateDueDate($invoiceDate): Carbon
    {
        $schedule = $this->instalmentSchedule($invoiceDate);
        if ($schedule !== []) {
            return Carbon::parse(end($schedule)['due_date']);
        }

        return self::offsetDate($invoiceDate, (string) $this->type, (int) $this->days);
    }

    /**
     * Issue #324: the instalment schedule for an invoice dated $invoiceDate —
     * each row is a due date and its share (percent) of the document total;
     * the balance line takes whatever the percentage lines leave. Ordered by
     * due date. Empty when the term has no instalment lines (single due date).
     *
     * @return list<array{sequence: int, due_date: string, percent: float}>
     */
    public function instalmentSchedule($invoiceDate): array
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();
        if ($lines->isEmpty()) {
            return [];
        }

        $allocated = 0.0;
        $rows = [];
        foreach ($lines as $index => $line) {
            $percent = $line->value_type === PaymentTermLine::VALUE_BALANCE
                ? null
                : round((float) $line->value, 4);
            $allocated += $percent ?? 0.0;

            $rows[] = [
                'sequence' => $index + 1,
                'due_date' => self::offsetDate($invoiceDate, (string) $line->delay_type, (int) $line->days)->toDateString(),
                'percent' => $percent,
            ];
        }

        $balance = max(0.0, round(100 - $allocated, 4));
        foreach ($rows as &$row) {
            $row['percent'] ??= $balance;
        }
        unset($row);

        usort($rows, fn (array $a, array $b) => [$a['due_date'], $a['sequence']] <=> [$b['due_date'], $b['sequence']]);

        foreach ($rows as $index => &$row) {
            $row['sequence'] = $index + 1;
        }
        unset($row);

        return $rows;
    }

    /**
     * One date rule for terms and their instalment lines: immediate, N days
     * after the invoice date, or N days after the end of the invoice month.
     */
    public static function offsetDate($invoiceDate, string $type, int $days): Carbon
    {
        $date = $invoiceDate instanceof Carbon ? $invoiceDate->copy() : Carbon::parse($invoiceDate);

        switch ($type) {
            case 'immediate':
                return $date;

            case 'end_of_month':
                return $date->endOfMonth()->startOfDay()->addDays($days);

            case 'net_days':
            default:
                return $date->addDays($days);
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
