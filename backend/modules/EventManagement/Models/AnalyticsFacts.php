<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AnalyticsFacts Model
 *
 * Stores immutable event-driven analytics facts for accurate reporting.
 * Each fact represents a specific event in the booking lifecycle.
 *
 * @property int $id
 * @property string $fact_type
 * @property int $booking_id
 * @property int $event_id
 * @property array $fact_data
 * @property array|null $calculated_metrics
 * @property \Carbon\Carbon $fact_timestamp
 * @property string $idempotency_key
 */
class AnalyticsFacts extends Model
{
    protected $table = 'analytics_facts';

    public $timestamps = false; // Only fact_timestamp, no created_at/updated_at

    protected $fillable = [
        'fact_type',
        'booking_id',
        'event_id',
        'fact_data',
        'calculated_metrics',
        'fact_timestamp',
        'idempotency_key',
    ];

    protected $casts = [
        'fact_data' => 'array',
        'calculated_metrics' => 'array',
        'fact_timestamp' => 'datetime',
    ];

    // Fact type constants
    public const FACT_TYPE_BOOKING_CREATED = 'booking_created';

    public const FACT_TYPE_PAYMENT_CONFIRMED = 'payment_confirmed';

    public const FACT_TYPE_TICKET_ISSUED = 'ticket_issued';

    public const FACT_TYPE_CHECK_IN = 'check_in';

    public const FACT_TYPE_CANCELLATION = 'cancellation';

    public const FACT_TYPE_REFUND = 'refund';

    public const FACT_TYPE_TRANSFER = 'transfer';

    /**
     * Relationship to the booking
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Relationship to the event
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get revenue amount from fact data
     */
    public function getRevenueAmount(): ?float
    {
        return $this->fact_data['revenue_amount'] ?? null;
    }

    /**
     * Get ticket quantity from fact data
     */
    public function getTicketQuantity(): ?int
    {
        return $this->fact_data['ticket_quantity'] ?? null;
    }

    /**
     * Get customer ID from fact data
     */
    public function getCustomerId(): ?int
    {
        return $this->fact_data['customer_id'] ?? null;
    }

    /**
     * Check if this is a revenue-generating fact
     */
    public function isRevenueGenerating(): bool
    {
        return in_array($this->fact_type, [
            self::FACT_TYPE_PAYMENT_CONFIRMED,
            self::FACT_TYPE_TICKET_ISSUED,
        ]);
    }

    /**
     * Check if this is a capacity-affecting fact
     */
    public function isCapacityAffecting(): bool
    {
        return in_array($this->fact_type, [
            self::FACT_TYPE_BOOKING_CREATED,
            self::FACT_TYPE_CANCELLATION,
            self::FACT_TYPE_REFUND,
        ]);
    }

    /**
     * Get calculated metric by name
     */
    public function getCalculatedMetric(string $metricName): mixed
    {
        return $this->calculated_metrics[$metricName] ?? null;
    }

    /**
     * Scope for specific fact types
     */
    public function scopeOfType($query, string $factType)
    {
        return $query->where('fact_type', $factType);
    }

    /**
     * Scope for specific event
     */
    public function scopeForEvent($query, int $eventId)
    {
        return $query->where('event_id', $eventId);
    }

    /**
     * Scope for date range
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('fact_timestamp', [$startDate, $endDate]);
    }

    /**
     * Scope for revenue-generating facts
     */
    public function scopeRevenueGenerating($query)
    {
        return $query->whereIn('fact_type', [
            self::FACT_TYPE_PAYMENT_CONFIRMED,
            self::FACT_TYPE_TICKET_ISSUED,
        ]);
    }

    /**
     * Prevent updates to maintain immutability
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new \Exception('AnalyticsFacts records are immutable and cannot be updated');
    }

    /**
     * Prevent deletion to maintain audit trail
     */
    public function delete()
    {
        throw new \Exception('AnalyticsFacts records cannot be deleted to maintain audit trail');
    }
}
