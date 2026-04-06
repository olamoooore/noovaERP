<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\TenantHealthSnapshot;
use App\SuperAdmin\Domain\TenantOperation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Database\Models\Tenant;

class TenantOperationService
{
    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
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
            targetType: 'tenant',
            targetId: (string) $tenantId,
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
            targetType: 'tenant',
            targetId: (string) $tenantId,
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
            DB::statement("SET search_path TO \"{$schemaName}\"");
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
            DB::statement('SET search_path TO public');
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
            targetType: 'tenant',
            targetId: (string) $tenantId,
            before: [],
            after: ['db_connected' => $dbConnected],
            status: 'success',
        );

        return $snapshot;
    }

    public function runOperation(
        string $tenantId,
        string $operation,
        array $payload = [],
        ?string $reason = null,
    ): TenantOperation {
        $allowed = ['suspend', 'reinstate', 'ping_health'];
        $notYetImplemented = ['migrate_plan', 'run_seeder'];

        if (in_array($operation, $notYetImplemented, true)) {
            throw ValidationException::withMessages([
                'operation' => "Operation [{$operation}] is not yet implemented. Scoped to Phase 3.",
            ]);
        }

        if (!in_array($operation, $allowed, true)) {
            throw ValidationException::withMessages([
                'operation' => "Unknown operation [{$operation}].",
            ]);
        }

        return match ($operation) {
            'suspend'     => $this->suspend($tenantId, $reason),
            'reinstate'   => $this->reinstate($tenantId, $reason),
            'ping_health' => $this->wrapPingAsOperation($tenantId),
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
