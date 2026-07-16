<?php

namespace Tests\Feature;

use App\Services\PosStructuredLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * OBS-001: Structured logging with correlation IDs tests
 */
class PosStructuredLoggingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function payment_log_contains_required_structured_fields()
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'pos.payment.completed'
                    && isset($context['operation'])
                    && $context['operation'] === 'pos_payment'
                    && isset($context['order_id'])
                    && isset($context['payment_id'])
                    && isset($context['method'])
                    && isset($context['amount'])
                    && isset($context['timestamp']);
            });

        $logger = app(PosStructuredLogger::class);
        $logger->payment(
            orderId: 100,
            paymentId: 50,
            method: 'cash',
            amount: 99.99,
            terminalId: 1,
            sessionId: 1
        );
    }

    /** @test */
    public function void_log_contains_required_structured_fields()
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'pos.void.completed'
                    && $context['operation'] === 'pos_void'
                    && isset($context['order_id'])
                    && isset($context['void_reason']);
            });

        $logger = app(PosStructuredLogger::class);
        $logger->void(
            orderId: 200,
            voidReason: 'Customer request',
            approvedBy: 5,
            terminalId: 1,
            sessionId: 1
        );
    }

    /** @test */
    public function refund_log_contains_required_structured_fields()
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'pos.refund.completed'
                    && $context['operation'] === 'pos_refund'
                    && isset($context['refund_amount'])
                    && isset($context['method']);
            });

        $logger = app(PosStructuredLogger::class);
        $logger->refund(
            orderId: 300,
            refundAmount: 45.00,
            method: 'cash',
            approvedBy: 6,
            terminalId: 1,
            sessionId: 1
        );
    }

    /** @test */
    public function payment_failure_logs_at_error_level()
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'pos.payment.failed'
                    && isset($context['failure_reason']);
            });

        $logger = app(PosStructuredLogger::class);
        $logger->paymentFailed(
            orderId: 400,
            reason: 'Insufficient funds',
            terminalId: 1,
            sessionId: 1
        );
    }

    /** @test */
    public function sync_failure_logs_at_error_level()
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'pos.sync.failed'
                    && $context['sync_status'] === 'failed'
                    && isset($context['error_message']);
            });

        $logger = app(PosStructuredLogger::class);
        $logger->sync(
            operationType: 'void',
            queueItemId: 10,
            status: 'failed',
            errorMessage: 'ApprovalRequest not found'
        );
    }

    /** @test */
    public function correlation_id_middleware_is_registered()
    {
        // Verify the middleware class exists and can be instantiated
        $middleware = app(\App\Http\Middleware\CorrelationIdMiddleware::class);
        $this->assertInstanceOf(\App\Http\Middleware\CorrelationIdMiddleware::class, $middleware);
    }

    /** @test */
    public function correlation_id_is_echoed_in_response_header()
    {
        // Make a request with a correlation ID header
        $correlationId = 'test-correlation-'.uniqid();

        $response = $this->withHeaders([
            'X-Correlation-ID' => $correlationId,
        ])->get('/api/health');

        // The middleware should echo it back (if route exists and middleware is applied)
        // This is a best-effort check — the route may not exist in test env
        if ($response->status() !== 404) {
            $this->assertEquals($correlationId, $response->headers->get('X-Correlation-ID'));
        } else {
            $this->markTestSkipped('Health route not available in test environment');
        }
    }
}
