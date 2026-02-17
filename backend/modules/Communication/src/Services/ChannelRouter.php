<?php

namespace Modules\Communication\Services;

use Modules\Communication\Services\Channels\ChannelInterface;
use Modules\Communication\Services\Channels\EmailChannel;
use Modules\Communication\Services\Channels\InAppChannel;
use Modules\Communication\Services\Channels\SmsChannel;
use Modules\Communication\Services\Channels\WhatsAppChannel;
use Modules\Communication\Services\Channels\PushChannel;
use Modules\Communication\Models\CommunicationOutbox;
use Modules\Communication\Models\CommunicationPreference;
use Modules\Communication\Models\CommunicationSubscriber;

/**
 * ChannelRouter — Decides which channels to use for each subscriber.
 *
 * Respects:
 * - Channel configuration (enabled/disabled)
 * - User preferences (per-channel, per-subtype overrides, mutes)
 * - Subscriber-specific channel selections
 * - Quiet hours
 * - Digest mode
 */
class ChannelRouter
{
    protected array $channelDrivers = [];

    public function __construct(
        protected EmailChannel $email,
        protected InAppChannel $inApp,
        protected SmsChannel $sms,
        protected WhatsAppChannel $whatsApp,
        protected PushChannel $push,
    ) {
        $this->channelDrivers = [
            'email' => $this->email,
            'in_app' => $this->inApp,
            'sms' => $this->sms,
            'whatsapp' => $this->whatsApp,
            'push' => $this->push,
        ];
    }

    /**
     * Resolve which channels a subscriber should receive a message on.
     *
     * @param CommunicationSubscriber $subscriber
     * @param string|null $subtype
     * @return string[] Active channel names
     */
    public function resolveChannels(CommunicationSubscriber $subscriber, ?string $subtype = null): array
    {
        // 1. Start with subscriber's preferred channels
        $channels = $subscriber->getChannels();

        // 2. Filter by subtype subscription
        if ($subtype && !$subscriber->shouldReceiveSubtype($subtype)) {
            return [];
        }

        // 3. Get user/customer preferences
        $preferences = $this->getPreferences($subscriber);

        if ($preferences) {
            // Check if subtype is muted
            if ($subtype && $preferences->isSubtypeMuted($subtype)) {
                return [];
            }

            // Check for subtype-specific overrides
            if ($subtype) {
                $override = $preferences->getSubtypeOverride($subtype);
                if ($override && isset($override['channels'])) {
                    $channels = $override['channels'];
                }
            }

            // Filter by enabled channels in preferences
            $channels = array_filter($channels, fn($ch) => $preferences->isChannelEnabled($ch));

            // Handle quiet hours: remove non-exempt channels
            if ($preferences->isInQuietHours()) {
                $exemptChannels = config('communication.quiet_hours.except_channels', ['in_app']);
                $channels = array_filter($channels, fn($ch) => in_array($ch, $exemptChannels));
            }
        }

        // 4. Filter by globally enabled channels
        $channels = array_filter($channels, fn($ch) => $this->isChannelEnabled($ch));

        return array_values($channels);
    }

    /**
     * Get the driver for a given channel.
     */
    public function getDriver(string $channel): ?ChannelInterface
    {
        return $this->channelDrivers[$channel] ?? null;
    }

    /**
     * Get all available (enabled) channel drivers.
     */
    public function getEnabledDrivers(): array
    {
        return array_filter($this->channelDrivers, fn(ChannelInterface $driver) => $driver->isEnabled());
    }

    /**
     * Check if a channel is globally enabled.
     */
    public function isChannelEnabled(string $channel): bool
    {
        $driver = $this->getDriver($channel);
        return $driver ? $driver->isEnabled() : false;
    }

    /**
     * Get preferences for a subscriber.
     */
    protected function getPreferences(CommunicationSubscriber $subscriber): ?CommunicationPreference
    {
        if ($subscriber->user_id) {
            return CommunicationPreference::forUser($subscriber->user_id)->first();
        }

        if ($subscriber->customer_id) {
            return CommunicationPreference::forCustomer($subscriber->customer_id)->first();
        }

        return null;
    }

    /**
     * Check if a subscriber should receive digest instead of instant.
     */
    public function shouldDigest(CommunicationSubscriber $subscriber): bool
    {
        $preferences = $this->getPreferences($subscriber);

        if (!$preferences) {
            return false;
        }

        return in_array($preferences->delivery_mode, [
            CommunicationPreference::MODE_DIGEST_DAILY,
            CommunicationPreference::MODE_DIGEST_WEEKLY,
        ]);
    }
}
