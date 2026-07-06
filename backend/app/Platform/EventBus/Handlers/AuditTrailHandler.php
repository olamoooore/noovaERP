<?php

namespace App\Platform\EventBus\Handlers;

use App\Platform\EventBus\Contracts\DomainEventContract;
use App\Platform\EventBus\Contracts\EventHandlerContract;
use App\Platform\SystemLog\Contracts\SystemLogServiceContract;

class AuditTrailHandler implements EventHandlerContract
{
    public function __construct(private SystemLogServiceContract $systemLog) {}

    public function handle(DomainEventContract $event): void
    {
        $this->systemLog->log(
            'info',
            'audit.domain_event',
            "Domain event: {$event->getEventName()}",
            [
                'event_name' => $event->getEventName(),
                'version' => $event->getVersion(),
                'source_module' => $event->getSourceModule(),
                'tenant_id' => $event->getTenantId(),
                'user_id' => $event->getUserId(),
                'correlation_id' => $event->getCorrelationId(),
                'payload' => $event->getPayload(),
                'occurred_at' => $event->getOccurredAt(),
            ],
            $event->getCorrelationId()
        );
    }
}
