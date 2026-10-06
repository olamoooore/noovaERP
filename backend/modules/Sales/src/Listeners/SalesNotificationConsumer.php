<?php

declare(strict_types=1);

namespace Modules\Sales\Listeners;

use App\Platform\EventBus\Contracts\DomainEventContract;
use App\Platform\EventBus\Contracts\EventHandlerContract;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Notifications\SalesNotificationCatalogue;
use Modules\Sales\Notifications\SalesNotificationDispatcher;

/**
 * SalesNotificationConsumer — the #903 consumer on the platform Domain Event
 * Bus. Subscribed to the catalogue events that map to a user-facing
 * notification (SalesNotificationCatalogue::DOMAIN_EVENT_MAP); each event fans
 * out through SalesNotificationDispatcher, which resolves recipients, applies
 * per-user preference defaults and writes in-app + mail notifications through
 * the platform NotificationService.
 *
 * Idempotency is handled by the dispatcher (event id / document), so a
 * redelivered event is not notified twice. Failures propagate so the dispatcher
 * dead-letters the event instead of dropping a notification silently.
 */
class SalesNotificationConsumer implements EventHandlerContract
{
    public function handle(DomainEventContract $event): void
    {
        if (! Schema::hasTable('notification_logs')) {
            return;
        }

        app(SalesNotificationDispatcher::class)->dispatchDomainEvent(
            $event->getEventName(),
            $event->getPayload(),
        );
    }
}
