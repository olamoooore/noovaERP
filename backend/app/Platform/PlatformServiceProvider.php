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

        $this->registerObservers();
    }

    private function registerObservers(): void
    {
        // POS module
        if (class_exists(\Modules\POS\Models\PosOrder::class)) {
            \Modules\POS\Models\PosOrder::observe(\App\Observers\AuditTrailObserver::class);
        }
        if (class_exists(\Modules\POS\Models\PosPayment::class)) {
            \Modules\POS\Models\PosPayment::observe(\App\Observers\AuditTrailObserver::class);
        }

        // Inventory module
        if (class_exists(\Modules\Inventory\Models\StockLevel::class)) {
            \Modules\Inventory\Models\StockLevel::observe(\App\Observers\AuditTrailObserver::class);
        }
        if (class_exists(\Modules\Inventory\Models\PurchaseOrder::class)) {
            \Modules\Inventory\Models\PurchaseOrder::observe(\App\Observers\AuditTrailObserver::class);
        }

        // HRM module
        if (class_exists(\Modules\HRM\Models\Employee::class)) {
            \Modules\HRM\Models\Employee::observe(\App\Observers\AuditTrailObserver::class);
        }
        if (class_exists(\Modules\HRM\Models\PayrollRun::class)) {
            \Modules\HRM\Models\PayrollRun::observe(\App\Observers\AuditTrailObserver::class);
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
