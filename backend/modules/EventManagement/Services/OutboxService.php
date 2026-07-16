<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Models\OutboxEvent;
use Modules\EventManagement\Models\OutboxEventConsumer;
use Modules\EventManagement\Models\OutboxProcessingLog;

/**
 * OutboxService
 *
 * Implements transactional outbox pattern for exactly-once side effects processing.
 * Ensures that side effects are processed exactly once even in case of failures.
 */
class OutboxService
{
    // Default consumers for different event types
    private const EVENT_CONSUMERS = [
        OutboxEvent::EVENT_BOOKING_CREATED => [
            OutboxEventConsumer::CONSUMER_NOTIFICATION,
            OutboxEventConsumer::CONSUMER_ANALYTICS,
            OutboxEventConsumer::CONSUMER_FRAUD_DETECTION,
        ],
        OutboxEvent::EVENT_PAYMENT_CONFIRMED => [
            OutboxEventConsumer::CONSUMER_NOTIFICATION,
            OutboxEventConsumer::CONSUMER_ANALYTICS,
            OutboxEventConsumer::CONSUMER_ACCOUNTING,
            OutboxEventConsumer::CONSUMER_LOYALTY,
        ],
        OutboxEvent::EVENT_TICKET_ISSUED => [
            OutboxEventConsumer::CONSUMER_NOTIFICATION,
            OutboxEventConsumer::CONSUMER_ANALYTICS,
        ],
        OutboxEvent::EVENT_BOOKING_CANCELLED => [
            OutboxEventConsumer::CONSUMER_NOTIFICATION,
            OutboxEventConsumer::CONSUMER_ANALYTICS,
            OutboxEventConsumer::CONSUMER_ACCOUNTING,
        ],
        OutboxEvent::EVENT_FRAUD_DETECTED => [
            OutboxEventConsumer::CONSUMER_NOTIFICATION,
            OutboxEventConsumer::CONSUMER_FRAUD_DETECTION,
        ],
        OutboxEvent::EVENT_TIER_UPGRADED => [
            OutboxEventConsumer::CONSUMER_NOTIFICATION,
            OutboxEventConsumer::CONSUMER_LOYALTY,
        ],
    ];

    /**
     * Create an outbox event within a transaction
     * This ensures the event is created atomically with the business operation
     */
    public function createEvent(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $eventData,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
        ?array $customConsumers = null
    ): OutboxEvent {
        return DB::transaction(function () use (
            $aggregateType, $aggregateId, $eventType, $eventData,
            $idempotencyKey, $metadata, $customConsumers
        ) {
            // Generate idempotency key if not provided
            $idempotencyKey = $idempotencyKey ?? $this->generateIdempotencyKey(
                $aggregateType, $aggregateId, $eventType, $eventData
            );

            // Check if event already exists (idempotency)
            $existingEvent = OutboxEvent::where('idempotency_key', $idempotencyKey)->first();
            if ($existingEvent) {
                Log::info('Outbox event already exists, returning existing event', [
                    'idempotency_key' => $idempotencyKey,
                    'event_id' => $existingEvent->id,
                ]);

                return $existingEvent;
            }

            // Create the outbox event
            $outboxEvent = OutboxEvent::create([
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'event_type' => $eventType,
                'event_data' => $eventData,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata ?? [],
            ]);

            // Create consumer records
            $consumers = $customConsumers ?? $this->getConsumersForEventType($eventType);
            foreach ($consumers as $consumerName) {
                OutboxEventConsumer::createForEvent($outboxEvent->id, $consumerName);
            }

            // Log event creation
            $this->logProcessingAction(
                $outboxEvent->id,
                null,
                'created',
                'Outbox event created',
                [
                    'aggregate_type' => $aggregateType,
                    'aggregate_id' => $aggregateId,
                    'event_type' => $eventType,
                    'consumers' => $consumers,
                ]
            );

            Log::info('Outbox event created', [
                'event_id' => $outboxEvent->id,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'event_type' => $eventType,
                'consumers' => $consumers,
            ]);

            return $outboxEvent;
        });
    }

    /**
     * Process pending outbox events
     */
    public function processPendingEvents(int $batchSize = 100): array
    {
        $processedEvents = [];
        $failedEvents = [];

        // Get events ready for processing
        $events = OutboxEvent::readyForProcessing()
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();

        foreach ($events as $event) {
            try {
                $result = $this->processEvent($event);
                if ($result['success']) {
                    $processedEvents[] = $event->id;
                } else {
                    $failedEvents[] = [
                        'event_id' => $event->id,
                        'error' => $result['error'],
                    ];
                }
            } catch (\Exception $e) {
                $failedEvents[] = [
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ];

                Log::error('Failed to process outbox event', [
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return [
            'processed_count' => count($processedEvents),
            'failed_count' => count($failedEvents),
            'processed_events' => $processedEvents,
            'failed_events' => $failedEvents,
        ];
    }

    /**
     * Process a single outbox event
     */
    public function processEvent(OutboxEvent $event): array
    {
        return DB::transaction(function () use ($event) {
            // Mark event as processing
            $event->markAsProcessing();

            $this->logProcessingAction(
                $event->id,
                null,
                'processing_started',
                'Event processing started'
            );

            try {
                // Get pending consumers for this event
                $pendingConsumers = $event->consumers()
                    ->readyForProcessing()
                    ->get();

                $allConsumersSucceeded = true;
                $consumerResults = [];

                foreach ($pendingConsumers as $consumer) {
                    $result = $this->processConsumer($consumer);
                    $consumerResults[$consumer->consumer_name] = $result;

                    if (! $result['success']) {
                        $allConsumersSucceeded = false;
                    }
                }

                // Update event status based on consumer results
                if ($allConsumersSucceeded) {
                    $event->markAsCompleted();

                    $this->logProcessingAction(
                        $event->id,
                        null,
                        'processing_completed',
                        'All consumers processed successfully',
                        ['consumer_results' => $consumerResults]
                    );

                    return [
                        'success' => true,
                        'consumer_results' => $consumerResults,
                    ];
                } else {
                    $errorMessage = 'One or more consumers failed processing';
                    $event->markAsFailed($errorMessage);

                    $this->logProcessingAction(
                        $event->id,
                        null,
                        'processing_failed',
                        $errorMessage,
                        ['consumer_results' => $consumerResults]
                    );

                    return [
                        'success' => false,
                        'error' => $errorMessage,
                        'consumer_results' => $consumerResults,
                    ];
                }

            } catch (\Exception $e) {
                $event->markAsFailed($e->getMessage());

                $this->logProcessingAction(
                    $event->id,
                    null,
                    'processing_failed',
                    'Event processing failed with exception: '.$e->getMessage(),
                    ['exception' => $e->getTraceAsString()]
                );

                throw $e;
            }
        });
    }

    /**
     * Process a single consumer
     */
    private function processConsumer(OutboxEventConsumer $consumer): array
    {
        try {
            $consumer->markAsProcessing();

            $this->logProcessingAction(
                $consumer->outbox_event_id,
                $consumer->consumer_name,
                'processing_started',
                'Consumer processing started'
            );

            // Delegate to specific consumer processor
            $result = $this->delegateToConsumer($consumer);

            if ($result['success']) {
                $consumer->markAsCompleted($result['metadata'] ?? null);

                $this->logProcessingAction(
                    $consumer->outbox_event_id,
                    $consumer->consumer_name,
                    'processing_completed',
                    'Consumer processing completed successfully',
                    $result['metadata'] ?? []
                );
            } else {
                $consumer->markAsFailed($result['error']);

                $this->logProcessingAction(
                    $consumer->outbox_event_id,
                    $consumer->consumer_name,
                    'processing_failed',
                    'Consumer processing failed: '.$result['error']
                );
            }

            return $result;

        } catch (\Exception $e) {
            $consumer->markAsFailed($e->getMessage());

            $this->logProcessingAction(
                $consumer->outbox_event_id,
                $consumer->consumer_name,
                'processing_failed',
                'Consumer processing failed with exception: '.$e->getMessage(),
                ['exception' => $e->getTraceAsString()]
            );

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Delegate processing to specific consumer service
     */
    private function delegateToConsumer(OutboxEventConsumer $consumer): array
    {
        $event = $consumer->outboxEvent;

        switch ($consumer->consumer_name) {
            case OutboxEventConsumer::CONSUMER_NOTIFICATION:
                return $this->processNotificationConsumer($event);

            case OutboxEventConsumer::CONSUMER_ANALYTICS:
                return $this->processAnalyticsConsumer($event);

            case OutboxEventConsumer::CONSUMER_ACCOUNTING:
                return $this->processAccountingConsumer($event);

            case OutboxEventConsumer::CONSUMER_LOYALTY:
                return $this->processLoyaltyConsumer($event);

            case OutboxEventConsumer::CONSUMER_FRAUD_DETECTION:
                return $this->processFraudDetectionConsumer($event);

            default:
                return [
                    'success' => false,
                    'error' => 'Unknown consumer: '.$consumer->consumer_name,
                ];
        }
    }

    /**
     * Process notification consumer
     */
    private function processNotificationConsumer(OutboxEvent $event): array
    {
        // This would integrate with NotificationService
        // For now, simulate processing
        Log::info('Processing notification consumer', [
            'event_id' => $event->id,
            'event_type' => $event->event_type,
        ]);

        return [
            'success' => true,
            'metadata' => [
                'processed_at' => now()->toISOString(),
                'notification_type' => $this->getNotificationTypeForEvent($event->event_type),
            ],
        ];
    }

    /**
     * Process analytics consumer
     */
    private function processAnalyticsConsumer(OutboxEvent $event): array
    {
        // This would integrate with EventAnalyticsService
        Log::info('Processing analytics consumer', [
            'event_id' => $event->id,
            'event_type' => $event->event_type,
        ]);

        return [
            'success' => true,
            'metadata' => [
                'processed_at' => now()->toISOString(),
                'analytics_updated' => true,
            ],
        ];
    }

    /**
     * Process accounting consumer
     */
    private function processAccountingConsumer(OutboxEvent $event): array
    {
        // This would integrate with accounting system
        Log::info('Processing accounting consumer', [
            'event_id' => $event->id,
            'event_type' => $event->event_type,
        ]);

        return [
            'success' => true,
            'metadata' => [
                'processed_at' => now()->toISOString(),
                'accounting_entry_created' => true,
            ],
        ];
    }

    /**
     * Process loyalty consumer
     */
    private function processLoyaltyConsumer(OutboxEvent $event): array
    {
        // This would integrate with LoyaltyService
        Log::info('Processing loyalty consumer', [
            'event_id' => $event->id,
            'event_type' => $event->event_type,
        ]);

        return [
            'success' => true,
            'metadata' => [
                'processed_at' => now()->toISOString(),
                'loyalty_points_awarded' => $event->getEventDataValue('loyalty_points') ?? 0,
            ],
        ];
    }

    /**
     * Process fraud detection consumer
     */
    private function processFraudDetectionConsumer(OutboxEvent $event): array
    {
        // This would integrate with FraudDetectionService
        Log::info('Processing fraud detection consumer', [
            'event_id' => $event->id,
            'event_type' => $event->event_type,
        ]);

        return [
            'success' => true,
            'metadata' => [
                'processed_at' => now()->toISOString(),
                'fraud_analysis_completed' => true,
            ],
        ];
    }

    /**
     * Generate deterministic idempotency key
     */
    private function generateIdempotencyKey(string $aggregateType, string $aggregateId, string $eventType, array $eventData): string
    {
        $dataHash = hash('sha256', json_encode($eventData));

        return hash('sha256', $aggregateType.':'.$aggregateId.':'.$eventType.':'.$dataHash);
    }

    /**
     * Get consumers for event type
     */
    private function getConsumersForEventType(string $eventType): array
    {
        return self::EVENT_CONSUMERS[$eventType] ?? [];
    }

    /**
     * Get notification type for event
     */
    private function getNotificationTypeForEvent(string $eventType): string
    {
        return match ($eventType) {
            OutboxEvent::EVENT_BOOKING_CREATED => 'booking_confirmation',
            OutboxEvent::EVENT_PAYMENT_CONFIRMED => 'payment_confirmation',
            OutboxEvent::EVENT_TICKET_ISSUED => 'ticket_delivery',
            OutboxEvent::EVENT_BOOKING_CANCELLED => 'booking_cancellation',
            OutboxEvent::EVENT_FRAUD_DETECTED => 'fraud_alert',
            OutboxEvent::EVENT_TIER_UPGRADED => 'tier_upgrade_notification',
            default => 'general_notification'
        };
    }

    /**
     * Log processing action
     */
    private function logProcessingAction(
        int $outboxEventId,
        ?string $consumerName,
        string $action,
        string $message,
        ?array $context = null
    ): void {
        OutboxProcessingLog::create([
            'outbox_event_id' => $outboxEventId,
            'consumer_name' => $consumerName,
            'action' => $action,
            'message' => $message,
            'context' => $context,
        ]);
    }

    /**
     * Get processing statistics
     */
    public function getProcessingStatistics(): array
    {
        return [
            'total_events' => OutboxEvent::count(),
            'pending_events' => OutboxEvent::where('status', OutboxEvent::STATUS_PENDING)->count(),
            'processing_events' => OutboxEvent::where('status', OutboxEvent::STATUS_PROCESSING)->count(),
            'completed_events' => OutboxEvent::where('status', OutboxEvent::STATUS_COMPLETED)->count(),
            'failed_events' => OutboxEvent::where('status', OutboxEvent::STATUS_FAILED)->count(),
            'events_by_type' => OutboxEvent::selectRaw('event_type, COUNT(*) as count')
                ->groupBy('event_type')
                ->pluck('count', 'event_type')
                ->toArray(),
            'consumer_statistics' => OutboxEventConsumer::selectRaw('consumer_name, status, COUNT(*) as count')
                ->groupBy('consumer_name', 'status')
                ->get()
                ->groupBy('consumer_name')
                ->map(function ($consumers) {
                    return $consumers->pluck('count', 'status')->toArray();
                })
                ->toArray(),
        ];
    }
}
