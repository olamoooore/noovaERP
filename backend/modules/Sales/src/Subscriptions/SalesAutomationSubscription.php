<?php

namespace Modules\Sales\Subscriptions;

use App\Platform\EventBus\Contracts\EventSubscriptionContract;
use Modules\Sales\Events\SalesEventCatalogue;
use Modules\Sales\Listeners\SalesAutomationConsumer;

/**
 * SalesAutomationSubscription — registers the #913 automation consumer on the
 * platform Domain Event Bus for every event in the #901 sales catalogue.
 *
 * Priority 250 runs it after the #901 audit consumer (200), so the audit log
 * always exists before automation reacts to an event.
 */
class SalesAutomationSubscription implements EventSubscriptionContract
{
    public function getModuleKey(): string
    {
        return 'sales.automation';
    }

    public function getEventNames(): array
    {
        return SalesEventCatalogue::eventNames();
    }

    public function getHandlerClass(): string
    {
        return SalesAutomationConsumer::class;
    }

    public function getPriority(): int
    {
        return 250;
    }

    public function getTenantFilter(): ?string
    {
        return null;
    }

    public function getPayloadFilter(): ?array
    {
        return null;
    }
}
