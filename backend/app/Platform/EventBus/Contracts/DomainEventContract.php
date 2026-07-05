<?php

namespace App\Platform\EventBus\Contracts;

/**
 * DomainEventContract — every cross-module event must implement this.
 *
 * Ensures consistent event shape, versioning, and metadata for the
 * platform-wide Domain Event Bus (ADR 000 Pillar 10).
 */
interface DomainEventContract
{
    /**
     * Unique event name (e.g., 'contact.created', 'invoice.approved').
     */
    public function getEventName(): string;

    /**
     * Schema version of this event (e.g., '1.0.0').
     * Allows consumers to handle version upgrades gracefully.
     */
    public function getVersion(): string;

    /**
     * Tenant ID that produced this event.
     */
    public function getTenantId(): string;

    /**
     * User ID that triggered this event (null for system events).
     */
    public function getUserId(): ?string;

    /**
     * Correlation ID for tracing across services.
     */
    public function getCorrelationId(): string;

    /**
     * The event payload as an array.
     */
    public function getPayload(): array;

    /**
     * ISO-8601 timestamp of when the event occurred.
     */
    public function getOccurredAt(): string;

    /**
     * The source module key (e.g., 'contact', 'accounting', 'pos').
     */
    public function getSourceModule(): string;

    /**
     * Whether this event is idempotent (safe to retry).
     */
    public function isIdempotent(): bool;
}
