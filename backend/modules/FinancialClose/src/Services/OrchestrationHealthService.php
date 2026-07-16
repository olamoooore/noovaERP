<?php

namespace Modules\FinancialClose\Services;

use Modules\FinancialClose\Models\CloseReadinessSnapshot;
use Modules\FinancialClose\Models\HealthEvent;
use Modules\FinancialClose\Models\JobRunHistory;
use Modules\FinancialClose\Models\OrchestrationAlert;

class OrchestrationHealthService
{
    /**
     * Record a health event
     */
    public function recordHealthEvent(string $tenantId, string $eventType, string $severity, string $description, array $metadata = []): ?HealthEvent
    {
        return HealthEvent::create([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'severity' => $severity,
            'status' => 'open',
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Resolve a health event
     */
    public function resolveHealthEvent(string $tenantId, string $eventId): bool
    {
        $event = HealthEvent::where('tenant_id', $tenantId)
            ->where('id', $eventId)
            ->first();

        if (! $event || $event->status !== 'open') {
            return false;
        }

        $event->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        return true;
    }

    /**
     * Record a job run
     */
    public function recordJobRun(string $tenantId, string $jobType): JobRunHistory
    {
        return JobRunHistory::create([
            'tenant_id' => $tenantId,
            'job_type' => $jobType,
            'status' => 'pending',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark a job run as completed
     */
    public function completeJobRun(string $tenantId, string $jobRunId, bool $success, ?string $errorMessage = null): bool
    {
        $jobRun = JobRunHistory::where('tenant_id', $tenantId)
            ->where('id', $jobRunId)
            ->first();

        if (! $jobRun) {
            return false;
        }

        $startedAt = $jobRun->started_at;
        $completedAt = now();
        $durationMs = $startedAt ? $completedAt->diffInMilliseconds($startedAt) : null;

        $jobRun->update([
            'status' => $success ? 'completed' : 'failed',
            'completed_at' => $completedAt,
            'duration_ms' => $durationMs,
            'error_message' => $errorMessage,
        ]);

        if (! $success) {
            $this->createAlert(
                $tenantId,
                'job_failed',
                'high',
                "Job {$jobRun->job_type} failed: {$errorMessage}"
            );
        }

        return true;
    }

    /**
     * Create an orchestration alert
     */
    public function createAlert(string $tenantId, string $alertType, string $severity, string $message, array $metadata = []): ?OrchestrationAlert
    {
        return OrchestrationAlert::create([
            'tenant_id' => $tenantId,
            'alert_type' => $alertType,
            'severity' => $severity,
            'status' => 'open',
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Check for stale readiness snapshots and alert
     */
    public function checkStaleSnapshots(string $tenantId, int $staleAfterHours = 24): int
    {
        $staleSnapshots = CloseReadinessSnapshot::where('tenant_id', $tenantId)
            ->where('generated_at', '<', now()->subHours($staleAfterHours))
            ->get();

        $alerted = 0;

        foreach ($staleSnapshots as $snapshot) {
            $this->createAlert(
                $tenantId,
                'stale_readiness_snapshot',
                'medium',
                "Readiness snapshot for entity {$snapshot->entity_id} is stale",
                ['entity_id' => $snapshot->entity_id, 'period_id' => $snapshot->period_id]
            );
            $alerted++;
        }

        return $alerted;
    }

    /**
     * Get open alerts for a tenant
     */
    public function getOpenAlerts(string $tenantId): array
    {
        return OrchestrationAlert::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get health dashboard summary
     */
    public function getHealthDashboard(string $tenantId): array
    {
        $openAlerts = OrchestrationAlert::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->count();

        $failedJobs = JobRunHistory::where('tenant_id', $tenantId)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        $openHealthEvents = HealthEvent::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->count();

        return [
            'open_alerts' => $openAlerts,
            'failed_jobs_last_24h' => $failedJobs,
            'open_health_events' => $openHealthEvents,
            'is_healthy' => $openAlerts === 0 && $failedJobs === 0,
        ];
    }
}
