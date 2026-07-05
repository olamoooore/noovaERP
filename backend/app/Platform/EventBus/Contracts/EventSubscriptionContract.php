<?php

namespace App\Platform\EventBus\Contracts;

/**
 * EventSubscriptionContract — defines how modules subscribe to events.
 *
 * A subscription declares: which events a module listens to, a handler class,
 * and optional filtering rules (tenant, entity, conditions).
 */
interface EventSubscriptionContract
{
    /**
     * The module key that owns this subscription (e.g., 'accounting', 'crm').
     */
    public function getModuleKey(): string;

    /**
     * Event names this subscription listens to.
     * Use ['*'] to listen to all events.
     *
     * @return string[]
     */
    public function getEventNames(): array;

    /**
     * Fully qualified class name of the handler that processes this event.
     */
    public function getHandlerClass(): string;

    /**
     * Priority (lower = earlier). Default is 100.
     */
    public function getPriority(): int;

    /**
     * Optional filter: only fire if event tenant_id matches.
     * null = no tenant filter (fire for all tenants).
     */
    public function getTenantFilter(): ?string;

    /**
     * Optional filter: only fire if event payload contains matching values.
     * Example: ['contact_type' => 'company'] — only fire for company contacts.
     * null = no payload filter.
     *
     * @return array<string, mixed>|null
     */
    public function getPayloadFilter(): ?array;
}
