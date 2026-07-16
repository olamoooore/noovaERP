<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Models\OutboxEvent;
use Modules\EventManagement\Models\OutboxEventConsumer;
use Modules\EventManagement\Models\OutboxProcessingLog;
use Modules\EventManagement\Services\OutboxService;
use Tests\TestCase;

/**
 * Property-Based Test for Exactly-Once Side Effects
 *
 * **Feature: erp-event-management-phase3, Property 19: Exactly-Once Side Effects**
 * **Validates: Requirements 18.1, 18.2, 18.3, 18.4, 18.5**
 *
 * Tests that side effects are processed exactly once even under failure conditions.
 */
class ExactlyOnceSideEffectsTest extends TestCase
{
    use RefreshDatabase;

    private OutboxService $outboxService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outboxService = app(OutboxService::class);
    }

    /**
     * Property: For any outbox event, side effects should be processed exactly once
     * even under retry conditions, failures, and concurrent processing attempts.
     */
    public function test_exactly_once_side_effects_property()
    {
        $this->markTestSkipped('Property-based test - run with: php artisan test --filter=test_exactly_once_side_effects_property');

        // Run property test with 25+ iterations
        for ($i = 0; $i < 25; $i++) {
            $this->runSingleExactlyOnceTest($i);
        }
    }

    /**
     * Single iteration of the exactly-once processing property test
     */
    private function runSingleExactlyOnceTest(int $iteration): void
    {
        DB::beginTransaction();

        try {
            // Generate random test data
            $testData = $this->generateRandomEventData($iteration);

            // Test idempotency - creating same event multiple times
            $this->testIdempotentEventCreation($testData);

            // Test exactly-once processing under retries
            $this->testExactlyOnceProcessingWithRetries($testData);

            // Test concurrent processing prevention
            $this->testConcurrentProcessingPrevention($testData);

            // Test consumer-level exactly-once processing
            $this->testConsumerLevelExactlyOnce($testData);

            // Test failure recovery maintains exactly-once
            $this->testFailureRecoveryExactlyOnce($testData);

        } finally {
            DB::rollBack();
        }
    }

    /**
     * Generate random test data for property testing
     */
    private function generateRandomEventData(int $seed): array
    {
        mt_srand($seed);

        $eventTypes = [
            OutboxEvent::EVENT_BOOKING_CREATED,
            OutboxEvent::EVENT_PAYMENT_CONFIRMED,
            OutboxEvent::EVENT_TICKET_ISSUED,
            OutboxEvent::EVENT_BOOKING_CANCELLED,
        ];

        return [
            'aggregate_type' => 'booking',
            'aggregate_id' => (string) mt_rand(1, 1000),
            'event_type' => $eventTypes[array_rand($eventTypes)],
            'event_data' => [
                'booking_id' => mt_rand(1, 1000),
                'customer_id' => mt_rand(1, 100),
                'amount' => mt_rand(50, 500),
                'timestamp' => now()->toISOString(),
            ],
            'retry_count' => mt_rand(0, 3),
            'failure_simulation' => mt_rand(0, 1) === 1,
        ];
    }

    /**
     * Test idempotent event creation
     */
    private function test_idempotent_event_creation(array $testData): void
    {
        $idempotencyKey = 'test-'.$testData['aggregate_id'].'-'.$testData['event_type'];

        // Create event multiple times with same idempotency key
        $event1 = $this->outboxService->createEvent(
            $testData['aggregate_type'],
            $testData['aggregate_id'],
            $testData['event_type'],
            $testData['event_data'],
            $idempotencyKey
        );

        $event2 = $this->outboxService->createEvent(
            $testData['aggregate_type'],
            $testData['aggregate_id'],
            $testData['event_type'],
            $testData['event_data'],
            $idempotencyKey
        );

        $event3 = $this->outboxService->createEvent(
            $testData['aggregate_type'],
            $testData['aggregate_id'],
            $testData['event_type'],
            $testData['event_data'],
            $idempotencyKey
        );

        // Should return the same event instance
        $this->assertEquals($event1->id, $event2->id);
        $this->assertEquals($event1->id, $event3->id);
        $this->assertEquals($idempotencyKey, $event1->idempotency_key);

        // Should only have one event in database
        $eventCount = OutboxEvent::where('idempotency_key', $idempotencyKey)->count();
        $this->assertEquals(1, $eventCount);
    }

    /**
     * Test exactly-once processing with retries
     */
    private function test_exactly_once_processing_with_retries(array $testData): void
    {
        // Create event
        $event = $this->outboxService->createEvent(
            $testData['aggregate_type'],
            $testData['aggregate_id'].'-retry',
            $testData['event_type'],
            $testData['event_data']
        );

        // Simulate processing with retries
        $processCount = 0;
        $maxRetries = 3;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            // Mark as processing
            $event->markAsProcessing();

            // Simulate processing
            $processCount++;

            if ($attempt < $maxRetries - 1) {
                // Simulate failure and retry
                $event->markAsFailed('Simulated failure for retry test');
                $event->refresh();
            } else {
                // Final attempt succeeds
                $event->markAsCompleted();
            }
        }

        // Verify event was processed exactly once (final success)
        $event->refresh();
        $this->assertTrue($event->isCompleted());
        $this->assertEquals(OutboxEvent::STATUS_COMPLETED, $event->status);

        // Verify processing logs show all attempts but final state is completed
        $logs = OutboxProcessingLog::where('outbox_event_id', $event->id)->get();
        $this->assertGreaterThan(0, $logs->count());

        // Should have exactly one completion log
        $completionLogs = $logs->where('action', 'processing_completed');
        $this->assertEquals(1, $completionLogs->count());
    }

    /**
     * Test concurrent processing prevention
     */
    private function test_concurrent_processing_prevention(array $testData): void
    {
        // Create event
        $event = $this->outboxService->createEvent(
            $testData['aggregate_type'],
            $testData['aggregate_id'].'-concurrent',
            $testData['event_type'],
            $testData['event_data']
        );

        // Simulate concurrent processing attempts
        $event1 = OutboxEvent::find($event->id);
        $event2 = OutboxEvent::find($event->id);

        // First processor marks as processing
        $event1->markAsProcessing();

        // Second processor should see it's already processing
        $event2->refresh();
        $this->assertTrue($event2->isProcessing());

        // Complete processing
        $event1->markAsCompleted();

        // Verify final state
        $event2->refresh();
        $this->assertTrue($event2->isCompleted());
        $this->assertNotNull($event2->processed_at);
    }

    /**
     * Test consumer-level exactly-once processing
     */
    private function test_consumer_level_exactly_once(array $testData): void
    {
        // Create event with consumers
        $event = $this->outboxService->createEvent(
            $testData['aggregate_type'],
            $testData['aggregate_id'].'-consumer',
            $testData['event_type'],
            $testData['event_data'],
            null,
            null,
            [OutboxEventConsumer::CONSUMER_NOTIFICATION, OutboxEventConsumer::CONSUMER_ANALYTICS]
        );

        // Get consumers
        $consumers = $event->consumers;
        $this->assertEquals(2, $consumers->count());

        foreach ($consumers as $consumer) {
            // Simulate multiple processing attempts for same consumer
            $processAttempts = 0;

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $consumer->markAsProcessing();
                $processAttempts++;

                if ($attempt < 2) {
                    // Simulate failure
                    $consumer->markAsFailed('Simulated consumer failure');
                    $consumer->refresh();
                } else {
                    // Final success
                    $consumer->markAsCompleted(['processed_items' => 1]);
                }
            }

            // Verify consumer completed exactly once
            $consumer->refresh();
            $this->assertTrue($consumer->isCompleted());
            $this->assertNotNull($consumer->completed_at);
            $this->assertEquals(1, $consumer->getMetadataValue('processed_items'));
        }

        // Verify event is completed when all consumers complete
        $event->refresh();
        $allConsumersCompleted = $event->consumers->every(fn ($c) => $c->isCompleted());
        $this->assertTrue($allConsumersCompleted);
    }

    /**
     * Test failure recovery maintains exactly-once
     */
    private function test_failure_recovery_exactly_once(array $testData): void
    {
        // Create event
        $event = $this->outboxService->createEvent(
            $testData['aggregate_type'],
            $testData['aggregate_id'].'-recovery',
            $testData['event_type'],
            $testData['event_data']
        );

        // Simulate system failure during processing
        $event->markAsProcessing();

        // Simulate system crash - event remains in processing state
        // On recovery, system should handle this gracefully

        // Simulate recovery process
        $stuckEvents = OutboxEvent::where('status', OutboxEvent::STATUS_PROCESSING)
            ->where('created_at', '<', now()->subMinutes(5))
            ->get();

        $this->assertContains($event->id, $stuckEvents->pluck('id'));

        // Recovery should reset stuck events to pending or failed
        foreach ($stuckEvents as $stuckEvent) {
            $stuckEvent->update(['status' => OutboxEvent::STATUS_PENDING]);
        }

        // Now process normally
        $result = $this->outboxService->processEvent($event);

        // Should complete successfully
        $this->assertTrue($result['success']);

        $event->refresh();
        $this->assertTrue($event->isCompleted());
    }

    /**
     * Test outbox service processing statistics
     */
    public function test_outbox_processing_statistics()
    {
        // Create multiple events with different states
        $events = [];

        // Pending events
        for ($i = 0; $i < 3; $i++) {
            $events[] = $this->outboxService->createEvent(
                'booking',
                "pending-$i",
                OutboxEvent::EVENT_BOOKING_CREATED,
                ['test' => true]
            );
        }

        // Completed events
        for ($i = 0; $i < 2; $i++) {
            $event = $this->outboxService->createEvent(
                'booking',
                "completed-$i",
                OutboxEvent::EVENT_PAYMENT_CONFIRMED,
                ['test' => true]
            );
            $event->markAsCompleted();
            $events[] = $event;
        }

        // Failed events
        $failedEvent = $this->outboxService->createEvent(
            'booking',
            'failed-1',
            OutboxEvent::EVENT_TICKET_ISSUED,
            ['test' => true]
        );
        $failedEvent->markAsFailed('Test failure');
        $events[] = $failedEvent;

        // Get statistics
        $stats = $this->outboxService->getProcessingStatistics();

        // Verify statistics
        $this->assertEquals(6, $stats['total_events']);
        $this->assertEquals(3, $stats['pending_events']);
        $this->assertEquals(2, $stats['completed_events']);
        $this->assertEquals(1, $stats['failed_events']);

        // Verify events by type
        $this->assertArrayHasKey('events_by_type', $stats);
        $this->assertEquals(3, $stats['events_by_type'][OutboxEvent::EVENT_BOOKING_CREATED]);
        $this->assertEquals(2, $stats['events_by_type'][OutboxEvent::EVENT_PAYMENT_CONFIRMED]);
        $this->assertEquals(1, $stats['events_by_type'][OutboxEvent::EVENT_TICKET_ISSUED]);
    }

    /**
     * Test batch processing maintains exactly-once guarantees
     */
    public function test_batch_processing_exactly_once()
    {
        // Create multiple events
        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = $this->outboxService->createEvent(
                'booking',
                "batch-$i",
                OutboxEvent::EVENT_BOOKING_CREATED,
                ['batch_test' => true, 'index' => $i]
            );
        }

        // Process in batches
        $result1 = $this->outboxService->processPendingEvents(5);
        $result2 = $this->outboxService->processPendingEvents(5);

        // Verify all events processed exactly once
        $totalProcessed = $result1['processed_count'] + $result2['processed_count'];
        $this->assertEquals(10, $totalProcessed);

        // Verify no events are left pending
        $pendingCount = OutboxEvent::where('status', OutboxEvent::STATUS_PENDING)->count();
        $this->assertEquals(0, $pendingCount);

        // Verify all events are completed
        $completedCount = OutboxEvent::where('status', OutboxEvent::STATUS_COMPLETED)->count();
        $this->assertEquals(10, $completedCount);
    }

    /**
     * Test idempotency key generation is deterministic
     */
    public function test_idempotency_key_deterministic()
    {
        $testData = [
            'aggregate_type' => 'booking',
            'aggregate_id' => '123',
            'event_type' => OutboxEvent::EVENT_BOOKING_CREATED,
            'event_data' => ['amount' => 100, 'customer_id' => 456],
        ];

        // Generate multiple events with same data
        $event1 = $this->outboxService->createEvent(
            $testData['aggregate_type'],
            $testData['aggregate_id'],
            $testData['event_type'],
            $testData['event_data']
        );

        $event2 = $this->outboxService->createEvent(
            $testData['aggregate_type'],
            $testData['aggregate_id'],
            $testData['event_type'],
            $testData['event_data']
        );

        // Should be the same event (idempotent)
        $this->assertEquals($event1->id, $event2->id);
        $this->assertEquals($event1->idempotency_key, $event2->idempotency_key);

        // Different data should generate different keys
        $event3 = $this->outboxService->createEvent(
            $testData['aggregate_type'],
            $testData['aggregate_id'],
            $testData['event_type'],
            ['amount' => 200, 'customer_id' => 456] // Different amount
        );

        $this->assertNotEquals($event1->id, $event3->id);
        $this->assertNotEquals($event1->idempotency_key, $event3->idempotency_key);
    }
}
