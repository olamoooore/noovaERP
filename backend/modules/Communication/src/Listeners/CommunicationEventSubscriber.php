<?php

namespace Modules\Communication\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Events\BusinessEvent;
use Modules\Communication\Services\CommunicationEngine;

/**
 * CommunicationEventSubscriber — Central listener for all business events.
 *
 * This subscriber listens to BusinessEvent dispatches and routes them
 * through the CommunicationEngine. It acts as the bridge between
 * domain events and the communication infrastructure.
 *
 * All modules should fire BusinessEvent instead of sending mail directly.
 */
class CommunicationEventSubscriber implements ShouldQueue
{
    /**
     * The queue this listener should run on.
     */
    public string $queue = 'communication';

    public function __construct(
        protected CommunicationEngine $engine,
    ) {}

    /**
     * Handle BusinessEvent dispatches.
     */
    public function handleBusinessEvent(BusinessEvent $event): void
    {
        try {
            // If direct recipients are specified, use sendDirect
            if (
                ! empty($event->directRecipientUserIds) ||
                ! empty($event->directRecipientCustomerIds) ||
                ! empty($event->directRecipientEmails)
            ) {
                $this->engine->sendDirect(
                    subject: $event->subject,
                    body: $event->body,
                    recipientUserIds: $event->directRecipientUserIds,
                    recipientCustomerIds: $event->directRecipientCustomerIds,
                    recipientEmails: $event->directRecipientEmails,
                    channels: $event->channels ?: ['email', 'in_app'],
                    subtype: $event->event,
                    model: $event->model,
                    recordId: $event->recordId,
                    authorId: $event->authorId,
                    tenantId: $event->tenantId,
                    templateKey: $event->templateKey,
                    templateVariables: $event->context,
                    metadata: $event->metadata,
                );
            } else {
                // Default: notify all subscribers of the record
                $this->engine->notify(
                    model: $event->model,
                    recordId: $event->recordId,
                    subtype: $event->event,
                    subject: $event->subject,
                    body: $event->body,
                    authorId: $event->authorId,
                    authorType: $event->authorType,
                    templateKey: $event->templateKey,
                    templateVariables: $event->context,
                    tenantId: $event->tenantId,
                    metadata: $event->metadata,
                    scheduledAt: $event->scheduledAt,
                );
            }

            Log::info('Communication: BusinessEvent handled', [
                'event' => $event->event,
                'model' => $event->model,
                'record_id' => $event->recordId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Communication: BusinessEvent failed', [
                'event' => $event->event,
                'model' => $event->model,
                'record_id' => $event->recordId,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Re-throw so the queue can retry
        }
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            BusinessEvent::class => 'handleBusinessEvent',
        ];
    }
}
