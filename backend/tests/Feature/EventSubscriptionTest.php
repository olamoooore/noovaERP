<?php

namespace Tests\Feature;

use App\Platform\EventBus\DomainEventDispatcher;
use App\Platform\EventBus\EventSubscription;
use App\Platform\EventBus\Handlers\AccountingContactSyncHandler;
use App\Platform\EventBus\Handlers\AuditTrailHandler;
use App\Platform\EventBus\Handlers\CrmContactSyncHandler;
use App\Platform\SystemLog\SystemLog;
use Illuminate\Support\Str;
use Modules\Contact\src\Events\ContactCreated;
use Modules\Contact\src\Events\ContactRoleChanged;
use Modules\Contact\src\Events\ContactUpdated;
use Modules\Contact\src\Models\AccountingCustomerProfile;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Models\CrmContactProfile;
use Tests\TenantTestCase;

class EventSubscriptionTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected DomainEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->dispatcher = app(DomainEventDispatcher::class);
        $this->registerTestSubscriptions();
    }

    private function registerTestSubscriptions(): void
    {
        $this->dispatcher->subscribe(new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract
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

        $this->dispatcher->subscribe(new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract
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

        $this->dispatcher->subscribe(new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract
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

    private function createTestContact(array $overrides = []): CoreContact
    {
        return CoreContact::create(array_merge([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'contact_type' => 'person',
            'display_name' => 'Test Contact',
            'email' => 'test@example.com',
            'is_active' => true,
        ], $overrides));
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
        $contact = $this->createTestContact(['display_name' => 'Dispatch Test']);

        $event = new ContactCreated(
            tenant()->id,
            $contact->id,
            null,
            null,
            $contact->toArray()
        );

        $result = $this->dispatcher->dispatch($event);

        $this->assertGreaterThanOrEqual(2, $result['dispatched']);
        $this->assertEquals(0, $result['failed']);

        $log = SystemLog::where('category', 'audit.domain_event')
            ->where('correlation_id', $event->getCorrelationId())
            ->first();
        $this->assertNotNull($log);
    }

    public function test_accounting_handler_provisions_customer_profile(): void
    {
        $contact = $this->createTestContact(['display_name' => 'Customer Test']);

        $event = new ContactRoleChanged(
            tenant()->id,
            $contact->id,
            'customer',
            'added'
        );

        $this->dispatcher->dispatch($event);

        $profile = AccountingCustomerProfile::where('contact_id', $contact->id)->first();
        $this->assertNotNull($profile, 'AccountingCustomerProfile should be provisioned');
        $this->assertEquals('active', $profile->account_status);
        $this->assertStringStartsWith('CUST-', $profile->customer_code);
    }

    public function test_accounting_handler_provisions_vendor_profile(): void
    {
        $contact = $this->createTestContact(['display_name' => 'Vendor Test']);

        $event = new ContactRoleChanged(
            tenant()->id,
            $contact->id,
            'vendor',
            'added'
        );

        $this->dispatcher->dispatch($event);

        $profile = \Modules\Contact\src\Models\AccountingVendorProfile::where('contact_id', $contact->id)->first();
        $this->assertNotNull($profile, 'AccountingVendorProfile should be provisioned');
        $this->assertEquals('active', $profile->account_status);
    }

    public function test_crm_handler_provisions_lead_profile(): void
    {
        $contact = $this->createTestContact(['display_name' => 'CRM Lead Test']);

        $event = new ContactRoleChanged(
            tenant()->id,
            $contact->id,
            'lead',
            'added'
        );

        $this->dispatcher->dispatch($event);

        $profile = CrmContactProfile::where('contact_id', $contact->id)->first();
        $this->assertNotNull($profile, 'CrmContactProfile should be provisioned');
        $this->assertEquals('active', $profile->lead_status);
        $this->assertEquals('new', $profile->lead_stage);
    }

    public function test_accounting_handler_logs_sync_event(): void
    {
        $contact = $this->createTestContact(['display_name' => 'Log Test']);

        $event = new ContactCreated(
            tenant()->id,
            $contact->id,
            null,
            null,
            $contact->toArray()
        );

        $this->dispatcher->dispatch($event);

        $log = SystemLog::where('category', 'accounting.contact_sync')
            ->where('correlation_id', $event->getCorrelationId())
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('contact.created', $log->message);
    }

    public function test_crm_handler_logs_sync_event(): void
    {
        $contact = $this->createTestContact(['display_name' => 'CRM Log Test']);

        $event = new ContactUpdated(
            tenant()->id,
            $contact->id,
            null,
            ['display_name' => 'Old Name'],
            ['display_name' => 'New Name']
        );

        $this->dispatcher->dispatch($event);

        $log = SystemLog::where('category', 'crm.contact_sync')
            ->where('correlation_id', $event->getCorrelationId())
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('contact.updated', $log->message);
    }

    public function test_role_removed_deactivates_profiles(): void
    {
        $contact = $this->createTestContact(['display_name' => 'Deactivate Test']);

        AccountingCustomerProfile::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'contact_id' => $contact->id,
            'customer_code' => 'CUST-TEST',
            'payment_terms' => 30,
            'credit_limit' => 0,
            'current_balance' => 0,
            'currency' => 'MYR',
            'account_opened_date' => now(),
            'account_status' => 'active',
        ]);

        $event = new ContactRoleChanged(
            tenant()->id,
            $contact->id,
            'customer',
            'removed'
        );

        $this->dispatcher->dispatch($event);

        $profile = AccountingCustomerProfile::where('contact_id', $contact->id)->first();
        $this->assertEquals('inactive', $profile->account_status);
    }
}
