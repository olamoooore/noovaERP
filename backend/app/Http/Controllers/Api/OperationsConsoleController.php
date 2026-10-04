<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Platform\Exceptions\ExceptionAccessPolicy;
use App\Platform\Exceptions\ExceptionConsoleService;
use App\Platform\Exceptions\ExceptionQueue;
use App\Platform\Exceptions\ExceptionRetryRegistry;
use App\Platform\Exceptions\ExceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Issue #914: the operations console over #902's platform exception queue.
 *
 * One listing for every typed operational failure (failed postings, e-invoice
 * rejections, notification failures, tax variances, audit-chain breaks):
 * filters by type/module/entity/status/owner, grouped stats, SLA age vs the
 * per-severity targets, owner assignment and a one-click retry that invokes
 * the retry handler registered in ExceptionRetryRegistry.
 *
 * Authorization is PER EXCEPTION TYPE and depends on the row (`owner_role`),
 * so it runs here instead of on the route: ExceptionAccessPolicy maps the
 * owner role onto existing seeded permissions (see that class). List/stats
 * are scoped in SQL; show/assign/retry answer 403 for a type the caller does
 * not own.
 */
class OperationsConsoleController extends Controller
{
    public function __construct(
        private readonly ExceptionService $exceptions,
        private readonly ExceptionConsoleService $console,
        private readonly ExceptionAccessPolicy $access,
    ) {}

    /**
     * GET /api/v1/platform/exceptions
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);
        $perPage = min(max($perPage, 1), 100);

        $page = $this->console->paginate(
            $this->filters($request),
            $this->access->readableOwnerRoles($request),
            $this->access->canReadUnowned($request),
            $perPage,
        );

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (array $row) => $this->withCapabilities($request, $row))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/platform/exceptions/stats
     */
    public function stats(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->console->stats(
                $this->filters($request),
                $this->access->readableOwnerRoles($request),
                $this->access->canReadUnowned($request),
            ),
        ]);
    }

    /**
     * GET /api/v1/platform/exceptions/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $exception = $this->find($id);

        if (! $this->access->canRead($request, $exception->owner_role)) {
            return $this->forbiddenResponse($this->access->readPermission($exception->owner_role), 'view');
        }

        return response()->json(['data' => $this->withCapabilities($request, $this->console->present($exception))]);
    }

    /**
     * POST /api/v1/platform/exceptions/{id}/assign
     */
    public function assign(Request $request, string $id): JsonResponse
    {
        $exception = $this->find($id);

        if (! $this->access->canWrite($request, $exception->owner_role)) {
            return $this->forbiddenResponse($this->access->writePermission($exception->owner_role), 'assign');
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $isMember = DB::table('public.user_company_access')
            ->where('tenant_id', tenant()->id)
            ->where('user_id', $validated['user_id'])
            ->exists();

        if (! $isMember) {
            return response()->json([
                'message' => 'The selected user is not a member of this workspace.',
            ], 422);
        }

        $this->exceptions->assign($id, (int) $validated['user_id']);

        return response()->json(['data' => $this->withCapabilities($request, $this->console->present($this->find($id)))]);
    }

    /**
     * POST /api/v1/platform/exceptions/{id}/retry
     *
     * Re-runs the operation behind the queued exception through the handler
     * registered in ExceptionRetryRegistry and reports the outcome. Rows
     * without a registered handler (review-only exceptions) are not retryable.
     */
    public function retry(Request $request, string $id): JsonResponse
    {
        $exception = $this->find($id);

        if (! $this->access->canWrite($request, $exception->owner_role)) {
            return $this->forbiddenResponse($this->access->writePermission($exception->owner_role), 'retry');
        }

        if ($exception->status === 'resolved') {
            return response()->json([
                'message' => 'The exception is already resolved.',
            ], 422);
        }

        $handler = (string) $exception->retry_handler;

        if ($handler === '' || ! app(ExceptionRetryRegistry::class)->has($handler)) {
            return response()->json([
                'message' => 'No retry handler is registered for this exception type.',
            ], 422);
        }

        $this->exceptions->retry($id);

        $fresh = $this->find($id);
        $failed = $fresh->status !== 'resolved';

        return response()->json([
            'data' => $this->withCapabilities($request, $this->console->present($fresh)),
            'retry' => [
                'handler' => $handler,
                'outcome' => $failed ? 'failed' : 'resolved',
                'error' => $failed ? ($fresh->context['last_retry_error'] ?? null) : null,
            ],
        ]);
    }

    /**
     * Per-row action capability for the console UI: assignment follows the
     * owner role's WRITE permission; retry additionally requires a registered
     * handler and an unresolved record.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withCapabilities(Request $request, array $row): array
    {
        $ownerRole = $row['owner_role'] ?? null;
        $canWrite = $this->access->canWrite($request, $ownerRole);
        $handler = (string) ($row['retry_handler'] ?? '');

        $row['can_assign'] = $canWrite;
        $row['can_retry'] = $canWrite
            && ($row['status'] ?? null) !== 'resolved'
            && $handler !== ''
            && app(ExceptionRetryRegistry::class)->has($handler);

        return $row;
    }

    private function find(string $id): ExceptionQueue
    {
        return ExceptionQueue::query()
            ->where('tenant_id', tenant()->id)
            ->findOrFail($id);
    }

    private function forbiddenResponse(string $permission, string $action): JsonResponse
    {
        return response()->json([
            'message' => "You do not have permission to {$action} this exception type.",
            'required_permission' => $permission,
        ], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $filters = [
            'type' => $request->query('type'),
            'module' => $request->query('module'),
            'status' => $request->query('status'),
            'owner' => $request->query('owner'),
            'severity' => $request->query('severity'),
            'assigned_to' => $request->query('assigned_to'),
            'document_id' => $request->query('document_id'),
            'search' => $request->query('search'),
            'legal_entity_id' => $request->query('legal_entity_id') ?? $request->query('entity_id'),
        ];

        return array_filter($filters, fn ($value) => $value !== null && $value !== '');
    }
}
