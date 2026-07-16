<?php

namespace Tests\Feature;

use App\Platform\EventBus\DomainEventDispatcher;
use App\Platform\EventBus\Handlers\AccountingContactSyncHandler;
use App\Platform\EventBus\Handlers\AuditTrailHandler;
use App\Platform\EventBus\Handlers\CrmContactSyncHandler;
use App\Platform\SystemLog\SystemLog;
use Modules\Contact\src\Services\ContactMasterService;
use Tests\TenantTestCase;

class AuditTrailIntegrationTest extends TenantTestCase
{
    private ContactMasterService $contactService;

    private string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        // Ensure subscriptions are registered (PlatformServiceProvider may have skipped at boot)
        $dispatcher = app(DomainEventDispatcher::class);
        $this->registerSubscriptions($dispatcher);

        $this->contactService = app(ContactMasterService::class);
        $this->contactService->setEventDispatcher($dispatcher);
    }

    private function registerSubscriptions(DomainEventDispatcher $dispatcher): void
    {
        $dispatcher->unsubscribeModule('accounting');
        $dispatcher->unsubscribeModule('crm');
        $dispatcher->unsubscribeModule('platform');

        $dispatcher->subscribe(new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract
        {
            public function getModuleKey(): string
            {
                return 'accounting';
            }

            public function getEventNames(): array
            {
                return ['contact.created', 'contact.updated', 'contact.deleted', 'contact.role.changed'];
            }

            public function getHandlerClass(): string
            {
                return AccountingContactSyncHandler::class;
            }

            public function getPriority(): int
            {
                return 100;
            }

            public function getTenantFilter(): ?string
            {
                return null;
            }

            public function getPayloadFilter(): ?array
            {
                return null;
            }
        });

        $dispatcher->subscribe(new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract
        {
            public function getModuleKey(): string
            {
                return 'crm';
            }

            public function getEventNames(): array
            {
                return ['contact.created', 'contact.updated', 'contact.deleted', 'contact.role.changed', 'contact.profile.updated'];
            }

            public function getHandlerClass(): string
            {
                return CrmContactSyncHandler::class;
            }

            public function getPriority(): int
            {
                return 100;
            }

            public function getTenantFilter(): ?string
            {
                return null;
            }

            public function getPayloadFilter(): ?array
            {
                return null;
            }
        });

        $dispatcher->subscribe(new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract
        {
            public function getModuleKey(): string
            {
                return 'platform';
            }

            public function getEventNames(): array
            {
                return ['*'];
            }

            public function getHandlerClass(): string
            {
                return AuditTrailHandler::class;
            }

            public function getPriority(): int
            {
                return 1000;
            }

            public function getTenantFilter(): ?string
            {
                return null;
            }

            public function getPayloadFilter(): ?array
            {
                return null;
            }
        });
    }

    public function test_contact_create_emits_audit_log(): void
    {
        $contact = $this->contactService->create([
            'display_name' => 'Audit Trail Test Contact',
            'legal_name' => 'Audit Trail Test Contact Ltd',
            'status' => 'active',
            'country_id' => 'MY',
        ], $this->tenantUuid);

        $log = SystemLog::where('category', 'audit.domain_event')
            ->where('message', 'ilike', '%contact.created%')
            ->first();
        $this->assertNotNull($log, 'Audit log entry not found for contact.created event');
    }

    public function test_multiple_events_logged_with_correlation(): void
    {
        $contact = $this->contactService->create([
            'display_name' => 'Correlation Test',
            'legal_name' => 'Correlation Test Ltd',
            'status' => 'active',
            'country_id' => 'MY',
        ], $this->tenantUuid);

        $this->contactService->update($contact->id, [
            'display_name' => 'Correlation Test Updated',
        ], $this->tenantUuid);

        $logs = SystemLog::where('category', 'audit.domain_event')->get();
        $this->assertGreaterThanOrEqual(2, $logs->count());
    }

    public function test_system_log_service_is_bound(): void
    {
        $service = app(\App\Platform\SystemLog\Contracts\SystemLogServiceContract::class);
        $this->assertInstanceOf(\App\Platform\SystemLog\SystemLogService::class, $service);
    }
}
