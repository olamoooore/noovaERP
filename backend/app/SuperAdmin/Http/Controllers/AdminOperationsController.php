<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Jobs\HealthCheckBatchJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Tenant;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class AdminOperationsController extends Controller
{
    /** POST /admin/operations/bulk-health — dispatch queued batch job */
    public function bulkHealth(Request $request): JsonResponse
    {
        Gate::authorize('run-bulk-operations');

        $validated = $request->validate([
            'tenant_ids'   => ['nullable', 'array', 'max:200'],
            'tenant_ids.*' => ['string'],
        ]);

        // If no specific tenants given, ping all active tenants
        if (empty($validated['tenant_ids'])) {
            $tenantIds = Tenant::pluck('id')->toArray();
        } else {
            $tenantIds = $validated['tenant_ids'];
        }

        if (count($tenantIds) === 0) {
            return response()->json(['message' => 'No tenants to check.'], 422);
        }

        $batchId = Str::uuid()->toString();

        HealthCheckBatchJob::dispatch($tenantIds, $batchId, TenantUserHelper::resolveTenantUserId())
            ->onQueue('operations');

        return response()->json([
            'data' => [
                'batch_id' => $batchId,
                'total'    => count($tenantIds),
                'status'   => 'queued',
            ],
        ], 202);
    }

    /** GET /admin/operations/batch/{batchId} — poll job progress */
    public function batchStatus(string $batchId): JsonResponse
    {
        Gate::authorize('run-bulk-operations');

        $status = Cache::get("health_batch:{$batchId}");

        if ($status === null) {
            return response()->json(['message' => 'Batch not found.'], 404);
        }

        return response()->json(['data' => $status]);
    }
}
