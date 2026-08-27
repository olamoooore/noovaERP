<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SecurityIncident;
use App\Services\SecurityIncidentService;
use Tests\TenantTestCase;

/**
 * Pillar 2 Part 2 §3 — security incident register:
 *
 * A structured security-incident register must exist to record failed
 * logins, brute-force detection, privilege escalation attempts, and
 * other security-relevant events in a queryable central table.
 */
class IssueP31SecurityIncidentRegisterTest extends TenantTestCase
{
    // ------------------------------------------------------------------
    // Source-checks
    // ------------------------------------------------------------------

    public function test_auth_controller_imports_security_incident_service(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../app/Http/Controllers/Api/Auth/AuthController.php'
        );

        $this->assertStringContainsString(SecurityIncidentService::class, $source);
        $this->assertStringContainsString('recordFailedLogin', $source);
        $this->assertStringContainsString('recordBruteForce', $source);
    }

    public function test_admin_auth_controller_imports_security_incident_service(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../app/SuperAdmin/Http/Controllers/AdminAuthController.php'
        );

        $this->assertStringContainsString(SecurityIncidentService::class, $source);
        $this->assertStringContainsString('recordAdminLoginFailed', $source);
        $this->assertStringContainsString('recordPrivilegeEscalationAttempt', $source);
    }

    public function test_security_incident_service_has_required_methods(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../app/Services/SecurityIncidentService.php'
        );

        $this->assertStringContainsString('recordFailedLogin', $source);
        $this->assertStringContainsString('recordAdminLoginFailed', $source);
        $this->assertStringContainsString('recordBruteForce', $source);
        $this->assertStringContainsString('recordPrivilegeEscalationAttempt', $source);
        $this->assertStringContainsString('recordImpersonationStart', $source);
        $this->assertStringContainsString('recordImpersonationEnd', $source);
        $this->assertStringContainsString('recordAnomaly', $source);
        $this->assertStringContainsString('getRecentByIp', $source);
    }

    public function test_security_incidents_migration_creates_table(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../database/migrations/2026_08_27_000001_create_security_incidents_table.php'
        );

        $this->assertStringContainsString("Schema::create('security_incidents'", $source);
        $this->assertStringContainsString('event_type', $source);
        $this->assertStringContainsString('actor_id', $source);
        $this->assertStringContainsString('target_user_id', $source);
        $this->assertStringContainsString('tenant_id', $source);
        $this->assertStringContainsString('ip_address', $source);
        $this->assertStringContainsString('severity', $source);
        $this->assertStringContainsString('status', $source);
    }

    public function test_security_incident_model_has_correct_table(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../app/Models/SecurityIncident.php'
        );

        $this->assertStringContainsString("protected \$table = 'security_incidents'", $source);
        $this->assertStringContainsString('event_type', $source);
        $this->assertStringContainsString('severity', $source);
    }

    // ------------------------------------------------------------------
    // Runtime: SecurityIncidentService unit tests
    // ------------------------------------------------------------------

    public function test_record_failed_login_creates_incident(): void
    {
        $service = app(SecurityIncidentService::class);
        $incident = $service->recordFailedLogin('test@example.com');

        $this->assertInstanceOf(SecurityIncident::class, $incident);
        $this->assertSame('failed_login', $incident->event_type);
        $this->assertSame('low', $incident->severity);
        $this->assertSame('open', $incident->status);
        $this->assertSame('test@example.com', $incident->details['email']);
        $this->assertNotNull($incident->ip_address);
    }

    public function test_record_admin_login_failed_creates_incident(): void
    {
        $service = app(SecurityIncidentService::class);
        $incident = $service->recordAdminLoginFailed('admin@example.com', 'invalid_credentials');

        $this->assertSame('admin_login_failed', $incident->event_type);
        $this->assertSame('medium', $incident->severity);
        $this->assertSame('invalid_credentials', $incident->details['reason']);
    }

    public function test_record_brute_force_creates_incident(): void
    {
        $service = app(SecurityIncidentService::class);
        $incident = $service->recordBruteForce(6, 'victim@example.com');

        $this->assertSame('brute_force_detected', $incident->event_type);
        $this->assertSame('high', $incident->severity);
        $this->assertSame(6, $incident->details['attempts']);
    }

    public function test_record_privilege_escalation_creates_incident(): void
    {
        $service = app(SecurityIncidentService::class);
        $incident = $service->recordPrivilegeEscalationAttempt(
            attemptedRole: 'platform_super_admin',
            actorId: 42,
        );

        $this->assertSame('privilege_escalation_attempt', $incident->event_type);
        $this->assertSame('critical', $incident->severity);
        $this->assertSame(42, $incident->actor_id);
        $this->assertSame('platform_super_admin', $incident->details['attempted_role']);
    }

    public function test_record_impersonation_creates_incidents(): void
    {
        $service = app(SecurityIncidentService::class);
        $start = $service->recordImpersonationStart(1, 2, 'tenant-abc');
        $end = $service->recordImpersonationEnd(1, 2, 'tenant-abc');

        $this->assertSame('impersonation_start', $start->event_type);
        $this->assertSame('high', $start->severity);
        $this->assertSame(1, $start->actor_id);
        $this->assertSame(2, $start->target_user_id);
        $this->assertSame('tenant-abc', $start->tenant_id);

        $this->assertSame('impersonation_end', $end->event_type);
        $this->assertSame('medium', $end->severity);
    }

    public function test_record_anomaly_creates_incident(): void
    {
        $service = app(SecurityIncidentService::class);
        $incident = $service->recordAnomaly(
            description: 'Unusual access pattern detected',
            actorId: 5,
            tenantId: 'tenant-xyz',
            extra: ['pattern' => 'multiple_countries'],
        );

        $this->assertSame('anomaly', $incident->event_type);
        $this->assertSame('high', $incident->severity);
        $this->assertSame(5, $incident->actor_id);
        $this->assertSame('Unusual access pattern detected', $incident->details['description']);
        $this->assertSame('multiple_countries', $incident->details['pattern']);
    }

    public function test_get_recent_by_ip_returns_correct_count(): void
    {
        $service = app(SecurityIncidentService::class);

        // Record 3 failed logins from the same IP
        for ($i = 0; $i < 3; $i++) {
            $service->recordFailedLogin('victim@example.com');
        }

        $count = $service->getRecentByIp('127.0.0.1', 15);
        $this->assertGreaterThanOrEqual(3, $count);
    }

    public function test_security_incident_is_immutable(): void
    {
        $incident = SecurityIncident::create([
            'event_type' => 'test_event',
            'severity' => 'low',
            'status' => 'open',
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $this->assertNull($incident->updated_at, 'SecurityIncident should be immutable (no timestamps)');
    }
}
