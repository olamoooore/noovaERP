<?php

namespace App\Platform\SystemLog;

use App\Platform\SystemLog\Contracts\SystemLogServiceContract;
use Illuminate\Support\Str;

class SystemLogService implements SystemLogServiceContract
{
    public function log(string $level, string $category, string $message, ?array $context = null, ?string $correlationId = null): object
    {
        $record = SystemLog::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'level' => $level,
            'category' => $category,
            'message' => $message,
            'context' => $context,
            'correlation_id' => $correlationId ?? Str::uuid(),
            'source' => request()->headers->get('User-Agent', 'system'),
            'user_id' => auth()->id(),
        ]);

        return (object) $record->toArray();
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
