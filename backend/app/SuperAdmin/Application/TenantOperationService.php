<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\PlanVersion;
use App\SuperAdmin\Domain\Subscription;
use App\SuperAdmin\Domain\TenantHealthSnapshot;
use App\SuperAdmin\Domain\TenantOperation;
use App\Services\SearchPathGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Database\Models\Tenant;

class TenantOperationService
{
    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function suspend(string $tenantId, ?string $reason = null): TenantOperation
    {
        $tenant = Tenant::findOrFail($tenantId);

        $before = $tenant->data ?? [];
        $tenant->update(['data' => array_merge($before, ['suspended' => true])]);

        $operation = TenantOperation::create([
            'tenant_id'    => $tenantId,
            'admin_user_id'=> Auth::id(),
            'operation'    => 'suspend',
            'payload'      => ['reason' => $reason],
            'result'       => ['suspended' => true],
            'status'       => 'success',
            'created_at'   => now(),
        ]);

        $this->auditLogger->log(
            action: 'tenant.suspended',
            target: $tenant,
            before: $before,
            after: array_merge($before, ['suspended' => true]),
            reason: $reason,
            status: 'success',
        );

        return $operation;
    }

    public function reinstate(string $tenantId, ?string $reason = null): TenantOperation
    {
        $tenant = Tenant::findOrFail($tenantId);

        $before = $tenant->data ?? [];
        $tenant->update(['data' => array_merge($before, ['suspended' => false])]);

        $operation = TenantOperation::create([
            'tenant_id'    => $tenantId,
            'admin_user_id'=> Auth::id(),
            'operation'    => 'reinstate',
            'payload'      => ['reason' => $reason],
            'result'       => ['suspended' => false],
            'status'       => 'success',
            'created_at'   => now(),
        ]);

        $this->auditLogger->log(
            action: 'tenant.reinstated',
            target: $tenant,
            before: $before,
            after: array_merge($before, ['suspended' => false]),
            reason: $reason,
            status: 'success',
        );

        return $operation;
    }

    public function pingHealth(string $tenantId): TenantHealthSnapshot
    {
        $tenant     = Tenant::findOrFail($tenantId);
        $schemaName = $tenant->id;
        $dbConnected    = false;
        $storageUsageMb = null;
        $lastLoginAt    = null;

        try {
            SearchPathGuard::setSearchPath($schemaName, "Tenant health check for tenant {$tenantId}");
            $dbConnected = true;

            $sizeRow = DB::selectOne(
                "SELECT pg_size_pretty(pg_total_relation_size(quote_ident(schemaname) || '.' || quote_ident(tablename)))
                 FROM pg_tables WHERE schemaname = ? LIMIT 1",
                [$schemaName]
            );

            $totalBytes = DB::selectOne(
                "SELECT SUM(pg_total_relation_size(quote_ident(schemaname) || '.' || quote_ident(tablename))) AS total
                 FROM pg_tables WHERE schemaname = ?",
                [$schemaName]
            );

            if ($totalBytes && $totalBytes->total) {
                $storageUsageMb = (int) round($totalBytes->total / 1024 / 1024);
            }

            // Try to get last login from tenant's users table
            $loginRow = DB::selectOne("SELECT MAX(last_login_at) as last_login FROM users LIMIT 1");
            if ($loginRow && $loginRow->last_login) {
                $lastLoginAt = $loginRow->last_login;
            }
        } catch (\Throwable) {
            $dbConnected = false;
        } finally {
            SearchPathGuard::setSearchPath('public', "Tenant health check complete for tenant {$tenantId}");
        }

        $snapshot = TenantHealthSnapshot::create([
            'tenant_id'          => $tenantId,
            'db_connected'       => $dbConnected,
            'storage_usage_mb'   => $storageUsageMb,
            'last_login_at'      => $lastLoginAt,
            'error_rate_percent' => null,
            'response_time_ms'   => null,
            'metadata'           => null,
            'created_at'         => now(),
        ]);

        $this->auditLogger->log(
            action: 'tenant.health_pinged',
            target: $tenant,
            before: [],
            after: ['db_connected' => $dbConnected],
            status: 'success',
        );

        return $snapshot;
    }

    public function migratePlan(
        string $tenantId,
        array $payload,
        ?string $reason = null,
    ): TenantOperation {
        $planVersionId    = $payload['plan_version_id'] ?? null;
        $billingInterval  = $payload['billing_interval'] ?? 'monthly';

        if (!$planVersionId) {
            throw ValidationException::withMessages([
                'payload' => 'plan_version_id is required for migrate_plan.',
            ]);
        }

        if (!in_array($billingInterval, ['monthly', 'yearly'], true)) {
            throw ValidationException::withMessages([
                'payload' => 'billing_interval must be monthly or yearly.',
            ]);
        }

        $newVersion   = PlanVersion::findOrFail($planVersionId);
        $subscription = Subscription::where('tenant_id', $tenantId)->latest()->first();

        if ($subscription) {
            // Tenant already has a subscription — migrate it to the new plan
            $this->subscriptionService->migratePlan(
                subscription: $subscription,
                newVersion: $newVersion,
                billingInterval: $billingInterval,
                reason: $reason,
            );
        } else {
            // Tenant is on a legacy trial with no subscription record — create one
            $this->subscriptionService->createSubscription(
                tenantId: $tenantId,
                planVersionId: (int) $planVersionId,
                billingInterval: $billingInterval,
            );
        }

        return TenantOperation::create([
            'tenant_id'     => $tenantId,
            'admin_user_id' => Auth::id(),
            'operation'     => 'migrate_plan',
            'payload'       => $payload,
            'result'        => ['plan_version_id' => $newVersion->id, 'billing_interval' => $billingInterval],
            'status'        => 'success',
            'created_at'    => now(),
        ]);
    }

    public function runOperation(
        string $tenantId,
        string $operation,
        array $payload = [],
        ?string $reason = null,
    ): TenantOperation {
        $allowed = ['suspend', 'reinstate', 'ping_health', 'migrate_plan'];
        $notYetImplemented = ['run_seeder'];

        if (in_array($operation, $notYetImplemented, true)) {
            throw ValidationException::withMessages([
                'operation' => "Operation [{$operation}] is not yet implemented.",
            ]);
        }

        if (!in_array($operation, $allowed, true)) {
            throw ValidationException::withMessages([
                'operation' => "Unknown operation [{$operation}].",
            ]);
        }

        return match ($operation) {
            'suspend'      => $this->suspend($tenantId, $reason),
            'reinstate'    => $this->reinstate($tenantId, $reason),
            'ping_health'  => $this->wrapPingAsOperation($tenantId),
            'migrate_plan' => $this->migratePlan($tenantId, $payload, $reason),
        };
    }

    private function wrapPingAsOperation(string $tenantId): TenantOperation
    {
        $snapshot = $this->pingHealth($tenantId);

        return TenantOperation::create([
            'tenant_id'     => $tenantId,
            'admin_user_id' => Auth::id(),
            'operation'     => 'ping_health',
            'payload'       => null,
            'result'        => ['db_connected' => $snapshot->db_connected, 'snapshot_id' => $snapshot->id],
            'status'        => 'success',
            'created_at'    => now(),
        ]);
    }
}
