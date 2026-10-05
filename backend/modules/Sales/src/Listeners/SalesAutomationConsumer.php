<?php

namespace Modules\Sales\Listeners;

use App\Platform\EventBus\Contracts\DomainEventContract;
use App\Platform\EventBus\Contracts\EventHandlerContract;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Models\AutomationRule;
use Modules\Sales\Services\AutomationRuleEngine;

/**
 * SalesAutomationConsumer — #913 subscriber that runs the tenant's no-code
 * automation rules for every event on the #901 sales catalogue.
 *
 * The bus is fed only after the originating sales transaction committed (the
 * #901 outbox publisher runs on DB::afterCommit), so rules never fire for a
 * rolled-back document. Idempotency lives in the engine: one
 * automation_executions row per (rule, event_id) means at-least-once
 * redelivery cannot run a rule's actions twice.
 *
 * The consumer is schema-guarded: a tenant whose schema predates this
 * migration keeps working (the event is still audited by #901's consumer).
 */
class SalesAutomationConsumer implements EventHandlerContract
{
    public function __construct(private readonly AutomationRuleEngine $engine) {}

    public function handle(DomainEventContract $event): void
    {
        if (! Schema::hasTable('automation_rules')) {
            return;
        }

        $payload = $event->getPayload();
        $legalEntityId = $payload['legal_entity_id'] ?? null;

        // Rules are per legal entity. An event without an entity cannot match
        // any rule (a rule always carries an entity).
        if ($legalEntityId === null || ! is_numeric($legalEntityId)) {
            return;
        }

        $rules = AutomationRule::query()
            ->active()
            ->forEvent($event->getEventName())
            ->forEntity((int) $legalEntityId)
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $this->engine->process($rule, $event);
        }
    }
}
