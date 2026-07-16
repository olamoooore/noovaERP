<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\PlanChangeService;
use App\SuperAdmin\Domain\TenantPlanChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class AdminPlanChangeController extends Controller
{
    public function __construct(private readonly PlanChangeService $service) {}

    /**
     * POST /tenants/{tenantId}/plan-changes
     * Request a plan change for a tenant.
     */
    public function store(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate([
            'to_family_id' => ['required', 'integer', 'exists:plan_families,id'],
            'effective_date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $change = $this->service->requestChange(
                tenantId: $tenantId,
                toFamilyId: (int) $data['to_family_id'],
                effectiveDate: $data['effective_date'],
                reason: $data['reason'] ?? null,
                adminId: Auth::id(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $change], 201);
    }

    /**
     * GET /tenants/{tenantId}/plan-changes
     * Return the full plan change history for one tenant.
     */
    public function history(string $tenantId): JsonResponse
    {
        $changes = $this->service->getChangeHistory($tenantId);

        return response()->json(['data' => $changes]);
    }

    /**
     * POST /tenants/{tenantId}/plan-changes/preview
     * Preview proration amounts before requesting a change.
     */
    public function preview(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate([
            'to_family_id' => ['required', 'integer', 'exists:plan_families,id'],
            'effective_date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $preview = $this->service->previewProration(
            $tenantId,
            (int) $data['to_family_id'],
            $data['effective_date'],
        );

        return response()->json(['data' => $preview]);
    }

    /**
     * GET /plan-changes
     * Return all pending plan changes across all tenants.
     */
    public function pending(): JsonResponse
    {
        $changes = $this->service->getPendingChanges();

        return response()->json(['data' => $changes]);
    }

    /**
     * POST /plan-changes/{change}/apply
     * Apply a pending plan change.
     */
    public function apply(TenantPlanChange $change): JsonResponse
    {
        try {
            $change = $this->service->applyChange($change, Auth::id());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $change]);
    }

    /**
     * POST /plan-changes/{change}/cancel
     * Cancel a pending plan change.
     */
    public function cancel(TenantPlanChange $change): JsonResponse
    {
        try {
            $change = $this->service->cancelChange($change, Auth::id());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $change]);
    }
}
