<?php

namespace Modules\Communication\Services\Channels;

use Modules\Communication\Models\CommunicationOutbox;
use Modules\Communication\Models\CommunicationMessage;

/**
 * ChannelInterface — Contract for all delivery channel adapters.
 *
 * Each channel (email, SMS, push, etc.) implements this interface.
 * The CommunicationEngine routes outbox items to the appropriate channel.
 */
interface ChannelInterface
{
    /**
     * Get the channel identifier.
     */
    public function getChannel(): string;

    /**
     * Check if this channel is currently enabled and configured.
     */
    public function isEnabled(): bool;

    /**
     * Send a single outbox item through this channel.
     *
     * @param CommunicationOutbox $outbox The queued delivery item
     * @param CommunicationMessage $message The parent message
     * @return array{success: bool, external_id?: string, error?: string}
     */
    public function send(CommunicationOutbox $outbox, CommunicationMessage $message): array;

    /**
     * Send a batch of outbox items (for channels that support batching).
     *
     * @param \Illuminate\Support\Collection $outboxItems
     * @return array<int, array{success: bool, external_id?: string, error?: string}>
     */
    public function sendBatch(\Illuminate\Support\Collection $outboxItems): array;
}
