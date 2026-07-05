<?php

namespace Modules\Contact\src\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base event for all Contact Master events.
 * Each event carries: tenant_id, contact_id, user_id, old_value, new_value.
 */
abstract class ContactEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $contactId,
        public readonly ?string $userId = null,
        public readonly ?array $oldValue = null,
        public readonly ?array $newValue = null,
    ) {}
}
