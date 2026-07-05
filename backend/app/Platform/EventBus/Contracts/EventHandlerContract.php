<?php

namespace App\Platform\EventBus\Contracts;

/**
 * EventHandlerContract — the actual handler that processes an event.
 *
 * Implement this in each module to react to cross-module events.
 */
interface EventHandlerContract
{
    /**
     * Handle the incoming domain event.
     *
     * @param DomainEventContract $event
     * @return void
     */
    public function handle(DomainEventContract $event): void;
}
