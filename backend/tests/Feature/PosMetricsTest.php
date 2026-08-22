<?php

namespace Tests\Feature;

use App\Services\PosMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TenantTestCase;

/**
 * OBS-002: Metrics emission and alert threshold tests
 */
class PosMetricsTest extends TenantTestCase
{

    private PosMetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = app(PosMetricsService::class);
        Cache::flush();
    }

    /** @test */
    public function payment_completed_emits_metric()
    {
        Log::shouldReceive('debug')
            ->atLeast()->once()
            ->withArgs(function ($message, $context) {
                return $message === 'pos.metric'
                    && $context['metric_name'] === 'pos.payment.completed';
            });

        $this->metrics->paymentCompleted('cash', 99.99);
    }

    /** @test */
    public function payment_failure_increments_counter()
    {
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('critical')->zeroOrMoreTimes();

        $this->metrics->paymentFailed('card', 'declined');

        $count = Cache::get('pos.metrics.pos.payment.failure', 0);
        $this->assertGreaterThan(0, $count, 'pos.payment.failure counter must be incremented');
    }

    /** @test */
    public function sync_failure_increments_counter()
    {
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('critical')->zeroOrMoreTimes();

        $this->metrics->syncFailed('void', 'ApprovalRequest not found');

        $count = Cache::get('pos.metrics.pos.sync.failure', 0);
        $this->assertGreaterThan(0, $count, 'pos.sync.failure counter must be incremented');
    }

    /** @test */
    public function void_completed_emits_metric()
    {
        Log::shouldReceive('debug')
            ->atLeast()->once()
            ->withArgs(function ($message, $context) {
                return $message === 'pos.metric'
                    && $context['metric_name'] === 'pos.void.completed';
            });

        $this->metrics->voidCompleted();
    }

    /** @test */
    public function refund_completed_emits_metric_with_amount()
    {
        Log::shouldReceive('debug')
            ->atLeast()->once()
            ->withArgs(function ($message, $context) {
                return $message === 'pos.metric'
                    && in_array($context['metric_name'], ['pos.refund.completed', 'pos.refund.amount']);
            });

        $this->metrics->refundCompleted(45.00);
    }

    /** @test */
    public function gl_posting_failure_emits_metric()
    {
        Log::shouldReceive('debug')
            ->atLeast()->once()
            ->withArgs(function ($message, $context) {
                return $message === 'pos.metric'
                    && $context['metric_name'] === 'pos.gl.posting_failure';
            });

        $this->metrics->glPostingFailed('pos_order', 'PostingEngine exception');
    }

    /** @test */
    public function alert_threshold_config_exists()
    {
        $this->assertNotNull(config('monitoring.pos.payment_failure_rate_threshold'));
        $this->assertNotNull(config('monitoring.pos.sync_failure_rate_threshold'));
        $this->assertNotNull(config('monitoring.pos.sync_queue_depth_threshold'));
        $this->assertNotNull(config('monitoring.pos.gl_posting_failure_threshold'));
    }

    /** @test */
    public function payment_failure_rate_threshold_is_one_percent()
    {
        $threshold = config('monitoring.pos.payment_failure_rate_threshold');
        $this->assertEquals(0.01, $threshold,
            'Payment failure rate alert threshold must be 1%');
    }

    /** @test */
    public function sync_failure_rate_threshold_is_five_percent()
    {
        $threshold = config('monitoring.pos.sync_failure_rate_threshold');
        $this->assertEquals(0.05, $threshold,
            'Sync failure rate alert threshold must be 5%');
    }

    /** @test */
    public function sync_queue_depth_threshold_is_one_hundred()
    {
        $threshold = config('monitoring.pos.sync_queue_depth_threshold');
        $this->assertEquals(100, $threshold,
            'Sync queue depth alert threshold must be 100');
    }
}
