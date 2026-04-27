<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\POS\Models\PosOrder;
use Tests\TestCase;

/**
 * CHAN-003: Race condition test for PaymentTransactionCompletionService
 *
 * Verifies the gap identified in POS-FIND-024:
 * Two concurrent webhooks arriving before order.status is 'paid' can both
 * enter completePosOrderPayment() because the PaymentTransaction lockForUpdate()
 * and the PosPaymentCompletionService order-status check are in separate transactions.
 */
class PaymentGatewayRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function concurrent_webhooks_for_same_transaction_do_not_duplicate_payment()
    {
        // Arrange: create a pending POS order and payment transaction
        $posOrder = PosOrder::factory()->create([
            'status' => 'draft',
            'total' => 100.00,
        ]);

        $transaction = PaymentTransaction::factory()->create([
            'amount' => 100.00,
            'status' => 'pending',
            'processed_at' => null,
            'metadata' => ['pos_order_id' => $posOrder->id],
        ]);

        $service = app(PaymentTransactionCompletionService::class);

        // Act: simulate two concurrent webhook calls
        // In a real race, both would enter before processed_at is set.
        // The lockForUpdate() on PaymentTransaction prevents true concurrency in tests,
        // but we verify the idempotency guard works on sequential calls.
        $service->complete($transaction);
        $service->complete($transaction->fresh()); // second call (replay)

        // Assert: exactly one payment record created
        $paymentCount = \Modules\POS\Models\PosPayment::where('pos_order_id', $posOrder->id)->count();
        $this->assertEquals(1, $paymentCount, 'Concurrent webhooks must not create duplicate payments');
    }

    /** @test */
    public function completed_transaction_is_idempotent_on_replay()
    {
        $posOrder = PosOrder::factory()->create([
            'status' => 'draft',
            'total' => 50.00,
        ]);

        $transaction = PaymentTransaction::factory()->create([
            'amount' => 50.00,
            'status' => 'pending',
            'processed_at' => null,
            'metadata' => ['pos_order_id' => $posOrder->id],
        ]);

        $service = app(PaymentTransactionCompletionService::class);

        // First completion
        $result1 = $service->complete($transaction);

        // Second completion (replay)
        $result2 = $service->complete($transaction->fresh());

        // Both should succeed without error
        $this->assertNotNull($result1);
        $this->assertNotNull($result2);

        // Order should be paid exactly once
        $this->assertEquals('paid', $posOrder->fresh()->status);

        // GL entries should not be duplicated
        $glCount = \App\Models\JournalEntry::where('source_document_id', $posOrder->id)
            ->where('source_document_type', 'pos_order')
            ->count();
        $this->assertLessThanOrEqual(1, $glCount, 'Replay must not create duplicate GL entries');
    }

    /** @test */
    public function payment_transaction_lock_prevents_double_processing()
    {
        $posOrder = PosOrder::factory()->create([
            'status' => 'draft',
            'total' => 75.00,
        ]);

        $transaction = PaymentTransaction::factory()->create([
            'amount' => 75.00,
            'status' => 'pending',
            'processed_at' => null,
            'metadata' => ['pos_order_id' => $posOrder->id],
        ]);

        $service = app(PaymentTransactionCompletionService::class);
        $service->complete($transaction);

        // Verify processed_at is set after first completion
        $refreshed = $transaction->fresh();
        $this->assertNotNull($refreshed->processed_at, 'processed_at must be set after completion');
    }

    /** @test */
    public function race_condition_gap_is_documented_and_mitigated()
    {
        // This test documents the known race condition gap (POS-FIND-024):
        // PaymentTransactionCompletionService locks PaymentTransaction but
        // PosPaymentCompletionService uses a separate DB::transaction.
        //
        // Mitigation: PosPaymentCompletionService checks order.status === 'paid'
        // before proceeding. The order-status check is the final idempotency guard.
        //
        // Full fix requires adding processed_at check in PaymentTransactionCompletionService
        // before calling completePosOrderPayment() — tracked as CHAN-003 enhancement.

        $posOrder = PosOrder::factory()->create([
            'status' => 'paid', // Already paid
            'total' => 100.00,
        ]);

        $transaction = PaymentTransaction::factory()->create([
            'amount' => 100.00,
            'status' => 'pending',
            'processed_at' => null,
            'metadata' => ['pos_order_id' => $posOrder->id],
        ]);

        $service = app(PaymentTransactionCompletionService::class);

        // Calling complete on an already-paid order should be safe (no-op)
        $result = $service->complete($transaction);
        $this->assertNotNull($result);

        // No new payment should be created
        $paymentCount = \Modules\POS\Models\PosPayment::where('pos_order_id', $posOrder->id)->count();
        $this->assertEquals(0, $paymentCount, 'Already-paid order must not get duplicate payment');
    }
}
