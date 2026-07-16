<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Modules\POS\Models\PosOfflineQueue;
use Tests\TenantTestCase;

class PosOfflineQueueStateMachineTest extends TenantTestCase
{
    private function createQueue(array $attributes): PosOfflineQueue
    {
        return PosOfflineQueue::create(array_merge([
            'idempotency_key' => (string) Str::uuid(),
        ], $attributes));
    }

    /**
     * Test STATE-002: Valid transition pending → syncing succeeds
     */
    public function test_valid_transition_pending_to_syncing_succeeds()
    {
        $queue = $this->createQueue([
            'operation_type' => 'create_order',
            'status' => 'pending',
            'payload' => ['test' => 'data'],
        ]);

        $this->assertTrue($queue->canTransitionTo('syncing'));

        $queue->transitionTo('syncing');
        $queue->save();

        $this->assertEquals('syncing', $queue->status);
        $this->assertNotNull($queue->last_sync_attempt);
        $this->assertEquals(1, $queue->sync_attempts);
    }

    /**
     * Test STATE-002: Valid transition syncing → synced succeeds
     */
    public function test_valid_transition_syncing_to_synced_succeeds()
    {
        $queue = $this->createQueue([
            'operation_type' => 'create_order',
            'status' => 'pending',
            'payload' => ['test' => 'data'],
        ]);

        $queue->transitionTo('syncing');
        $queue->save();

        $this->assertTrue($queue->canTransitionTo('synced'));

        $queue->transitionTo('synced');
        $queue->save();

        $this->assertEquals('synced', $queue->status);
        $this->assertNotNull($queue->synced_at);
    }

    /**
     * Test STATE-002: Valid transition pending → failed succeeds
     */
    public function test_valid_transition_pending_to_failed_succeeds()
    {
        $queue = $this->createQueue([
            'operation_type' => 'create_order',
            'status' => 'pending',
            'payload' => ['test' => 'data'],
        ]);

        $this->assertTrue($queue->canTransitionTo('failed'));

        $queue->transitionTo('failed');
        $queue->save();

        $this->assertEquals('failed', $queue->status);
    }

    /**
     * Test STATE-002: Valid transition failed → pending succeeds (retry)
     */
    public function test_valid_transition_failed_to_pending_succeeds()
    {
        $queue = $this->createQueue([
            'operation_type' => 'create_order',
            'status' => 'failed',
            'payload' => ['test' => 'data'],
        ]);

        $this->assertTrue($queue->canTransitionTo('pending'));

        $queue->transitionTo('pending');
        $queue->save();

        $this->assertEquals('pending', $queue->status);
    }

    /**
     * Test STATE-002: Valid transition pending → conflict succeeds
     */
    public function test_valid_transition_pending_to_conflict_succeeds()
    {
        $queue = $this->createQueue([
            'operation_type' => 'create_order',
            'status' => 'pending',
            'payload' => ['test' => 'data'],
        ]);

        $this->assertTrue($queue->canTransitionTo('conflict'));

        $queue->transitionTo('conflict');
        $queue->save();

        $this->assertEquals('conflict', $queue->status);
    }

    /**
     * Test STATE-002: Valid transition conflict → pending succeeds (resolve)
     */
    public function test_valid_transition_conflict_to_pending_succeeds()
    {
        $queue = $this->createQueue([
            'operation_type' => 'create_order',
            'status' => 'conflict',
            'payload' => ['test' => 'data'],
        ]);

        $this->assertTrue($queue->canTransitionTo('pending'));

        $queue->transitionTo('pending');
        $queue->save();

        $this->assertEquals('pending', $queue->status);
    }

    /**
     * Test STATE-002: Invalid transition - syncing already-synced item throws exception
     */
    public function test_invalid_transition_synced_to_syncing_throws_exception()
    {
        $queue = $this->createQueue([
            'operation_type' => 'create_order',
            'status' => 'synced',
            'payload' => ['test' => 'data'],
        ]);

        $this->assertFalse($queue->canTransitionTo('syncing'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid state transition from 'synced' to 'syncing'");

        $queue->transitionTo('syncing');
    }

    /**
     * Test STATE-002: Invalid transition - resolving non-conflict item throws exception
     */
    public function test_invalid_transition_pending_to_synced_throws_exception()
    {
        $queue = $this->createQueue([
            'operation_type' => 'create_order',
            'status' => 'pending',
            'payload' => ['test' => 'data'],
        ]);

        $this->assertFalse($queue->canTransitionTo('synced'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid state transition from 'pending' to 'synced'");

        $queue->transitionTo('synced');
    }

    /**
     * Test STATE-002: Synced item cannot transition to any state
     */
    public function test_synced_item_cannot_transition()
    {
        $queue = $this->createQueue([
            'operation_type' => 'create_order',
            'status' => 'synced',
            'payload' => ['test' => 'data'],
        ]);

        $this->assertFalse($queue->canTransitionTo('pending'));
        $this->assertFalse($queue->canTransitionTo('syncing'));
        $this->assertFalse($queue->canTransitionTo('failed'));
        $this->assertFalse($queue->canTransitionTo('conflict'));
    }

    /**
     * Test STATE-002: Sync attempts increment on syncing transition
     */
    public function test_sync_attempts_increment_on_syncing_transition()
    {
        $queue = $this->createQueue([
            'operation_type' => 'create_order',
            'status' => 'pending',
            'payload' => ['test' => 'data'],
        ]);

        $this->assertEquals(0, $queue->sync_attempts ?? 0);

        $queue->transitionTo('syncing');
        $queue->save();

        $this->assertEquals(1, $queue->sync_attempts);

        // Retry
        $queue->status = 'failed';
        $queue->save();

        $queue->transitionTo('syncing');
        $queue->save();

        $this->assertEquals(2, $queue->sync_attempts);
    }

    /**
     * Test STATE-002: Synced_at timestamp set on synced transition
     */
    public function test_synced_at_timestamp_set_on_synced_transition()
    {
        $queue = $this->createQueue([
            'operation_type' => 'create_order',
            'status' => 'pending',
            'payload' => ['test' => 'data'],
        ]);

        $this->assertNull($queue->synced_at);

        $queue->transitionTo('syncing');
        $queue->save();

        $queue->transitionTo('synced');
        $queue->save();

        $this->assertNotNull($queue->synced_at);
    }
}
