<?php

namespace Modules\Communication\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * MessageSent — Fired after a message has been successfully delivered.
 *
 * Other modules can listen to this for post-delivery actions
 * (analytics, webhooks, etc.).
 */
class MessageSent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $messageId,
        public string $model,
        public int $recordId,
        public string $channel,
        public ?int $recipientUserId = null,
        public ?int $recipientCustomerId = null,
    ) {}
}
