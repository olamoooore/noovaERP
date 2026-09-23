<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountingOutboxEvent;
use App\Models\Tenant;
use App\Platform\EventBus\Contracts\DomainEventContract;
use App\Platform\EventBus\Contracts\EventHandlerContract;
use App\Platform\EventBus\Contracts\EventSubscriptionContract;
use App\Platform\EventBus\DomainEventDispatcher;
use App\Platform\EventBus\EventDeadLetter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #62 (hostile re-review HR62-01/02/03) — outbox reliability:
 *
 *   HR62-01: publisher retry exhaustion transitions the row into an explicit,
 *            queryable dead-letter state with governed requeue semantics and
 *            preserved provenance.
 *   HR62-02: the accounting bridge dead letter replays mechanically (the
 *            bridge event is reconstructed from its outbox row, not the
 *            generic constructor).
 *   HR62-03: real subscriber-failure recovery through the real publisher +
 *            event bus: fail → dead letter → fix handler → retry → delivered.
 */
class HR62OutboxDeadLetterRecoveryTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = (string) $tenant->id;
        $this->bindTenantContext($this->tenantId);

        HR62RecoverableHandler::$failuresRemaining = 1;
        HR62RecoverableHandler::$handled = 0;
    }

    private function outboxRow(array $overrides = []): AccountingOutboxEvent
    {
        return AccountingOutboxEvent::create(array_merge([
            'tenant_id' => $this->tenantId,
            'event_type' => 'JournalPosted',
            'event_version' => '1.0',
            'aggregate_type' => 'JournalEntry',
            'aggregate_id' => '1',
            'aggregate_version' => 1,
            'source_capability' => 'ACC-002',
            'correlation_id' => (string) Str::uuid(),
            'causation_id' => (string) Str::uuid(),
            'event_payload' => ['journal_entry_id' => 1, 'posted_by' => '1'],
        ], $overrides));
    }

    private function subscribeRecoverableHandler(): void
    {
        $subscription = new class implements EventSubscriptionContract
        {
            public function getModuleKey(): string
            {
                return 'hr62-test-module';
            }

            public function getEventNames(): array
            {
                return ['accounting.outbox.published'];
            }

            public function getHandlerClass(): string
            {
                return HR62RecoverableHandler::class;
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
        };

        app(DomainEventDispatcher::class)->subscribe($subscription);
    }

    /**
     * HR62-03: real subscriber failure → dead letter → fixed handler → retry.
     */
    public function test_real_subscriber_failure_creates_dead_letter_and_recovers_via_retry(): void
    {
        $row = $this->outboxRow();
        $this->subscribeRecoverableHandler();

        // Publish through the real command + bus. The bus accepts the handoff
        // and the subscriber fails.
        $this->artisan('accounting:publish-outbox')->assertSuccessful();

        $row->refresh();
        $this->assertNotNull($row->published_at, 'The handoff to the bus must complete even when a subscriber fails.');

        $record = DB::table('domain_event_records')
            ->where('event_name', 'accounting.outbox.published')
            ->latest('id')->first();
        $this->assertNotNull($record, 'domain_event_records must carry the bridge event.');
        $this->assertNotNull($record->failed_at, 'The failed subscriber must be recorded on the event record.');

        $deadLetter = EventDeadLetter::where('event_name', 'accounting.outbox.published')->first();
        $this->assertNotNull($deadLetter, 'A dead letter must be created for the failed subscriber.');
        $this->assertSame(\App\Accounting\Events\AccountingOutboxPublished::class, $deadLetter->event_class,
            'The dead letter must resolve the explicit bridge contract (HR62-02).');
        $this->assertSame($row->id, $deadLetter->payload['outbox_event_id'] ?? null,
            'Dead-letter provenance must link back to the outbox row.');

        // Correct the subscriber failure and replay through the approved path.
        HR62RecoverableHandler::$failuresRemaining = 0;

        $retried = app(DomainEventDispatcher::class)->retryDeadLetter($deadLetter->id);
        $this->assertTrue($retried, 'retryDeadLetter must replay the bridge event through its explicit contract.');
        $this->assertSame(1, HR62RecoverableHandler::$handled, 'The corrected handler must receive the replayed event.');

        $this->assertNotNull($deadLetter->fresh()->retried_at, 'The dead-letter retry must be recorded.');
    }

    /**
     * HR62-01: retry exhaustion → explicit dead-letter state, not silent
     * query exclusion; requeue restores the row with provenance preserved.
     */
    public function test_retry_exhaustion_dead_letters_the_row_and_requeue_recovers_it(): void
    {
        // A row already at its attempt budget with a recorded failure.
        $row = $this->outboxRow([
            'publish_attempts' => 5,
            'last_publish_error' => 'previous failure',
        ]);

        // A bus failure can be induced by a subscription whose handler always
        // throws — the command then transitions the row to dead-letter.
        $this->subscribeAlwaysFailingHandler();

        // Budget already spent: the row is not even attempted; it must still
        // be transitioned explicitly so operations can see it.
        $this->artisan('accounting:publish-outbox', ['--max-attempts' => 5])->assertSuccessful();

        $row->refresh();
        $this->assertNotNull($row->dead_lettered_at, 'Exhausted rows must be explicitly dead-lettered.');
        $this->assertTrue($row->isDeadLettered());

        // Operational visibility: dead-lettered rows are queryable.
        $this->assertSame(1, AccountingOutboxEvent::query()->deadLettered()->count());

        // Governed requeue: back to pending with provenance preserved.
        $this->artisan('accounting:requeue-outbox', ['id' => $row->id])->assertSuccessful();

        $row->refresh();
        $this->assertNull($row->dead_lettered_at);
        $this->assertNotNull($row->requeued_at);
        $this->assertSame(1, (int) $row->requeue_count);
        $this->assertSame(0, (int) $row->publish_attempts, 'Requeue resets the attempt budget.');
        $this->assertSame('previous failure', $row->last_publish_error, 'Failure provenance is preserved.');

        // A subsequent publisher run picks the row up again.
        $this->assertSame(1, AccountingOutboxEvent::query()->pending()->count());
    }

    private function subscribeAlwaysFailingHandler(): void
    {
        $subscription = new class implements EventSubscriptionContract
        {
            public function getModuleKey(): string
            {
                return 'hr62-failing-module';
            }

            public function getEventNames(): array
            {
                return ['accounting.outbox.published'];
            }

            public function getHandlerClass(): string
            {
                return HR62AlwaysFailingHandler::class;
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
        };

        app(DomainEventDispatcher::class)->subscribe($subscription);
    }
}

class HR62RecoverableHandler implements EventHandlerContract
{
    public static int $failuresRemaining = 1;
    public static int $handled = 0;

    public function handle(DomainEventContract $event): void
    {
        if (static::$failuresRemaining > 0) {
            static::$failuresRemaining--;
            throw new \RuntimeException('HR62 simulated subscriber failure');
        }

        static::$handled++;
    }
}

class HR62AlwaysFailingHandler implements EventHandlerContract
{
    public function handle(DomainEventContract $event): void
    {
        throw new \RuntimeException('HR62 always failing handler');
    }
}
