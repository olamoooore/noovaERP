<?php

namespace App\Platform\Notifications\Contracts;

use App\Models\User;

interface NotificationServiceContract
{
    /**
     * Dispatch a notification to a user via mail.
     */
    public function dispatch(string $event, User $user, array $data = []): void;

    /**
     * Set a user's notification preference for an event.
     */
    public function setPreference(int $userId, string $event, string $channel, bool $enabled): void;

    /**
     * Get a user's notification preferences.
     */
    public function getPreferences(int $userId): array;

    /**
     * Get notification logs for a user.
     */
    public function getLogs(int $userId, ?string $event = null, int $limit = 50): array;

    /**
     * Mark a notification as read.
     */
    public function markRead(string $logId): bool;
}
