<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountingOutboxEvent;
use App\Platform\EventBus\Contracts\DomainEventContract;
use App\Platform\EventBus\DomainEventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue noovaerp/NoovaERP#62 — accounting_outbox_events must have a
 * consumer that publishes written rows onto the Domain Event Bus with
 * retry/no-loss semantics.
 */
class Issue62AccountingOutboxPublisherTest extends TenantTestCase
{
    private string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantUuid = (string) Str::uuid();
        $this->bindTenantContext($this->tenantUuid);
    }

    private function makeOutboxRow(): AccountingOutboxEvent
    {
        return AccountingOutboxEvent::create([
            'tenant_id' => $this->tenantUuid,
            'event_type' => 'JournalPosted',
            'event_version' => '1.0',
            'aggregate_type' => 'JournalEntry',
            'aggregate_id' => (string) random_int(1, 99999),
            'aggregate_version' => 1,
            'source_capability' => 'ACC-002',
            'correlation_id' => (string) Str::uuid(),
            'causation_id' => (string) Str::uuid(),
            'event_payload' => ['journal_entry_id' => 1, 'entry_number' => 'JE-TEST'],
        ]);
    }

    public function test_unpublished_row_is_published_and_recorded_on_event_bus(): void
    {
        $row = $this->makeOutboxRow();

        $this->artisan('accounting:publish-outbox')->assertSuccessful();

        $row->refresh();
        $this->assertNotNull($row->published_at, 'outbox row was not marked published');
        $this->assertNull($row->last_publish_error);

        // The Domain Event Bus must have persisted the bridged event.
        // domain_event_records.payload is a plain json column (not jsonb),
        // so filter in PHP rather than relying on ->> operators.
        $record = DB::table('domain_event_records')
            ->where('tenant_id', $this->tenantUuid)
            ->where('event_name', 'accounting.outbox.published')
            ->get()
            ->first(fn ($r) => (json_decode($r->payload, true)['outbox_event_id'] ?? null) === $row->id);
        $this->assertNotNull($record, 'no domain_event_records row for the published outbox event');
        $this->assertNull($record->failed_at);
    }

    public function test_publish_failure_retains_row_for_retry(): void
    {
        $row = $this->makeOutboxRow();

        $this->app->instance(DomainEventDispatcher::class, new class extends DomainEventDispatcher
        {
            public function dispatch(DomainEventContract $event): array
            {
                throw new \RuntimeException('bus unavailable');
            }
        });

        $this->artisan('accounting:publish-outbox')->assertSuccessful();

        $row->refresh();
        $this->assertNull($row->published_at, 'failed publish must not mark the row published');
        $this->assertSame(1, (int) $row->publish_attempts);
        $this->assertStringContainsString('bus unavailable', (string) $row->last_publish_error);

        // Recovery: once the bus works, the retained row is published.
        $this->app->forgetInstance(DomainEventDispatcher::class);
        $this->artisan('accounting:publish-outbox')->assertSuccessful();

        $row->refresh();
        $this->assertNotNull($row->published_at);
    }

    public function test_already_published_rows_are_not_republished(): void
    {
        $row = $this->makeOutboxRow();
        $this->artisan('accounting:publish-outbox')->assertSuccessful();
        $row->refresh();
        $publishedAt = $row->published_at;

        $this->artisan('accounting:publish-outbox')->assertSuccessful();

        $this->assertSame(1, AccountingOutboxEvent::where('id', $row->id)->count());
        $row->refresh();
        $this->assertTrue($publishedAt->equalTo($row->published_at));
    }
}
