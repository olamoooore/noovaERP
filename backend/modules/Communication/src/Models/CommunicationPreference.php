<?php

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CommunicationPreference — User notification preferences.
 *
 * Controls how and when each user/customer wants to be notified.
 * Equivalent to Odoo's res.users notification preferences.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property int|null $user_id
 * @property int|null $customer_id
 * @property bool $email_enabled
 * @property bool $sms_enabled
 * @property bool $push_enabled
 * @property bool $in_app_enabled
 * @property bool $whatsapp_enabled
 * @property string $delivery_mode
 * @property string|null $digest_time
 * @property string $timezone
 * @property bool $quiet_hours_enabled
 * @property string|null $quiet_start
 * @property string|null $quiet_end
 * @property array|null $subtype_overrides
 * @property array|null $muted_subtypes
 */
class CommunicationPreference extends Model
{
    protected $table = 'communication_preferences';

    const MODE_INSTANT = 'instant';
    const MODE_DIGEST_DAILY = 'digest_daily';
    const MODE_DIGEST_WEEKLY = 'digest_weekly';
    const MODE_MINIMAL = 'minimal';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'customer_id',
        'email_enabled',
        'sms_enabled',
        'push_enabled',
        'in_app_enabled',
        'whatsapp_enabled',
        'delivery_mode',
        'digest_time',
        'timezone',
        'quiet_hours_enabled',
        'quiet_start',
        'quiet_end',
        'subtype_overrides',
        'muted_subtypes',
    ];

    protected $casts = [
        'email_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
        'push_enabled' => 'boolean',
        'in_app_enabled' => 'boolean',
        'whatsapp_enabled' => 'boolean',
        'quiet_hours_enabled' => 'boolean',
        'subtype_overrides' => 'array',
        'muted_subtypes' => 'array',
    ];

    // ── Scopes ─────────────────────────────────────────────

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    // ── Helpers ─────────────────────────────────────────

    /**
     * Check if a given channel is enabled.
     */
    public function isChannelEnabled(string $channel): bool
    {
        return match ($channel) {
            'email' => $this->email_enabled,
            'sms' => $this->sms_enabled,
            'push' => $this->push_enabled,
            'in_app' => $this->in_app_enabled,
            'whatsapp' => $this->whatsapp_enabled,
            default => false,
        };
    }

    /**
     * Check if a subtype is muted.
     */
    public function isSubtypeMuted(string $subtype): bool
    {
        return in_array($subtype, $this->muted_subtypes ?? []);
    }

    /**
     * Get channel override for a specific subtype.
     *
     * e.g. "Always email me for invoice.sent even if I'm on digest"
     */
    public function getSubtypeOverride(string $subtype): ?array
    {
        $overrides = $this->subtype_overrides ?? [];
        return $overrides[$subtype] ?? null;
    }

    /**
     * Check if currently in quiet hours.
     */
    public function isInQuietHours(): bool
    {
        if (!$this->quiet_hours_enabled || !$this->quiet_start || !$this->quiet_end) {
            return false;
        }

        $now = now()->timezone($this->timezone)->format('H:i');
        $start = $this->quiet_start;
        $end = $this->quiet_end;

        // Handle overnight quiet hours (e.g., 22:00 - 07:00)
        if ($start > $end) {
            return $now >= $start || $now <= $end;
        }

        return $now >= $start && $now <= $end;
    }

    /**
     * Get or create preferences for a user.
     */
    public static function getOrCreateForUser(int $userId, ?string $tenantId = null): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId, 'tenant_id' => $tenantId],
            [
                'email_enabled' => true,
                'in_app_enabled' => true,
                'push_enabled' => true,
                'delivery_mode' => self::MODE_INSTANT,
            ]
        );
    }

    /**
     * Get or create preferences for a customer.
     */
    public static function getOrCreateForCustomer(int $customerId, ?string $tenantId = null): self
    {
        return self::firstOrCreate(
            ['customer_id' => $customerId, 'tenant_id' => $tenantId],
            [
                'email_enabled' => true,
                'in_app_enabled' => true,
                'delivery_mode' => self::MODE_INSTANT,
            ]
        );
    }
}
