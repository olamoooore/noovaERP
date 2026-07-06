<?php

namespace App\Platform;

use App\Platform\EventBus\DomainEventDispatcher;
use App\Platform\EventBus\EventSubscription;
use App\Platform\SystemLog\Contracts\SystemLogServiceContract;
use App\Platform\SystemLog\SystemLogService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DomainEventDispatcher::class);
        $this->app->bind(SystemLogServiceContract::class, SystemLogService::class);

        // Wire up the DomainEventDispatcher to ContactMasterService
        $this->app->afterResolving(\Modules\Contact\src\Services\ContactMasterService::class, function ($service) {
            $service->setEventDispatcher(app(DomainEventDispatcher::class));
        });
    }

    public function boot(): void
    {
        try {
            $this->registerEventSubscriptions();
        } catch (\Throwable $e) {
            // Silently skip if tables don't exist yet (e.g., during migrations or tests)
            \Illuminate\Support\Facades\Log::debug('PlatformServiceProvider: skipping event subscription registration', [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function registerEventSubscriptions(): void
    {
        // Skip if the domain_event_subscriptions table doesn't exist yet
        if (!\Illuminate\Support\Facades\Schema::hasTable('domain_event_subscriptions')) {
            return;
        }

        $dispatcher = app(DomainEventDispatcher::class);

        // Accounting subscribes to all contact events
        $this->createSubscription(
            $dispatcher,
            'accounting',
            ['contact.created', 'contact.updated', 'contact.deleted', 'contact.role.changed'],
            \App\Platform\EventBus\Handlers\AccountingContactSyncHandler::class,
            100
        );

        // CRM subscribes to all contact events
        $this->createSubscription(
            $dispatcher,
            'crm',
            ['contact.created', 'contact.updated', 'contact.deleted', 'contact.role.changed', 'contact.profile.updated'],
            \App\Platform\EventBus\Handlers\CrmContactSyncHandler::class,
            100
        );

        // Audit trail — logs all domain events across all modules
        $this->createSubscription(
            $dispatcher,
            'platform',
            ['*'],
            \App\Platform\EventBus\Handlers\AuditTrailHandler::class,
            1000
        );
    }

    private function createSubscription(
        DomainEventDispatcher $dispatcher,
        string $moduleKey,
        array $eventNames,
        string $handlerClass,
        int $priority = 100
    ): void {
        $subscription = new class($moduleKey, $eventNames, $handlerClass, $priority) implements \App\Platform\EventBus\Contracts\EventSubscriptionContract {
            private string $moduleKey;
            private array $eventNames;
            private string $handlerClass;
            private int $priority;

            public function __construct(string $moduleKey, array $eventNames, string $handlerClass, int $priority)
            {
                $this->moduleKey = $moduleKey;
                $this->eventNames = $eventNames;
                $this->handlerClass = $handlerClass;
                $this->priority = $priority;
            }

            public function getModuleKey(): string { return $this->moduleKey; }
            public function getEventNames(): array { return $this->eventNames; }
            public function getHandlerClass(): string { return $this->handlerClass; }
            public function getPriority(): int { return $this->priority; }
            public function getTenantFilter(): ?string { return null; }
            public function getPayloadFilter(): ?array { return null; }
        };

        $dispatcher->subscribe($subscription);
    }
}
