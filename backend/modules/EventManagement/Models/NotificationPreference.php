<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\EventManagement\Traits\HasTenantScope;

/**
 * NotificationPreference Model
 *
 * Manages customer notification preferences for different types of communications.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property string $notification_type
 * @property bool $email_enabled
 * @property bool $sms_enabled
 * @property bool $push_enabled
 * @property bool $in_app_enabled
 * @property string $frequency
 * @property \Carbon\Carbon|null $preferred_time
 * @property string $timezone
 * @property array|null $custom_settings
 */
class NotificationPreference extends Model
{
    use HasTenantScope;

    protected $table = 'notification_preferences';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'notification_type',
        'email_enabled',
        'sms_enabled',
        'push_enabled',
        'in_app_enabled',
        'frequency',
        'preferred_time',
        'timezone',
        'custom_settings',
    ];

    protected $casts = [
        'email_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
        'push_enabled' => 'boolean',
        'in_app_enabled' => 'boolean',
        'preferred_time' => 'datetime:H:i',
        'custom_settings' => 'array',
    ];

    /**
     * Scope for specific customer
     */
    public function scopeForCustomer($query, string $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope for specific notification type
     */
    public function scopeForType($query, string $type)
    {
        return $query->where('notification_type', $type);
    }

    /**
     * Scope for enabled channels
     */
    public function scopeEnabledForChannel($query, string $channel)
    {
        return $query->where($channel.'_enabled', true);
    }

    /**
     * Check if a channel is enabled for this preference
     */
    public function isChannelEnabled(string $channel): bool
    {
        $field = $channel.'_enabled';

        return $this->$field ?? false;
    }

    /**
     * Get enabled channels for this preference
     */
    public function getEnabledChannels(): array
    {
        $channels = [];

        if ($this->email_enabled) {
            $channels[] = 'email';
        }
        if ($this->sms_enabled) {
            $channels[] = 'sms';
        }
        if ($this->push_enabled) {
            $channels[] = 'push';
        }
        if ($this->in_app_enabled) {
            $channels[] = 'in_app';
        }

        return $channels;
    }

    /**
     * Get customer preferences for all notification types
     */
    public static function getCustomerPreferences(string $tenantId, string $customerId): array
    {
        $preferences = self::forTenant($tenantId)
            ->forCustomer($customerId)
            ->get()
            ->keyBy('notification_type');

        $defaultPreferences = self::getDefaultPreferences();
        $result = [];

        foreach ($defaultPreferences as $type => $defaults) {
            if ($preferences->has($type)) {
                $result[$type] = $preferences[$type];
            } else {
                // Create default preference if it doesn't exist
                $result[$type] = self::create(array_merge($defaults, [
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId,
                    'notification_type' => $type,
                ]));
            }
        }

        return $result;
    }

    /**
     * Update customer preference for a specific type
     */
    public static function updateCustomerPreference(
        string $tenantId,
        string $customerId,
        string $notificationType,
        array $settings
    ): self {
        return self::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'notification_type' => $notificationType,
            ],
            $settings
        );
    }

    /**
     * Check if customer should receive notification
     */
    public static function shouldReceiveNotification(
        string $tenantId,
        string $customerId,
        string $notificationType,
        string $channel
    ): bool {
        $preference = self::forTenant($tenantId)
            ->forCustomer($customerId)
            ->forType($notificationType)
            ->first();

        if (! $preference) {
            // Use default settings if no preference exists
            $defaults = self::getDefaultPreferences()[$notificationType] ?? [];

            return $defaults[$channel.'_enabled'] ?? false;
        }

        return $preference->isChannelEnabled($channel) && $preference->frequency !== 'disabled';
    }

    /**
     * Get notification types
     */
    public static function getNotificationTypes(): array
    {
        return [
            'event_announcements' => 'Event Announcements',
            'booking_confirmations' => 'Booking Confirmations',
            'event_reminders' => 'Event Reminders',
            'check_in_reminders' => 'Check-in Reminders',
            'event_updates' => 'Event Updates',
            'cancellation_notices' => 'Cancellation Notices',
            'feedback_requests' => 'Feedback Requests',
            'promotional_offers' => 'Promotional Offers',
            'loyalty_updates' => 'Loyalty Program Updates',
            'artist_announcements' => 'Artist Announcements',
            'early_access_notifications' => 'Early Access Notifications',
        ];
    }

    /**
     * Get frequency options
     */
    public static function getFrequencyOptions(): array
    {
        return [
            'immediate' => 'Immediate',
            'daily_digest' => 'Daily Digest',
            'weekly_digest' => 'Weekly Digest',
            'disabled' => 'Disabled',
        ];
    }

    /**
     * Get default preferences for new customers
     */
    public static function getDefaultPreferences(): array
    {
        return [
            'event_announcements' => [
                'email_enabled' => true,
                'sms_enabled' => false,
                'push_enabled' => true,
                'in_app_enabled' => true,
                'frequency' => 'immediate',
                'timezone' => 'UTC',
            ],
            'booking_confirmations' => [
                'email_enabled' => true,
                'sms_enabled' => true,
                'push_enabled' => true,
                'in_app_enabled' => true,
                'frequency' => 'immediate',
                'timezone' => 'UTC',
            ],
            'event_reminders' => [
                'email_enabled' => true,
                'sms_enabled' => false,
                'push_enabled' => true,
                'in_app_enabled' => true,
                'frequency' => 'immediate',
                'timezone' => 'UTC',
            ],
            'check_in_reminders' => [
                'email_enabled' => false,
                'sms_enabled' => true,
                'push_enabled' => true,
                'in_app_enabled' => true,
                'frequency' => 'immediate',
                'timezone' => 'UTC',
            ],
            'event_updates' => [
                'email_enabled' => true,
                'sms_enabled' => false,
                'push_enabled' => true,
                'in_app_enabled' => true,
                'frequency' => 'immediate',
                'timezone' => 'UTC',
            ],
            'cancellation_notices' => [
                'email_enabled' => true,
                'sms_enabled' => true,
                'push_enabled' => true,
                'in_app_enabled' => true,
                'frequency' => 'immediate',
                'timezone' => 'UTC',
            ],
            'feedback_requests' => [
                'email_enabled' => true,
                'sms_enabled' => false,
                'push_enabled' => false,
                'in_app_enabled' => true,
                'frequency' => 'immediate',
                'timezone' => 'UTC',
            ],
            'promotional_offers' => [
                'email_enabled' => false,
                'sms_enabled' => false,
                'push_enabled' => false,
                'in_app_enabled' => true,
                'frequency' => 'weekly_digest',
                'timezone' => 'UTC',
            ],
            'loyalty_updates' => [
                'email_enabled' => true,
                'sms_enabled' => false,
                'push_enabled' => false,
                'in_app_enabled' => true,
                'frequency' => 'immediate',
                'timezone' => 'UTC',
            ],
            'artist_announcements' => [
                'email_enabled' => true,
                'sms_enabled' => false,
                'push_enabled' => true,
                'in_app_enabled' => true,
                'frequency' => 'immediate',
                'timezone' => 'UTC',
            ],
            'early_access_notifications' => [
                'email_enabled' => true,
                'sms_enabled' => true,
                'push_enabled' => true,
                'in_app_enabled' => true,
                'frequency' => 'immediate',
                'timezone' => 'UTC',
            ],
        ];
    }
}
