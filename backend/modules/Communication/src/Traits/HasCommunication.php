<?php

namespace Modules\Communication\Traits;

use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Models\CommunicationSubscriber;
use Modules\Communication\Services\CommunicationEngine;

/**
 * HasCommunication — Trait to add communication/chatter capabilities to any Eloquent model.
 *
 * Add this trait to any model that needs:
 *   - Subscribers (followers)
 *   - Message thread (chatter)
 *   - Notification sending
 *   - Auto-subscribe on creation
 *
 * Usage:
 *   class Invoice extends Model {
 *       use HasCommunication;
 *
 *       protected string $communicationModel = 'invoice';
 *   }
 *
 *   // Subscribe a user to an invoice
 *   $invoice->addSubscriber(userId: $user->id);
 *
 *   // Notify all subscribers
 *   $invoice->notifySubscribers('invoice.sent', 'Invoice Sent', '<p>Your invoice is attached.</p>');
 *
 *   // Get message thread (chatter)
 *   $messages = $invoice->communicationMessages;
 */
trait HasCommunication
{
    /**
     * Boot the trait: auto-subscribe the creator on model creation.
     */
    public static function bootHasCommunication(): void
    {
        static::created(function ($model) {
            if (!config('communication.auto_subscribe.creator', true)) {
                return;
            }

            // Auto-subscribe the creator if available
            $creatorId = $model->created_by ?? $model->user_id ?? $model->author_id ?? null;

            if ($creatorId) {
                $engine = app(CommunicationEngine::class);
                $engine->subscribe(
                    model: $model->getCommunicationModelName(),
                    recordId: $model->getKey(),
                    userId: $creatorId,
                    tenantId: $model->tenant_id ?? null,
                );
            }
        });
    }

    /**
     * Get the communication model name identifier.
     */
    public function getCommunicationModelName(): string
    {
        return $this->communicationModel ?? strtolower(class_basename($this));
    }

    /**
     * Get all communication messages for this record.
     */
    public function communicationMessages()
    {
        return CommunicationMessage::forRecord(
            $this->getCommunicationModelName(),
            $this->getKey()
        )->orderByDesc('created_at');
    }

    /**
     * Get all subscribers for this record.
     */
    public function communicationSubscribers()
    {
        return CommunicationSubscriber::forRecord(
            $this->getCommunicationModelName(),
            $this->getKey()
        )->active();
    }

    /**
     * Add a subscriber to this record.
     */
    public function addSubscriber(
        ?int $userId = null,
        ?int $customerId = null,
        ?string $email = null,
        ?array $channels = null,
        ?array $subtypes = null,
    ): CommunicationSubscriber {
        return app(CommunicationEngine::class)->subscribe(
            model: $this->getCommunicationModelName(),
            recordId: $this->getKey(),
            userId: $userId,
            customerId: $customerId,
            email: $email,
            channels: $channels,
            subtypes: $subtypes,
            tenantId: $this->tenant_id ?? null,
        );
    }

    /**
     * Remove a subscriber from this record.
     */
    public function removeSubscriber(?int $userId = null, ?int $customerId = null): void
    {
        app(CommunicationEngine::class)->unsubscribe(
            model: $this->getCommunicationModelName(),
            recordId: $this->getKey(),
            userId: $userId,
            customerId: $customerId,
            tenantId: $this->tenant_id ?? null,
        );
    }

    /**
     * Notify all subscribers of this record.
     */
    public function notifySubscribers(
        string $subtype,
        string $subject,
        string $body,
        ?int $authorId = null,
        ?string $templateKey = null,
        array $templateVariables = [],
        array $metadata = [],
    ): CommunicationMessage {
        return app(CommunicationEngine::class)->notify(
            model: $this->getCommunicationModelName(),
            recordId: $this->getKey(),
            subtype: $subtype,
            subject: $subject,
            body: $body,
            authorId: $authorId,
            tenantId: $this->tenant_id ?? null,
            templateKey: $templateKey,
            templateVariables: $templateVariables,
            metadata: $metadata,
        );
    }

    /**
     * Post a comment on this record (chatter-style).
     */
    public function postComment(string $body, int $authorId, bool $isInternalNote = false, ?int $parentId = null): CommunicationMessage
    {
        return app(CommunicationEngine::class)->postComment(
            model: $this->getCommunicationModelName(),
            recordId: $this->getKey(),
            body: $body,
            authorId: $authorId,
            isInternalNote: $isInternalNote,
            tenantId: $this->tenant_id ?? null,
            parentId: $parentId,
        );
    }

    /**
     * Log a system message on this record.
     */
    public function logCommunication(string $body, string $subtype = 'system.log', array $metadata = []): CommunicationMessage
    {
        return app(CommunicationEngine::class)->logSystem(
            model: $this->getCommunicationModelName(),
            recordId: $this->getKey(),
            body: $body,
            subtype: $subtype,
            tenantId: $this->tenant_id ?? null,
            metadata: $metadata,
        );
    }

    /**
     * Get the message thread (chatter) for this record.
     */
    public function getThread(int $limit = 50, ?string $messageType = null): \Illuminate\Support\Collection
    {
        return app(CommunicationEngine::class)->getThread(
            model: $this->getCommunicationModelName(),
            recordId: $this->getKey(),
            tenantId: $this->tenant_id ?? null,
            limit: $limit,
            messageType: $messageType,
        );
    }
}
