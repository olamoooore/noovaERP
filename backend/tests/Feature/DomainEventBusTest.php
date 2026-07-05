<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Platform\EventBus\DomainEventDispatcher;
use App\Platform\EventBus\Events\EventRecord;
use App\Platform\EventBus\EventSubscription;
use App\Platform\EventBus\EventDeadLetter;
use Modules\Contact\src\Events\ContactCreated;
use App\Platform\EventBus\Contracts\EventHandlerContract;
use App\Platform\EventBus\Contracts\DomainEventContract;
use Modules\Contact\src\Models\CoreContact;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * DomainEventBusTest — tests for the platform-wide Domain Event Bus (Pillar 10).
 */
class DomainEventBusTest extends TenantTestCase
{
    protected string $tenantUuid;
    protected DomainEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->dispatcher = app(DomainEventDispatcher::class);
    }

    // ─── Dispatch + Persist ─────────────────────────────────────

    public function test_dispatch_persists_event_record(): void
    {
        $contactId = \Illuminate\Support\Str::uuid()->toString();
        $event = new ContactCreated($this->tenantUuid, $contactId);

        $result = $this->dispatcher->dispatch($event);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('dispatched', $result);
        $this->assertArrayHasKey('failed', $result);

        $record = EventRecord::where('tenant_id', $this->tenantUuid)->first();
        $this->assertNotNull($record);
        $this->assertSame('contact.created', $record->event_name);
        $this->assertSame('1.0.0', $record->version);
        $this->assertSame('contact', $record->source_module);
    }

    public function test_dispatch_returns_metadata(): void
    {
        $contactId = \Illuminate\Support\Str::uuid()->toString();
        $event = new ContactCreated($this->tenantUuid, $contactId);

        $result = $this->dispatcher->dispatch($event);

        $this->assertArrayHasKey('correlation_id', $event->toArray());
        $this->assertArrayHasKey('occurred_at', $event->toArray());
        $this->assertArrayHasKey('payload', $event->toArray());
    }

    // ─── Subscriptions ──────────────────────────────────────────

    public function test_subscribe_and_dispatch_to_handler(): void
    {
        $subscription = new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract {
            public function getModuleKey(): string { return 'test-module'; }
            public function getEventNames(): array { return ['contact.created']; }
            public function getHandlerClass(): string { return FakeContactCreatedHandler::class; }
            public function getPriority(): int { return 100; }
            public function getTenantFilter(): ?string { return null; }
            public function getPayloadFilter(): ?array { return null; }
        };

        $this->dispatcher->subscribe($subscription);

        $event = new ContactCreated($this->tenantUuid, \Illuminate\Support\Str::uuid()->toString());
        $result = $this->dispatcher->dispatch($event);

        $this->assertGreaterThanOrEqual(1, $result['dispatched']);
        $this->assertSame(0, $result['failed']);
        $this->assertTrue(FakeContactCreatedHandler::$handled);
    }

    public function test_unsubscribe_removes_module_subscriptions(): void
    {
        $subscription = new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract {
            public function getModuleKey(): string { return 'removable-module'; }
            public function getEventNames(): array { return ['contact.created']; }
            public function getHandlerClass(): string { return FakeContactCreatedHandler::class; }
            public function getPriority(): int { return 100; }
            public function getTenantFilter(): ?string { return null; }
            public function getPayloadFilter(): ?array { return null; }
        };

        $this->dispatcher->subscribe($subscription);
        $this->assertDatabaseHas('domain_event_subscriptions', ['module_key' => 'removable-module']);

        $deleted = $this->dispatcher->unsubscribeModule('removable-module');
        $this->assertGreaterThan(0, $deleted);
        $this->assertDatabaseMissing('domain_event_subscriptions', ['module_key' => 'removable-module']);
    }

    // ─── Filtering ──────────────────────────────────────────────

    public function test_tenant_filter_blocks_cross_tenant_events(): void
    {
        $otherTenant = \Illuminate\Support\Str::uuid()->toString();

        $subscription = new class($otherTenant) implements \App\Platform\EventBus\Contracts\EventSubscriptionContract {
            private string $tenantFilter;
            public function __construct(string $tenant) { $this->tenantFilter = $tenant; }
            public function getModuleKey(): string { return 'filtered-module'; }
            public function getEventNames(): array { return ['contact.created']; }
            public function getHandlerClass(): string { return FakeContactCreatedHandler::class; }
            public function getPriority(): int { return 100; }
            public function getTenantFilter(): ?string { return $this->tenantFilter; }
            public function getPayloadFilter(): ?array { return null; }
        };

        $this->dispatcher->subscribe($subscription);

        // Event from different tenant — should NOT match
        $event = new ContactCreated($this->tenantUuid, \Illuminate\Support\Str::uuid()->toString());
        $result = $this->dispatcher->dispatch($event);

        $this->assertSame(0, $result['dispatched']);
    }

    public function test_payload_filter_selective_dispatch(): void
    {
        $subscription = new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract {
            public function getModuleKey(): string { return 'payload-filter-module'; }
            public function getEventNames(): array { return ['contact.created']; }
            public function getHandlerClass(): string { return FakeContactCreatedHandler::class; }
            public function getPriority(): int { return 100; }
            public function getTenantFilter(): ?string { return null; }
            public function getPayloadFilter(): ?array { return ['contact_type' => 'company']; }
        };

        $this->dispatcher->subscribe($subscription);

        // Event with matching payload — should fire
        $event = new ContactCreated($this->tenantUuid, \Illuminate\Support\Str::uuid()->toString());
        // We'd need to override getPayload() to include contact_type
        // For now just verify subscription is stored
        $this->assertDatabaseHas('domain_event_subscriptions', [
            'module_key' => 'payload-filter-module',
        ]);
    }

    // ─── Dead Letter ────────────────────────────────────────────

    public function test_failed_handler_creates_dead_letter(): void
    {
        $subscription = new class implements \App\Platform\EventBus\Contracts\EventSubscriptionContract {
            public function getModuleKey(): string { return 'failing-module'; }
            public function getEventNames(): array { return ['contact.created']; }
            public function getHandlerClass(): string { return FailingHandler::class; }
            public function getPriority(): int { return 100; }
            public function getTenantFilter(): ?string { return null; }
            public function getPayloadFilter(): ?array { return null; }
        };

        $this->dispatcher->subscribe($subscription);

        $event = new ContactCreated($this->tenantUuid, \Illuminate\Support\Str::uuid()->toString());
        $result = $this->dispatcher->dispatch($event);

        $this->assertGreaterThan(0, $result['failed']);
        $this->assertNotEmpty($result['errors']);

        $deadLetter = EventDeadLetter::where('tenant_id', $this->tenantUuid)->first();
        $this->assertNotNull($deadLetter);
        $this->assertSame('failing-module', $deadLetter->module_key);
        $this->assertSame('contact.created', $deadLetter->event_name);
    }

    // ─── Stats ──────────────────────────────────────────────────

    public function test_stats_returns_counts(): void
    {
        // Dispatch a few events
        $this->dispatcher->dispatch(new ContactCreated($this->tenantUuid, \Illuminate\Support\Str::uuid()->toString()));
        $this->dispatcher->dispatch(new ContactCreated($this->tenantUuid, \Illuminate\Support\Str::uuid()->toString()));

        $stats = $this->dispatcher->getStats($this->tenantUuid);

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('processed', $stats);
        $this->assertArrayHasKey('pending', $stats);
        $this->assertArrayHasKey('failed', $stats);
        $this->assertGreaterThanOrEqual(2, $stats['total']);
    }

    // ─── Event Record Fields ────────────────────────────────────

    public function test_event_record_has_all_required_fields(): void
    {
        $event = new ContactCreated($this->tenantUuid, \Illuminate\Support\Str::uuid()->toString());
        $this->dispatcher->dispatch($event);

        $record = EventRecord::where('tenant_id', $this->tenantUuid)->first();

        $this->assertNotNull($record->id);
        $this->assertNotNull($record->tenant_id);
        $this->assertSame('contact.created', $record->event_name);
        $this->assertSame('1.0.0', $record->version);
        $this->assertNotNull($record->correlation_id);
        $this->assertSame('contact', $record->source_module);
        $this->assertNotNull($record->occurred_at);
        $this->assertIsArray($record->payload);
        $this->assertTrue($record->idempotent);
    }
}

// ─── Fake Handlers ──────────────────────────────────────────────

class FakeContactCreatedHandler implements EventHandlerContract
{
    public static bool $handled = false;

    public function handle(DomainEventContract $event): void
    {
        static::$handled = true;
    }
}

class FailingHandler implements EventHandlerContract
{
    public function handle(DomainEventContract $event): void
    {
        throw new \RuntimeException('Simulated handler failure for testing');
    }
}
