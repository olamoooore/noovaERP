<?php

namespace App\Platform\SystemLog;

use App\Platform\Audit\AuditHashChain;
use App\Platform\SystemLog\Contracts\SystemLogServiceContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SystemLogService implements SystemLogServiceContract
{
    public function __construct(private ?AuditHashChain $hashChain = null) {}

    public function log(string $level, string $category, string $message, ?array $context = null, ?string $correlationId = null): object
    {
        // In test/CLI contexts (no HTTP request, no tenant initialized), skip
        // writing to the system log instead of crashing. Production HTTP
        // requests always have a tenant initialized via middleware.
        $currentTenant = tenant();
        if (! $currentTenant) {
            return (object) [
                'id' => Str::uuid(),
                'tenant_id' => null,
                'level' => $level,
                'category' => $category,
                'message' => $message,
                'context' => $context,
                'correlation_id' => $correlationId ?? Str::uuid(),
                'source' => request() ? request()->headers->get('User-Agent', 'system') : 'system',
                'user_id' => auth()->id(),
            ];
        }

        // Created-at is truncated to the database's timestamp precision so the
        // hash computed here matches the value read back during verification.
        $createdAt = now()->startOfSecond();
        $userId = auth()->id();
        $correlationId ??= (string) Str::uuid();
        $source = request()->headers->get('User-Agent', 'system');

        // Issue #912: hash-chain the audit row and the chain head update in
        // one transaction — a failed insert rolls the head back with it.
        return DB::transaction(function () use ($currentTenant, $level, $category, $message, $context, $correlationId, $source, $userId, $createdAt) {
            $seal = ($this->hashChain ??= app(AuditHashChain::class))->seal([
                'tenant_id' => $currentTenant->id,
                'actor_id' => $userId,
                'operation' => $category,
                'subject' => $message,
                'payload' => $context,
                'created_at' => $createdAt,
            ]);

            $record = SystemLog::create([
                'id' => Str::uuid(),
                'tenant_id' => $currentTenant->id,
                'level' => $level,
                'category' => $category,
                'message' => $message,
                'context' => $context,
                'correlation_id' => $correlationId,
                'source' => $source,
                'user_id' => $userId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'chain_sequence' => $seal['chain_sequence'],
                'previous_hash' => $seal['previous_hash'],
                'entry_hash' => $seal['entry_hash'],
            ]);

            return (object) $record->toArray();
        });
    }

    public function query(array $filters): array
    {
        $query = SystemLog::where('tenant_id', tenant()->id);

        if (isset($filters['level'])) {
            $query->where('level', $filters['level']);
        }

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['correlation_id'])) {
            $query->where('correlation_id', $filters['correlation_id']);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query->orderBy('created_at', 'desc')->get()->toArray();
    }

    public function getByCorrelationId(string $correlationId): array
    {
        return SystemLog::where('tenant_id', tenant()->id)
            ->where('correlation_id', $correlationId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->toArray();
    }

    public function getRecent(int $limit = 50): array
    {
        return SystemLog::where('tenant_id', tenant()->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
