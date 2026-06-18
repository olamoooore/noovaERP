<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialClose\Services\FinancialCloseCycleService;
use Modules\FinancialClose\Services\FinancialCloseChecklistService;
use Modules\FinancialClose\Http\Resources\CloseCycleResource;
use Modules\FinancialClose\Jobs\GenerateTasksFromChecklistJob;

class CloseCycleController extends Controller
{
    public function __construct(
        private readonly FinancialCloseCycleService $cycleService,
        private readonly FinancialCloseChecklistService $checklistService,
    ) {}

    /** GET /cycles?entity_id= */
    public function index(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $entityId = $request->query('entity_id', '');

        $cycles = $this->cycleService->listCycles($tenantId, $entityId, $request->only('status'));

        return response()->json(['data' => $cycles]);
    }

    /** POST /cycles */
    public function store(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $validated = $request->validate([
            'entity_id'          => ['required', 'string'],
            'period_id'          => ['required', 'string'],
            'template_id'        => ['nullable', 'string'],
            'name'               => ['nullable', 'string', 'max:255'],
            'accounting_book_id' => ['nullable', 'string'],
        ]);

        $cycle = $this->cycleService->createCycle(
            $tenantId,
            $validated['entity_id'],
            $validated['period_id'],
            $userId,
            $validated['template_id'] ?? null,
            $validated['name'] ?? null,
            $validated['accounting_book_id'] ?? null,
        );

        if (!$cycle) {
            return response()->json(['message' => 'An active cycle already exists for this period.'], 409);
        }

        return response()->json(['data' => new CloseCycleResource($cycle)], 201);
    }

    /** POST /cycles/{cycleId}/open */
    public function open(Request $request, string $cycleId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $success = $this->cycleService->openCycle($tenantId, $cycleId, $userId);

        if (!$success) {
            return response()->json(['message' => 'Cycle cannot be opened. It must be in draft status.'], 422);
        }

        return response()->json(['message' => 'Cycle opened.']);
    }

    /** POST /cycles/{cycleId}/close */
    public function close(Request $request, string $cycleId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        // Evaluate readiness before attempting close
        $evaluation = $this->cycleService->evaluateReadiness($tenantId, $cycleId);

        if (!$evaluation['ready_to_close']) {
            return response()->json([
                'message'    => 'Cycle is not ready to close.',
                'evaluation' => $evaluation,
            ], 422);
        }

        $success = $this->cycleService->closeCycle($tenantId, $cycleId, $userId);

        if (!$success) {
            return response()->json(['message' => 'Cycle cannot be closed.'], 422);
        }

        return response()->json(['message' => 'Cycle closed.']);
    }

    /** POST /cycles/{cycleId}/signoff — record cycle-level sign-off */
    public function signoff(Request $request, string $cycleId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $result = $this->cycleService->signoffCycle(
            $tenantId,
            $cycleId,
            $userId,
            $request->input('role'),
            $request->input('notes'),
        );

        if (!$result['success']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        return response()->json([
            'message'       => 'Cycle sign-off recorded.',
            'signoff_count' => $result['signoff_count'],
            'ready_to_close'=> $result['ready_to_close'],
            'evaluation'    => $result['evaluation'],
        ], 201);
    }

    /** POST /cycles/{cycleId}/revoke-signoff */
    public function revokeSignoff(Request $request, string $cycleId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $result = $this->cycleService->revokeCycleSignoff($tenantId, $cycleId, $userId);

        if (!$result['success']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        return response()->json(['message' => 'Sign-off revoked.']);
    }

    /** GET /cycles/{cycleId}/readiness — evaluate close readiness */
    public function readiness(Request $request, string $cycleId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $evaluation = $this->cycleService->evaluateReadiness($tenantId, $cycleId);

        return response()->json(['data' => $evaluation]);
    }

    /** POST /cycles/{cycleId}/generate-tasks */
    public function generateTasks(Request $request, string $cycleId): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $cycle    = $this->cycleService->getCycle($tenantId, $cycleId);

        if (!$cycle) {
            return response()->json(['message' => 'Cycle not found.'], 404);
        }

        $templateId = $cycle->template_id;

        if (!$templateId) {
            $validated = $request->validate([
                'template_id' => ['required', 'string'],
            ]);
            $templateId = $validated['template_id'];
            $cycle->update(['template_id' => $templateId]);
        }

        $result = $this->checklistService->expandTemplateIntoTasks($tenantId, $cycleId, $templateId, auth()->id());

        if (!$result) {
            return response()->json(['message' => 'Task generation failed. Ensure the template is active.'], 422);
        }

        return response()->json([
            'message' => 'Tasks generated successfully.',
            'cycle_id' => $cycleId,
            'template_id' => $templateId,
        ]);
    }

    /** POST /cycles/reopen-period — reopen a locked fiscal period */
    public function reopenPeriod(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $userId   = $request->user()->id;

        $validated = $request->validate([
            'period_code' => ['required', 'string'],
        ]);

        // Resolve the user's role name for authorisation
        $role = null;
        $user = $request->user();
        if (method_exists($user, 'getRoleNames')) {
            $roles = $user->getRoleNames();
            $role  = is_array($roles) ? ($roles[0] ?? null) : $roles->first() ?? null;
        }
        // Fallback: check the 'role' string column on the users table
        if (!$role && $user) {
            $role = $user->role ?? null;
        }

        $result = $this->cycleService->reopenPeriod(
            $tenantId,
            $validated['period_code'],
            $userId,
            $role,
        );

        if (!$result['success']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        return response()->json(['message' => $result['message']]);
    }
}
