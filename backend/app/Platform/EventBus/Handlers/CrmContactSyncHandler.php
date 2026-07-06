<?php

namespace App\Platform\EventBus\Handlers;

use App\Platform\EventBus\Contracts\DomainEventContract;
use App\Platform\EventBus\Contracts\EventHandlerContract;
use App\Platform\SystemLog\Contracts\SystemLogServiceContract;
use Illuminate\Support\Facades\Log;

class CrmContactSyncHandler implements EventHandlerContract
{
    public function __construct(private SystemLogServiceContract $systemLog) {}

    public function handle(DomainEventContract $event): void
    {
        $payload = $event->getPayload();
        $contactId = $payload['contact_id'] ?? null;

        $this->systemLog->log(
            'info',
            'crm.contact_sync',
            "Contact event {$event->getEventName()} received by CRM",
            [
                'contact_id' => $contactId,
                'event_name' => $event->getEventName(),
                'source_module' => $event->getSourceModule(),
                'correlation_id' => $event->getCorrelationId(),
            ],
            $event->getCorrelationId()
        );

        Log::info("CRM: Contact sync event received", [
            'event' => $event->getEventName(),
            'contact_id' => $contactId,
        ]);
    }
}
