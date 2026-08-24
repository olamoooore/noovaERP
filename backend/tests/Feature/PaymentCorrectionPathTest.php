<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GAP-004 Tests — Payment Correction Paths (ADR-005 §15.3, G3).
 *
 * Verifies that:
 * - Payment cancellation routes through PaymentCorrectionService
 * - PaymentCorrectionService creates GL reversals via PostingEngine
 * - Unapply payment creates GL reversal (not just subledger mutation)
 * - Settlement reversal creates compensating records (not hard-delete)
 */
class PaymentCorrectionPathTest extends TestCase
{
    /** @test */
    public function payment_controller_cancel_routes_through_correction_service(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\Api\PaymentController::class);
        $method = $reflection->getMethod('cancel');
        $source = file_get_contents($method->getFileName());

        // The cancel method should now route through PaymentCorrectionService
        $this->assertStringContainsString(
            'PaymentCorrectionService',
            $source,
            'PaymentController::cancel() must route through PaymentCorrectionService'
        );
        $this->assertStringContainsString(
            'unapplyPayment',
            $source,
            'PaymentController::cancel() must call PaymentCorrectionService::unapplyPayment()'
        );
    }

    /** @test */
    public function payment_correction_service_unapply_creates_gl_reversal(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PaymentCorrectionService::class);
        $method = $reflection->getMethod('unapplyPayment');
        $source = file_get_contents($method->getFileName());

        // unapplyPayment must create a GL reversal
        $this->assertStringContainsString(
            'PostingEngine',
            $source,
            'PaymentCorrectionService::unapplyPayment() must call PostingEngine for GL reversal'
        );
        $this->assertStringContainsString(
            'reverse(',
            $source,
            'PaymentCorrectionService::unapplyPayment() must call PostingEngine::reverse()'
        );
    }

    /** @test */
    public function payment_correction_service_uses_canonical_subledger_service(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PaymentCorrectionService::class);
        $source = file_get_contents($reflection->getFileName());

        // Should use lifecycleService for subledger updates, not direct ->update()
        $this->assertStringContainsString(
            'applyPaymentReversal',
            $source,
            'PaymentCorrectionService must use lifecycleService for invoice payment reversal'
        );
        $this->assertStringContainsString(
            'applyPaymentRefund',
            $source,
            'PaymentCorrectionService must use lifecycleService for invoice payment refund'
        );
    }

    /** @test */
    public function settlement_reversal_creates_compensating_records(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\Api\SettlementController::class);
        $method = $reflection->getMethod('reverse');
        $source = file_get_contents($method->getFileName());

        // Must NOT hard-delete allocations
        $this->assertStringNotContainsString(
            'Allocation::where',
            $source,
            'Settlement reversal must NOT hard-delete allocations'
        );

        // Must create compensating allocation records
        $this->assertStringContainsString(
            'compensating',
            $source,
            'Settlement reversal must create compensating allocation records'
        );
        $this->assertStringContainsString(
            'reversed_at',
            $source,
            'Settlement reversal must mark allocations as reversed'
        );
    }
}
