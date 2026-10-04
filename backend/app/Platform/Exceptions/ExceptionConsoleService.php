<?php

namespace App\Platform\Exceptions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Issue #914: read model for the operations console over #902's
 * `platform_exception_queue`.
 *
 * The console needs more than ExceptionService's tenant-scoped CRUD: a
 * permission-scoped query (per exception type), grouping stats by type and
 * entity, and an SLA age computed against the per-severity targets in
 * config/operations_console.php.
 */
class ExceptionConsoleService
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $allowedOwnerRoles
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, array $allowedOwnerRoles, bool $allowUnowned, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->scopedQuery($filters, $allowedOwnerRoles, $allowUnowned)
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderByDesc('created_at');

        $page = $query->paginate($perPage)->withQueryString();

        $assigneeNames = $this->assigneeNames(
            collect($page->items())->pluck('assigned_to')->filter()->unique()->all(),
        );

        $page->through(fn (ExceptionQueue $row) => $this->present($row, $assigneeNames));

        return $page;
    }

    /**
     * Counts the console renders as summary cards and grouping headers.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $allowedOwnerRoles
     * @return array<string, mixed>
     */
    public function stats(array $filters, array $allowedOwnerRoles, bool $allowUnowned): array
    {
        $base = $this->scopedQuery($filters, $allowedOwnerRoles, $allowUnowned);

        $slaBreached = (clone $base)
            ->get(['severity', 'created_at', 'resolved_at'])
            ->filter(fn (ExceptionQueue $row) => $this->sla($row)['sla_breached'])
            ->count();

        return [
            'total' => (clone $base)->count(),
            'by_type' => $this->groupCount(clone $base, 'exception_type'),
            'by_module' => $this->groupCount(clone $base, 'module'),
            'by_status' => $this->groupCount(clone $base, 'status'),
            'by_severity' => $this->groupCount(clone $base, 'severity'),
            'by_owner_role' => $this->groupCount(clone $base, 'owner_role'),
            'by_entity' => $this->entityCount(clone $base),
            'sla_breached' => $slaBreached,
        ];
    }

    /**
     * @param  array<int, string>  $assigneeNames  id → name map (avoids N+1 on the list)
     * @return array<string, mixed>
     */
    public function present(ExceptionQueue $row, array $assigneeNames = []): array
    {
        $assigneeName = null;

        if ($row->assigned_to !== null) {
            $assigneeName = $assigneeNames[$row->assigned_to]
                ?? DB::table('public.users')->where('id', $row->assigned_to)->value('name');
        }

        return array_merge($row->toArray(), $this->sla($row), [
            'assigned_to_name' => $assigneeName,
        ]);
    }

    /**
     * Age vs the per-severity operational SLA target (config constants):
     * `age_hours` from raise to resolution (or now), `sla_breached` once the
     * due time passed — for resolved rows, once resolution ran past it.
     *
     * @return array{age_hours: float, sla_hours: int, sla_due_at: ?string, sla_breached: bool}
     */
    public function sla(ExceptionQueue $row): array
    {
        $targetHours = (int) config(
            'operations_console.sla_hours.'.$row->severity,
            (int) config('operations_console.sla_hours.default', 24),
        );

        $createdAt = $row->created_at;
        $end = $row->resolved_at ?? now();

        if ($createdAt === null) {
            return [
                'age_hours' => 0.0,
                'sla_hours' => $targetHours,
                'sla_due_at' => null,
                'sla_breached' => false,
            ];
        }

        $ageHours = round(max(0, $end->getTimestamp() - $createdAt->getTimestamp()) / 3600, 1);
        $dueAt = $createdAt->copy()->addHours($targetHours);
        $breached = $row->resolved_at !== null
            ? $row->resolved_at->gt($dueAt)
            : now()->gt($dueAt);

        return [
            'age_hours' => $ageHours,
            'sla_hours' => $targetHours,
            'sla_due_at' => $dueAt->toIso8601String(),
            'sla_breached' => $breached,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $allowedOwnerRoles
     */
    private function scopedQuery(array $filters, array $allowedOwnerRoles, bool $allowUnowned): Builder
    {
        $query = ExceptionQueue::query()->where('tenant_id', tenant()->id);

        $query->where(function (Builder $scoped) use ($allowedOwnerRoles, $allowUnowned): void {
            if ($allowedOwnerRoles !== []) {
                $scoped->whereIn('owner_role', $allowedOwnerRoles);

                if ($allowUnowned) {
                    $scoped->orWhereNull('owner_role');
                }
            } elseif ($allowUnowned) {
                $scoped->whereNull('owner_role');
            } else {
                // No mapped read permission at all → match nothing.
                $scoped->whereRaw('1 = 0');
            }
        });

        foreach ([
            'type' => 'exception_type',
            'module' => 'module',
            'status' => 'status',
            'owner' => 'owner_role',
            'severity' => 'severity',
        ] as $param => $column) {
            if (isset($filters[$param]) && $filters[$param] !== '') {
                $query->where($column, $filters[$param]);
            }
        }

        if (isset($filters['legal_entity_id']) && $filters['legal_entity_id'] !== '') {
            $query->where('legal_entity_id', (int) $filters['legal_entity_id']);
        }

        if (isset($filters['assigned_to']) && $filters['assigned_to'] !== '') {
            $query->where('assigned_to', (int) $filters['assigned_to']);
        }

        if (isset($filters['document_id']) && $filters['document_id'] !== '') {
            $query->where('document_id', (string) $filters['document_id']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $match) use ($search): void {
                $match->where('message', 'ilike', $search)
                    ->orWhere('exception_type', 'ilike', $search);
            });
        }

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function groupCount(Builder $query, string $column): array
    {
        return $query
            ->select($column, DB::raw('count(*) as aggregate'))
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function entityCount(Builder $query): array
    {
        $counts = $query
            ->select('legal_entity_id', DB::raw('count(*) as aggregate'))
            ->groupBy('legal_entity_id')
            ->pluck('aggregate', 'legal_entity_id');

        $result = [];

        foreach ($counts as $entityId => $count) {
            $key = $entityId === null || $entityId === '' ? 'unassigned' : (string) $entityId;
            $result[$key] = (int) $count;
        }

        return $result;
    }

    /**
     * @param  array<int, int|string|null>  $ids
     * @return array<int, string>
     */
    private function assigneeNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('public.users')
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])
            ->all();
    }
}
