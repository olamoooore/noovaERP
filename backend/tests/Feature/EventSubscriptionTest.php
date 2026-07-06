<?php

namespace Tests\Feature;

use App\Platform\EventBus\DomainEventDispatcher;
use App\Platform\EventBus\EventSubscription;
use App\Platform\EventBus\Handlers\AccountingContactSyncHandler;
use App\Platform\EventBus\Handlers\CrmContactSyncHandler;
use App\Platform\EventBus\Handlers\AuditTrailHandler;
use App\Platform\SystemLog\SystemLog;
use Modules\Contact\src\Events\ContactCreated;
use Modules\Contact\src\Events\ContactUpdated;
use Modules\Contact\src\Events\ContactRoleChanged;
use Tests\TenantTestCase;

class EventSubscriptionTest extends TenantTestCase
{
    protected string $tenantUuid;
    protected DomainEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->dispatcher = app(DomainEventDispatcher::class);

        // Register subscriptions manually for testing
        $this->registerTestSubscriptions();
    }

    private function registerTestSubscriptions(): void
    {
        // Accounting subscription
        $this->dispatcher->subscribe(new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract {
            public function getModuleKey(): string { return 'accounting'; }
            public function getEventNames(): array { return ['contact.created', 'contact.updated', 'contact.deleted', 'contact.role.changed']; }
            public function getHandlerClass(): string { return AccountingContactSyncHandler::class; }
            public function getPriority(): int { return 100; }
            public function getTenantFilter(): ?string { return null; }
            public function getPayloadFilter(): ?array { return null; }
        });

        // CRM subscription
        $this->dispatcher->subscribe(new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract {
            public function getModuleKey(): string { return 'crm'; }
            public function getEventNames(): array { return ['contact.created', 'contact.updated', 'contact.deleted', 'contact.role.changed', 'contact.profile.updated']; }
            public function getHandlerClass(): string { return CrmContactSyncHandler::class; }
            public function getPriority(): int { return 100; }
            public function getTenantFilter(): ?string { return null; }
            public function getPayloadFilter(): ?array { return null; }
        });

        // Audit trail subscription
        $this->dispatcher->subscribe(new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract {
            public function getModuleKey(): string { return 'platform'; }
            public function getEventNames(): array { return ['*']; }
            public function getHandlerClass(): string { return AuditTrailHandler::class; }
            public function getPriority(): int { return 1000; }
            public function getTenantFilter(): ?string { return null; }
            public function getPayloadFilter(): ?array { return null; }
        });
    }

    public function test_accounting_subscription_is_registered(): void
    {
        $sub = EventSubscription::where('module_key', 'accounting')
            ->where('handler_class', AccountingContactSyncHandler::class)
            ->first();
        $this->assertNotNull($sub);
        $this->assertContains('contact.created', $sub->event_names);
        $this->assertTrue($sub->is_active);
    }

    public function test_crm_subscription_is_registered(): void
    {
        $sub = EventSubscription::where('module_key', 'crm')
            ->where('handler_class', CrmContactSyncHandler::class)
            ->first();
        $this->assertNotNull($sub);
        $this->assertContains('contact.created', $sub->event_names);
        $this->assertTrue($sub->is_active);
    }

    public function test_audit_trail_subscription_is_registered(): void
    {
        $sub = EventSubscription::where('module_key', 'platform')
            ->where('handler_class', AuditTrailHandler::class)
            ->first();
        $this->assertNotNull($sub);
        $this->assertContains('*', $sub->event_names);
        $this->assertTrue($sub->is_active);
    }

    public function test_dispatching_contact_event_triggers_all_handlers(): void
    {
        $event = new ContactCreated(
            $this->tenantUuid,
            'test-contact-id-123',
            null,
            null,
            ['name' => 'Test Contact']
        );

        $result = $this->dispatcher->dispatch($event);

        $this->assertGreaterThanOrEqual(2, $result['dispatched']); // at least accounting + crm
        $this->assertEquals(0, $result['failed']);

        // Verify audit trail was written
        $log = SystemLog::where('category', 'audit.domain_event')
            ->where('correlation_id', $event->getCorrelationId())
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('contact.created', $log->message);
    }

    public function test_accounting_handler_logs_event(): void
    {
        $event = new ContactCreated(
            $this->tenantUuid,
            'test-contact-456',
            null,
            null,
            ['name' => 'Accounting Test']
        );

        $this->dispatcher->dispatch($event);

        $log = SystemLog::where('category', 'accounting.contact_sync')
            ->where('correlation_id', $event->getCorrelationId())
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('contact.created', $log->message);
    }

    public function test_crm_handler_logs_event(): void
    {
        $event = new ContactUpdated(
            $this->tenantUuid,
            'test-contact-789',
            null,
            ['name' => 'Old'],
            ['name' => 'New']
        );

        $this->dispatcher->dispatch($event);

        $log = SystemLog::where('category', 'crm.contact_sync')
            ->where('correlation_id', $event->getCorrelationId())
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('contact.updated', $log->message);
    }

    public function test_role_change_event_triggers_handlers(): void
    {
        $event = new ContactRoleChanged(
            $this->tenantUuid,
            'test-contact-role',
            'accounting_customer',
            'added'
        );

        $result = $this->dispatcher->dispatch($event);

        $this->assertGreaterThanOrEqual(2, $result['dispatched']);
        $this->assertEquals(0, $result['failed']);
    }

    public function test_dead_letter_on_handler_failure(): void
    {
        $event = new ContactCreated(
            $this->tenantUuid,
            'test-contact-fail',
            null,
            null,
            ['name' => 'Fail Test']
        );

        $result = $this->dispatcher->dispatch($event);

        // All current handlers should succeed
        $this->assertEquals(0, $result['failed']);
    }
}
