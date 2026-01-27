<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Validation\Rule;

class EventSettings extends Model
{
    use HasFactory;

    protected $table = 'event_settings';

    protected $fillable = [
        'tenant_id',
        'currency',
        'timezone',
        'cancellation_policy',
        'refund_policy',
        'max_capacity_per_event',
        'require_attendee_id',
        'data_retention_days',
    ];

    protected $casts = [
        'require_attendee_id' => 'boolean',
        'max_capacity_per_event' => 'integer',
        'data_retention_days' => 'integer',
    ];

    /**
     * Get validation rules for event settings
     */
    public static function getValidationRules(): array
    {
        return [
            'tenant_id' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['required', 'string', 'max:50', 'timezone'],
            'cancellation_policy' => ['nullable', 'string', 'max:5000'],
            'refund_policy' => ['nullable', 'string', 'max:5000'],
            'max_capacity_per_event' => ['required', 'integer', 'min:1', 'max:100000'],
            'require_attendee_id' => ['required', 'boolean'],
            'data_retention_days' => ['required', 'integer', 'min:30', 'max:3650'], // 30 days to 10 years
        ];
    }

    /**
     * Get validation rules for updating settings
     */
    public static function getUpdateValidationRules(): array
    {
        $rules = self::getValidationRules();
        $rules['tenant_id'] = ['sometimes', 'string', 'max:255'];
        return $rules;
    }

    /**
     * Get settings for a specific tenant
     */
    public static function forTenant(string $tenantId): ?self
    {
        return static::where('tenant_id', $tenantId)->first();
    }

    /**
     * Get settings for a tenant or create with defaults
     */
    public static function getOrCreateForTenant(string $tenantId): self
    {
        return static::firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'currency' => config('event_management.defaults.currency', 'USD'),
                'timezone' => config('event_management.defaults.timezone', 'UTC'),
                'max_capacity_per_event' => config('event_management.defaults.max_capacity_per_event', 10000),
                'require_attendee_id' => false,
                'data_retention_days' => config('event_management.defaults.data_retention_days', 2555),
            ]
        );
    }

    /**
     * Check if attendee ID is required for this tenant
     */
    public function requiresAttendeeId(): bool
    {
        return $this->require_attendee_id;
    }

    /**
     * Get the maximum capacity allowed per event
     */
    public function getMaxCapacity(): int
    {
        return $this->max_capacity_per_event;
    }

    /**
     * Get data retention period in days
     */
    public function getDataRetentionDays(): int
    {
        return $this->data_retention_days;
    }

    /**
     * Check if a capacity is within tenant limits
     */
    public function isCapacityAllowed(int $capacity): bool
    {
        return $capacity <= $this->max_capacity_per_event;
    }

    /**
     * Get supported currencies
     */
    public static function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'JPY', 'KRW', 'SGD', 'MYR', 'THB', 'PHP'];
    }

    /**
     * Check if currency is supported
     */
    public function isCurrencySupported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::getSupportedCurrencies());
    }

    /**
     * Get formatted currency symbol
     */
    public function getCurrencySymbol(): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'KRW' => '₩',
            'SGD' => 'S$',
            'MYR' => 'RM',
            'THB' => '฿',
            'PHP' => '₱',
        ];

        return $symbols[$this->currency] ?? $this->currency;
    }

    /**
     * Apply tenant timezone to a date
     */
    public function applyTimezone(\DateTime $date): \DateTime
    {
        $timezone = new \DateTimeZone($this->timezone);
        return $date->setTimezone($timezone);
    }

    /**
     * Check if refund is allowed based on policy
     */
    public function isRefundAllowed(): bool
    {
        return !empty($this->refund_policy);
    }

    /**
     * Check if cancellation is allowed based on policy
     */
    public function isCancellationAllowed(): bool
    {
        return !empty($this->cancellation_policy);
    }
}