<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SecurityIncident;
use Illuminate\Support\Facades\Request;

class SecurityIncidentService
{
    public function recordFailedLogin(string $email, ?string $tenantId = null): SecurityIncident
    {
        return $this->record(
            eventType: 'failed_login',
            details: ['email' => $email],
            tenantId: $tenantId,
            severity: 'low',
        );
    }

    public function recordAdminLoginFailed(string $email, string $reason): SecurityIncident
    {
        return $this->record(
            eventType: 'admin_login_failed',
            details: ['email' => $email, 'reason' => $reason],
            severity: 'medium',
        );
    }

    public function recordBruteForce(int $attempts, string $email): SecurityIncident
    {
        return $this->record(
            eventType: 'brute_force_detected',
            details: ['email' => $email, 'attempts' => $attempts],
            severity: 'high',
        );
    }

    public function recordPrivilegeEscalationAttempt(
        string $attemptedRole,
        ?int $actorId = null,
    ): SecurityIncident {
        return $this->record(
            eventType: 'privilege_escalation_attempt',
            actorId: $actorId,
            details: ['attempted_role' => $attemptedRole],
            severity: 'critical',
        );
    }

    public function recordImpersonationStart(
        int $actorId,
        int $targetUserId,
        ?string $tenantId = null,
    ): SecurityIncident {
        return $this->record(
            eventType: 'impersonation_start',
            actorId: $actorId,
            targetUserId: $targetUserId,
            tenantId: $tenantId,
            severity: 'high',
        );
    }

    public function recordImpersonationEnd(
        int $actorId,
        int $targetUserId,
        ?string $tenantId = null,
    ): SecurityIncident {
        return $this->record(
            eventType: 'impersonation_end',
            actorId: $actorId,
            targetUserId: $targetUserId,
            tenantId: $tenantId,
            severity: 'medium',
        );
    }

    public function recordAnomaly(
        string $description,
        ?int $actorId = null,
        ?string $tenantId = null,
        array $extra = [],
    ): SecurityIncident {
        return $this->record(
            eventType: 'anomaly',
            actorId: $actorId,
            tenantId: $tenantId,
            details: array_merge(['description' => $description], $extra),
            severity: 'high',
        );
    }

    public function getRecentByIp(string $ipAddress, int $minutes = 15): int
    {
        return SecurityIncident::where('ip_address', $ipAddress)
            ->where('event_type', 'failed_login')
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    private function record(
        string $eventType,
        ?int $actorId = null,
        ?int $targetUserId = null,
        ?string $tenantId = null,
        array $details = [],
        string $severity = 'low',
    ): SecurityIncident {
        return SecurityIncident::create([
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'target_user_id' => $targetUserId,
            'tenant_id' => $tenantId,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'details' => $details,
            'severity' => $severity,
            'status' => 'open',
            'created_at' => now(),
        ]);
    }
}
