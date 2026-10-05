<?php

declare(strict_types=1);

namespace Modules\Sales\Subscriptions;

use App\Platform\EventBus\Contracts\EventSubscriptionContract;
use Modules\Sales\Listeners\SalesNotificationConsumer;
use Modules\Sales\Notifications\SalesNotificationCatalogue;

/**
 * SalesNotificationSubscription — registers the #903 notification consumer on
 * the platform Domain Event Bus, next to the #901 audit subscription. Only the
 * sales events that have a user-facing notification are subscribed.
 */
class SalesNotificationSubscription implements EventSubscriptionContract
{
    public function getModuleKey(): string
    {
        return 'sales-notifications';
    }

    public function getEventNames(): array
    {
        return SalesNotificationCatalogue::domainEvents();
    }

    public function getHandlerClass(): string
    {
        return SalesNotificationConsumer::class;
    }

    public function getPriority(): int
    {
        return 150;
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
