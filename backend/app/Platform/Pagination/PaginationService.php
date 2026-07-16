<?php

namespace App\Platform\Pagination;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Pagination Service
 *
 * Standardized pagination across all modules.
 * Consistent query params: per_page, page, search, sort_by, sort_dir.
 */
class PaginationService
{
    /**
     * Paginate a query with standard parameters.
     *
     * @param  Builder  $query  The Eloquent query builder
     * @param  Request  $request  The HTTP request
     * @param  array  $config  Configuration options
     * @return array{data: array, meta: array}
     */
    public function paginate(Builder $query, Request $request, array $config = []): array
    {
        $perPage = $this->resolvePerPage($request, $config);
        $page = (int) $request->get('page', 1);
        $search = $request->get('search');
        $sortBy = $request->get('sort_by', $config['default_sort'] ?? 'created_at');
        $sortDir = $request->get('sort_dir', $config['default_sort_dir'] ?? 'desc');

        // Apply search
        if ($search && ! empty($config['search_columns'])) {
            $query->where(function ($q) use ($search, $config) {
                foreach ($config['search_columns'] as $column) {
                    $q->orWhere($column, 'ILIKE', "%{$search}%");
                }
            });
        }

        // Apply additional filters from request
        if (! empty($config['filters'])) {
            foreach ($config['filters'] as $filterKey => $filterConfig) {
                $value = $request->get($filterKey);
                if ($value !== null && $value !== '') {
                    $this->applyFilter($query, $filterKey, $value, $filterConfig);
                }
            }
        }

        // Apply sorting
        if ($sortBy && $this->isColumn($query, $sortBy)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        // Execute pagination
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * Simple paginated response wrapper.
     */
    public function toResponse(array $result, string $message = 'Success'): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $result['data'],
            'meta' => $result['meta'],
        ];
    }

    /**
     * Resolve per_page value from request or config.
     */
    private function resolvePerPage(Request $request, array $config): int
    {
        $per_page = (int) $request->get('per_page', $config['default_per_page'] ?? 20);
        $max = $config['max_per_page'] ?? 100;

        return max(1, min($per_page, $max));
    }

    /**
     * Apply a filter to the query.
     */
    private function applyFilter(Builder $query, string $key, mixed $value, array|string $config): void
    {
        $column = is_array($config) ? ($config['column'] ?? $key) : $config;
        $operator = is_array($config) ? ($config['operator'] ?? '=') : '=';

        match ($operator) {
            'ILIKE' => $query->where($column, 'ILIKE', "%{$value}%"),
            'in' => $query->whereIn($column, is_array($value) ? $value : [$value]),
            '>=', '>' , '<', '<=' => $query->where($column, $operator, $value),
            default => $query->where($column, $value),
        };
    }

    /**
     * Check if a column exists in the query's base table.
     */
    private function isColumn(Builder $query, string $column): bool
    {
        try {
            $table = $query->getModel()->getTable();

            return Schema::hasColumn($table, $column);
        } catch (\Exception $e) {
            return false;
        }
    }
}
