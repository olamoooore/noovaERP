<?php

namespace Modules\Sales\Subscriptions;

use App\Platform\EventBus\Contracts\EventSubscriptionContract;
use Modules\Sales\Events\SalesEventCatalogue;
use Modules\Sales\Listeners\SalesEventAuditConsumer;

/**
 * SalesEventSubscription — registers the Sales domain's event consumer on the
 * platform Domain Event Bus (issue #901, PC7). The SalesServiceProvider wires
 * it when tenancy initializes; it subscribes the audit consumer to every
 * event in the versioned catalogue.
 */
class SalesEventSubscription implements EventSubscriptionContract
{
    public function getModuleKey(): string
    {
        return 'sales';
    }

    public function getEventNames(): array
    {
        return SalesEventCatalogue::eventNames();
    }

    public function getHandlerClass(): string
    {
        return SalesEventAuditConsumer::class;
    }

    public function getPriority(): int
    {
        return 200;
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
