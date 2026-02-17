<?php

namespace Modules\Communication\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * BusinessEvent — A generic event emitted by any module that should
 * trigger communication through the central engine.
 *
 * Instead of calling CommunicationEngine directly, modules can fire
 * this event and let the event subscriber handle routing.
 *
 * Usage:
 *   event(new BusinessEvent(
 *       model: 'invoice',
 *       recordId: $invoice->id,
 *       event: 'invoice.sent',
 *       subject: 'Invoice #' . $invoice->number,
 *       body: '<p>Your invoice has been sent.</p>',
 *       authorId: auth()->id(),
 *       tenantId: tenant('id'),
 *       context: ['invoice_number' => $invoice->number, 'amount' => $invoice->total],
 *   ));
 */
class BusinessEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $model,
        public int $recordId,
        public string $event,
        public string $subject = '',
        public string $body = '',
        public ?int $authorId = null,
        public string $authorType = 'system',
        public ?string $tenantId = null,
        public ?string $templateKey = null,
        public array $context = [],
        public array $metadata = [],
        public array $channels = [],
        public array $directRecipientUserIds = [],
        public array $directRecipientCustomerIds = [],
        public array $directRecipientEmails = [],
        public ?\Carbon\Carbon $scheduledAt = null,
    ) {}
}
