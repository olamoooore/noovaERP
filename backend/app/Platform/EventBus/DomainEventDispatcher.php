<?php

namespace App\Platform\EventBus;

use App\Platform\EventBus\Contracts\DomainEventContract;
use App\Platform\EventBus\Contracts\EventHandlerContract;
use App\Platform\EventBus\Contracts\EventSubscriptionContract;
use App\Platform\EventBus\Events\EventRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DomainEventDispatcher — the platform-wide Domain Event Bus.
 *
 * Responsibilities:
 * 1. Persist every event to domain_event_records (audit trail)
 * 2. Route events to matching subscriptions (cross-module dispatch)
 * 3. Handle failures with retry + dead-letter queue
 * 4. Support event versioning and filtering
 * 5. Provide event replay for debugging/recovery
 *
 * Usage:
 *   app(DomainEventDispatcher::class)->dispatch(new ContactCreated(...));
 *   app(DomainEventDispatcher::class)->subscribe(new AccountingSubscription());
 *   app(DomainEventDispatcher::class)->replay($tenantId, '2026-07-01', '2026-07-05');
 */
class DomainEventDispatcher
{
    private const MAX_RETRIES = 3;

    private const RETRY_DELAY_SECONDS = 60;

    /**
     * Dispatch a domain event to all matching subscriptions.
     *
     * @return array{dispatched: int, failed: int, errors: string[]}
     */
    public function dispatch(DomainEventContract $event): array
    {
        $record = $this->persistEvent($event);
        $subscriptions = $this->findMatchingSubscriptions($event);

        $dispatched = 0;
        $failed = 0;
        $errors = [];

        foreach ($subscriptions as $subscription) {
            try {
                DB::beginTransaction();
                $handler = $this->resolveHandler($subscription);
                if ($handler) {
                    $handler->handle($event);
                    $dispatched++;
                }
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $failed++;
                $errors[] = "{$subscription->module_key}: {$e->getMessage()}";
                try {
                    $this->handleFailure($record, $subscription, $e);
                } catch (\Throwable $ignore) {
                }
            }
        }

        // Mark as processed if no failures
        if ($failed === 0) {
            $record->update(['processed_at' => now()]);
        }

        Log::info("Domain event dispatched: {$event->getEventName()}", [
            'event_id' => $record->id,
            'correlation_id' => $event->getCorrelationId(),
            'subscriptions_matched' => count($subscriptions),
            'dispatched' => $dispatched,
            'failed' => $failed,
        ]);

        return ['dispatched' => $dispatched, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Register a subscription (persist to DB).
     */
    public function subscribe(EventSubscriptionContract $subscription): EventSubscription
    {
        return EventSubscription::updateOrCreate(
            [
                'module_key' => $subscription->getModuleKey(),
                'handler_class' => $subscription->getHandlerClass(),
            ],
            [
                'id' => Str::uuid(),
                'event_names' => $subscription->getEventNames(),
                'priority' => $subscription->getPriority(),
                'tenant_filter' => $subscription->getTenantFilter(),
                'payload_filter' => $subscription->getPayloadFilter(),
                'is_active' => true,
            ]
        );
    }

    /**
     * Remove all subscriptions for a module.
     */
    public function unsubscribeModule(string $moduleKey): int
    {
        return EventSubscription::where('module_key', $moduleKey)->delete();
    }

    /**
     * Replay events for a tenant within a time range.
     *
     * @return EventRecord[]
     */
    public function replay(string $tenantId, string $from, string $to): array
    {
        $events = EventRecord::forTenant($tenantId)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $to)
            ->orderBy('occurred_at')
            ->get();

        foreach ($events as $record) {
            $this->dispatch(recordToEvent($record));
        }

        return $events->toArray();
    }

    /**
     * Retry a dead-lettered event.
     */
    public function retryDeadLetter(string $deadLetterId): bool
    {
        $deadLetter = EventDeadLetter::find($deadLetterId);
        if (! $deadLetter) {
            return false;
        }

        try {
            $eventClass = $deadLetter->event_class;
            $event = new $eventClass(
                $deadLetter->tenant_id,
                $deadLetter->user_id,
                $deadLetter->correlation_id
            );

            // Re-dispatch
            $this->dispatch($event);

            $deadLetter->update(['retried_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            $deadLetter->update([
                'retry_count' => $deadLetter->retry_count + 1,
                'error' => array_merge($deadLetter->error ?? [], [['message' => $e->getMessage(), 'at' => now()->toIso8601String()]]),
            ]);

            return false;
        }
    }

    /**
     * Get event statistics for a tenant.
     */
    public function getStats(string $tenantId): array
    {
        $total = EventRecord::forTenant($tenantId)->count();
        $processed = EventRecord::forTenant($tenantId)->whereNotNull('processed_at')->count();
        $pending = EventRecord::forTenant($tenantId)->unhandled()->count();
        $failed = EventRecord::forTenant($tenantId)->failed()->count();
        $deadLetters = EventDeadLetter::forTenant($tenantId)->count();

        return compact('total', 'processed', 'pending', 'failed', 'deadLetters');
    }

    // ─── Private ──────────────────────────────────────────────────

    private function persistEvent(DomainEventContract $event): EventRecord
    {
        return EventRecord::create([
            'id' => Str::uuid(),
            'tenant_id' => $event->getTenantId(),
            'event_name' => $event->getEventName(),
            'version' => $event->getVersion(),
            'user_id' => $event->getUserId(),
            'correlation_id' => $event->getCorrelationId(),
            'source_module' => $event->getSourceModule(),
            'occurred_at' => $event->getOccurredAt(),
            'payload' => $event->getPayload(),
            'idempotent' => $event->isIdempotent(),
        ]);
    }

    private function findMatchingSubscriptions(DomainEventContract $event): \Illuminate\Support\Collection
    {
        return EventSubscription::active()
            ->forEvent($event->getEventName())
            ->orderBy('priority')
            ->get()
            ->filter(fn (EventSubscription $sub) => $this->matchesFilters($sub, $event));
    }

    private function matchesFilters(EventSubscription $sub, DomainEventContract $event): bool
    {
        // Tenant filter
        if ($sub->tenant_filter && $sub->tenant_filter !== $event->getTenantId()) {
            return false;
        }

        // Payload filter
        if ($sub->payload_filter) {
            $payload = $event->getPayload();
            foreach ($sub->payload_filter as $key => $expectedValue) {
                if (($payload[$key] ?? null) !== $expectedValue) {
                    return false;
                }
            }
        }

        return true;
    }

    private function resolveHandler(EventSubscription $subscription): ?EventHandlerContract
    {
        $class = $subscription->handler_class;

        if (! class_exists($class)) {
            Log::warning("Event handler class not found: {$class}");

            return null;
        }

        $handler = app($class);

        if (! $handler instanceof EventHandlerContract) {
            Log::warning("Event handler does not implement EventHandlerContract: {$class}");

            return null;
        }

        return $handler;
    }

    private function handleFailure(EventRecord $record, EventSubscription $subscription, \Throwable $e): void
    {
        Log::error("Event handler failed: {$subscription->module_key}", [
            'event_id' => $record->id,
            'handler' => $subscription->handler_class,
            'error' => $e->getMessage(),
        ]);

        $record->update(['failed_at' => now()]);

        // Create or update dead letter
        EventDeadLetter::create([
            'id' => Str::uuid(),
            'tenant_id' => $record->tenant_id,
            'event_record_id' => $record->id,
            'event_name' => $record->event_name,
            'event_class' => $this->resolveEventClass($record),
            'module_key' => $subscription->module_key,
            'handler_class' => $subscription->handler_class,
            'correlation_id' => $record->correlation_id,
            'user_id' => $record->user_id,
            'payload' => $record->payload,
            'error' => [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'at' => now()->toIso8601String(),
            ],
            'retry_count' => 0,
        ]);
    }

    private function resolveEventClass(EventRecord $record): ?string
    {
        // Map event_name to class: 'contact.created' → ContactCreated::class
        $parts = explode('.', $record->event_name);
        if (count($parts) !== 2) {
            return null;
        }

        $moduleName = ucfirst($parts[0]);
        $action = ucfirst($parts[1]);
        $class = "App\\Platform\\EventBus\\Events\\{$moduleName}{$action}";

        return class_exists($class) ? $class : null;
    }
}
