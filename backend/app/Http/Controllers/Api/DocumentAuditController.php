<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Platform\Export\SpreadsheetFormulaGuard;
use App\Platform\SystemLog\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;

/**
 * Issue #905 (SALES-PLAT / PCU1): the read-only "Audit" feed for a Sales
 * document. It is a query over the #895 trail, never a second history store:
 *
 *   - field-level before/after changes come from the AuditTrailObserver rows
 *     (`platform_system_logs`, category audit.sales.*) — subject = table +
 *     row id, field, old value, new value, actor, request id;
 *   - explicit lifecycle rows (posted, cancelled, reset, voided, …) come from
 *     `audit_logs` with their before/after state and actor.
 *
 * Both sources are normalised into the same row shape, filtered by field or
 * user, and exported as CSV through the shared #898 formula guard. There are
 * no write verbs: every route is GET-only.
 */
class DocumentAuditController extends Controller
{
    /**
     * subject key => the trail's reference type + the observed tables/FK that
     * link line rows back to their document.
     *
     * @var array<string, array{reference_type: string, table: string, model: class-string, line_table: ?string, line_foreign_key: ?string}>
     */
    private const SUBJECTS = [
        'invoice' => [
            'reference_type' => 'invoice',
            'table' => 'invoices',
            'model' => Invoice::class,
            'line_table' => 'invoice_lines',
            'line_foreign_key' => 'invoice_id',
        ],
        'quotation' => [
            'reference_type' => 'quotation',
            'table' => 'quotations',
            'model' => Quotation::class,
            'line_table' => 'quotation_lines',
            'line_foreign_key' => 'quotation_id',
        ],
        'sales_order' => [
            'reference_type' => 'sales_order',
            'table' => 'sales_orders',
            'model' => SalesOrder::class,
            'line_table' => 'sales_order_lines',
            'line_foreign_key' => 'sales_order_id',
        ],
        'delivery_order' => [
            'reference_type' => 'delivery_order',
            'table' => 'delivery_orders',
            'model' => DeliveryOrder::class,
            'line_table' => 'delivery_order_lines',
            'line_foreign_key' => 'delivery_order_id',
        ],
        'payment' => [
            'reference_type' => 'payment',
            'table' => 'payments',
            'model' => Payment::class,
            'line_table' => null,
            'line_foreign_key' => null,
        ],
        'credit_note' => [
            'reference_type' => 'customer_credit_note',
            'table' => 'customer_credit_notes',
            'model' => CustomerCreditNote::class,
            'line_table' => 'customer_credit_note_lines',
            'line_foreign_key' => 'credit_note_id',
        ],
        'customer' => [
            'reference_type' => 'customer',
            'table' => 'customers',
            'model' => Customer::class,
            'line_table' => null,
            'line_foreign_key' => null,
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        [$subject, $subjectId] = $this->resolveSubject($request);

        $all = $this->entries($subject, $subjectId);
        $users = $this->resolveUsers($all);
        $entries = $this->withUsers($this->applyFilters($all, $request), $users);

        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $page = max((int) $request->query('page', 1), 1);
        $total = $entries->count();

        return response()->json([
            'data' => $entries->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'meta' => [
                'current_page' => $page,
                'last_page' => max((int) ceil($total / $perPage), 1),
                'per_page' => $perPage,
                'total' => $total,
            ],
            'filters' => [
                'fields' => $all->pluck('field')->filter()->unique()->sort()->values()->all(),
                'users' => $this->resolveUsers($all, true)->values()->all(),
            ],
        ]);
    }

    public function export(Request $request)
    {
        [$subject, $subjectId] = $this->resolveSubject($request);

        $all = $this->entries($subject, $subjectId);
        $users = $this->resolveUsers($all);
        $entries = $this->withUsers($this->applyFilters($all, $request), $users);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Time', 'User', 'Event', 'Field', 'Old Value', 'New Value', 'Request ID']);

        foreach ($entries as $entry) {
            fputcsv($handle, array_map(
                fn ($value) => is_string($value) ? SpreadsheetFormulaGuard::neutralise($value) : $value,
                [
                    $entry['occurred_at'],
                    $entry['user']['name'] ?? 'System',
                    $entry['event'],
                    $entry['path'] ?? $entry['field'] ?? '',
                    $this->stringify($entry['old_value']),
                    $this->stringify($entry['new_value']),
                    $entry['request_id'] ?? '',
                ],
            ));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$subject.'-'.$subjectId.'-audit.csv"',
        ]);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveSubject(Request $request): array
    {
        $subject = (string) $request->route('auditSubject');
        abort_unless(isset(self::SUBJECTS[$subject]), 404);

        $subjectId = (int) $request->route('subjectId');
        $config = self::SUBJECTS[$subject];

        if ($subjectId < 1 || ! $config['model']::query()->whereKey($subjectId)->exists()) {
            abort(404, 'Document not found');
        }

        return [$subject, $subjectId];
    }

    /**
     * The normalised trail for one document, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function entries(string $subject, int $subjectId): Collection
    {
        $config = self::SUBJECTS[$subject];

        $auditLogs = AuditLog::query()
            ->where('reference_type', $config['reference_type'])
            ->where('reference_id', $subjectId)
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $explicitRequestIds = $auditLogs
            ->filter(fn (AuditLog $log) => str_ends_with((string) $log->operation, '_updated'))
            ->map(fn (AuditLog $log) => is_array($log->metadata) ? ($log->metadata['request_id'] ?? null) : null)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        $systemLogs = $this->systemLogsFor($config, $subjectId)
            ->reject(function (SystemLog $log) use ($explicitRequestIds) {
                $requestId = $log->context['request_id'] ?? null;

                return is_string($requestId) && $requestId !== '' && in_array($requestId, $explicitRequestIds, true);
            });

        $entries = collect();

        foreach ($auditLogs as $log) {
            $entries->push(...$this->entriesFromAuditLog($log));
        }

        foreach ($systemLogs as $log) {
            $entries->push(...$this->entriesFromSystemLog($log));
        }

        return $entries
            ->sortByDesc(fn (array $entry) => $entry['occurred_at'])
            ->values();
    }

    /**
     * Observer rows for the document table and its line table.
     */
    private function systemLogsFor(array $config, int $subjectId): Collection
    {
        return SystemLog::query()
            ->where('category', 'like', 'audit.sales.%')
            ->where(function ($outer) use ($config, $subjectId) {
                $outer->where(function ($q) use ($config, $subjectId) {
                    $q->where('context->table', $config['table'])
                        ->where('context->model_id', $subjectId);
                });

                if ($config['line_table'] !== null) {
                    $outer->orWhere(function ($q) use ($config, $subjectId) {
                        $q->where('context->table', $config['line_table'])
                            ->where(function ($inner) use ($config, $subjectId) {
                                $inner->where('context->after->'.$config['line_foreign_key'], $subjectId)
                                    ->orWhere('context->before->'.$config['line_foreign_key'], $subjectId);
                            });
                    });
                }
            })
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function entriesFromAuditLog(AuditLog $log): array
    {
        $before = is_array($log->before_state) ? $log->before_state : [];
        $after = is_array($log->after_state) ? $log->after_state : [];
        $requestId = is_array($log->metadata) ? ($log->metadata['request_id'] ?? null) : null;
        $occurredAt = $log->created_at?->toIso8601String() ?? now()->toIso8601String();

        $base = [
            'id' => 'audit-'.$log->id,
            'source' => 'audit_log',
            'occurred_at' => $occurredAt,
            'user_id' => $log->actor_id !== null ? (int) $log->actor_id : null,
            'event' => (string) $log->operation,
            'request_id' => $requestId,
        ];

        // Field-level rows: the explicit update snapshot carries old and new
        // for every changed header field and line field.
        if (str_ends_with((string) $log->operation, '_updated')) {
            return array_map(
                fn (array $change) => $base + [
                    'kind' => 'field',
                    'field' => $change['field'],
                    'path' => $change['path'],
                    'old_value' => $change['old'],
                    'new_value' => $change['new'],
                ],
                $this->diffArrays($before, $after),
            );
        }

        // Everything else is a lifecycle event (posted, cancelled, reset,
        // deleted, voided, …). Keep the status transition when there is one.
        $field = null;
        $oldValue = null;
        $newValue = null;

        foreach (['status', 'payment_status'] as $candidate) {
            if (array_key_exists($candidate, $before) || array_key_exists($candidate, $after)) {
                $field = $candidate;
                $oldValue = $before[$candidate] ?? null;
                $newValue = $after[$candidate] ?? null;
                break;
            }
        }

        return [$base + [
            'kind' => 'lifecycle',
            'field' => $field,
            'path' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function entriesFromSystemLog(SystemLog $log): array
    {
        $context = is_array($log->context) ? $log->context : [];
        $action = (string) ($context['action'] ?? 'updated');
        $requestId = $context['request_id'] ?? null;

        $base = [
            'id' => 'log-'.$log->id,
            'source' => 'system_log',
            'occurred_at' => $log->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'user_id' => $log->user_id !== null
                ? (int) $log->user_id
                : (isset($context['actor_id']) && $context['actor_id'] !== null ? (int) $context['actor_id'] : null),
            'event' => $action,
            'request_id' => is_string($requestId) && $requestId !== '' ? $requestId : null,
        ];

        if ($action === 'updated') {
            $before = is_array($context['before'] ?? null) ? $context['before'] : [];
            $after = is_array($context['after'] ?? null) ? $context['after'] : [];

            return array_map(
                fn (array $change) => $base + [
                    'kind' => 'field',
                    'field' => $change['field'],
                    'path' => $change['path'],
                    'old_value' => $change['old'],
                    'new_value' => $change['new'],
                ],
                $this->diffArrays($before, $after),
            );
        }

        return [$base + [
            'kind' => 'lifecycle',
            'field' => null,
            'path' => null,
            'old_value' => null,
            'new_value' => $action === 'deleted' ? true : null,
        ]];
    }

    /**
     * Flatten nested before/after arrays into leaf field changes so the tab
     * can render "field: old → new" for header fields and line fields alike.
     *
     * @return array<int, array{field: string, path: string, old: mixed, new: mixed}>
     */
    private function diffArrays(array $before, array $after, string $prefix = ''): array
    {
        $changes = [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));

        foreach ($keys as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if (is_array($old) || is_array($new)) {
                $changes = array_merge($changes, $this->diffArrays((array) $old, (array) $new, $path));

                continue;
            }

            if ((string) $old !== (string) $new) {
                $changes[] = [
                    'field' => (string) $key,
                    'path' => $path,
                    'old' => $old,
                    'new' => $new,
                ];
            }
        }

        return $changes;
    }

    private function applyFilters(Collection $entries, Request $request): Collection
    {
        $field = $request->query('field');
        if (is_string($field) && $field !== '') {
            $entries = $entries->filter(
                fn (array $entry) => ($entry['field'] ?? null) === $field || ($entry['path'] ?? null) === $field,
            );
        }

        $userId = $request->query('user_id');
        if ($userId !== null && $userId !== '') {
            $entries = $entries->filter(
                fn (array $entry) => (int) ($entry['user_id'] ?? 0) === (int) $userId,
            );
        }

        return $entries->values();
    }

    /**
     * Resolve actor names once for the whole feed.
     *
     * @return Collection<int|string, string>
     */
    private function resolveUsers(Collection $entries, bool $asList = false): Collection
    {
        $ids = $entries
            ->pluck('user_id')
            ->filter(fn ($id) => $id !== null)
            ->unique()
            ->values();

        $names = $ids->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $ids->all())->pluck('name', 'id');

        if ($asList) {
            return $ids->map(fn ($id) => ['id' => (int) $id, 'name' => (string) ($names[$id] ?? 'User #'.$id)])->values();
        }

        return $names;
    }

    private function withUsers(Collection $entries, Collection $users): Collection
    {
        return $entries->map(function (array $entry) use ($users) {
            $entry['user'] = $entry['user_id'] !== null
                ? ['id' => (int) $entry['user_id'], 'name' => (string) ($users[$entry['user_id']] ?? 'User #'.$entry['user_id'])]
                : null;

            unset($entry['user_id']);

            return $entry;
        })->values();
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }
}
