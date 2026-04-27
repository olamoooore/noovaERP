<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * OBS-002: Metrics emission for POS payment, sync, and void/refund operations.
 *
 * Emits counters and gauges that can be consumed by StatsD, Prometheus,
 * or any metrics backend. Falls back to cache-based counters when no
 * metrics backend is configured.
 */
class PosMetricsService
{
    /**
     * Increment a counter metric.
     */
    public function increment(string $metric, array $tags = []): void
    {
        $this->emit('increment', $metric, 1, $tags);
    }

    /**
     * Record a gauge value.
     */
    public function gauge(string $metric, float $value, array $tags = []): void
    {
        $this->emit('gauge', $metric, $value, $tags);
    }

    /**
     * Record a timing in milliseconds.
     */
    public function timing(string $metric, float $ms, array $tags = []): void
    {
        $this->emit('timing', $metric, $ms, $tags);
    }

    // --- POS-specific metric helpers ---

    public function paymentCompleted(string $method, float $amount): void
    {
        $this->increment('pos.payment.completed', ['method' => $method]);
        $this->gauge('pos.payment.amount', $amount, ['method' => $method]);
    }

    public function paymentFailed(string $method, string $reason): void
    {
        $this->increment('pos.payment.failure', ['method' => $method, 'reason' => $reason]);
        $this->checkPaymentFailureRateAlert();
    }

    public function voidCompleted(): void
    {
        $this->increment('pos.void.completed');
    }

    public function refundCompleted(float $amount): void
    {
        $this->increment('pos.refund.completed');
        $this->gauge('pos.refund.amount', $amount);
    }

    public function syncQueued(string $operationType): void
    {
        $this->increment('pos.sync.queued', ['operation' => $operationType]);
        $this->updateSyncQueueDepth();
    }

    public function syncCompleted(string $operationType): void
    {
        $this->increment('pos.sync.completed', ['operation' => $operationType]);
        $this->updateSyncQueueDepth();
    }

    public function syncFailed(string $operationType, string $reason): void
    {
        $this->increment('pos.sync.failure', ['operation' => $operationType, 'reason' => $reason]);
        $this->checkSyncFailureRateAlert();
    }

    public function glPostingFailed(string $documentType, string $reason): void
    {
        $this->increment('pos.gl.posting_failure', ['document_type' => $documentType, 'reason' => $reason]);
    }

    // --- Alert threshold checks ---

    /**
     * Alert if payment failure rate exceeds 1% in the last 5 minutes.
     * Threshold: pos.payment.failure_rate > 0.01
     */
    protected function checkPaymentFailureRateAlert(): void
    {
        $failures = (int) Cache::get('pos.metrics.payment.failures_5m', 0);
        $total = (int) Cache::get('pos.metrics.payment.total_5m', 1);

        Cache::increment('pos.metrics.payment.failures_5m');
        Cache::put('pos.metrics.payment.failures_5m',
            Cache::get('pos.metrics.payment.failures_5m', 0), now()->addMinutes(5));

        $rate = $total > 0 ? ($failures / $total) : 0;

        if ($rate > config('monitoring.pos.payment_failure_rate_threshold', 0.01)) {
            Log::critical('pos.alert.payment_failure_rate_exceeded', [
                'failure_rate' => $rate,
                'threshold' => 0.01,
                'failures_5m' => $failures,
                'total_5m' => $total,
            ]);
        }
    }

    /**
     * Alert if sync failure rate exceeds 5%.
     * Threshold: pos.sync.failure_rate > 0.05
     */
    protected function checkSyncFailureRateAlert(): void
    {
        $failures = (int) Cache::get('pos.metrics.sync.failures_5m', 0);
        $total = (int) Cache::get('pos.metrics.sync.total_5m', 1);

        Cache::increment('pos.metrics.sync.failures_5m');
        Cache::put('pos.metrics.sync.failures_5m',
            Cache::get('pos.metrics.sync.failures_5m', 0), now()->addMinutes(5));

        $rate = $total > 0 ? ($failures / $total) : 0;

        if ($rate > config('monitoring.pos.sync_failure_rate_threshold', 0.05)) {
            Log::critical('pos.alert.sync_failure_rate_exceeded', [
                'failure_rate' => $rate,
                'threshold' => 0.05,
                'failures_5m' => $failures,
                'total_5m' => $total,
            ]);
        }
    }

    /**
     * Update the sync queue depth gauge.
     * Alert if depth exceeds 100.
     */
    protected function updateSyncQueueDepth(): void
    {
        $depth = \Modules\POS\Models\PosOfflineQueue::where('status', 'pending')->count();
        $this->gauge('pos.sync.queue_depth', $depth);

        if ($depth > config('monitoring.pos.sync_queue_depth_threshold', 100)) {
            Log::warning('pos.alert.sync_queue_depth_exceeded', [
                'queue_depth' => $depth,
                'threshold' => 100,
            ]);
        }
    }

    /**
     * Emit a metric to the configured backend.
     * Falls back to structured logging if no backend is configured.
     */
    protected function emit(string $type, string $metric, float $value, array $tags = []): void
    {
        // Increment cache-based counter for rate calculations
        if ($type === 'increment') {
            Cache::increment("pos.metrics.{$metric}");
        }

        // Log as structured metric for log-based monitoring (Datadog, CloudWatch, etc.)
        Log::debug('pos.metric', [
            'metric_type' => $type,
            'metric_name' => $metric,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
