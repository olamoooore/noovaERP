<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\TenantOperationService;
use App\SuperAdmin\Application\TenantQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminTenantController extends Controller
{
    public function __construct(
        private readonly TenantQueryService $queryService,
        private readonly TenantOperationService $operationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'   => ['nullable', 'string', 'max:100'],
            'status'   => ['nullable', 'string', 'in:active,suspended,trial'],
            'plan'     => ['nullable', 'string', 'max:50'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $paginator = $this->queryService->listTenants(
            search: $validated['search'] ?? null,
            status: $validated['status'] ?? null,
            plan: $validated['plan'] ?? null,
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 15),
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total'     => $paginator->total(),
                'page'      => $paginator->currentPage(),
                'per_page'  => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $tenantId): JsonResponse
    {
        $detail = $this->queryService->getTenantDetail($tenantId);

        return response()->json(['data' => $detail]);
    }

    public function updateStatus(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:suspended,active'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['status'] === 'suspended') {
            $this->operationService->suspend($tenantId, $validated['reason'] ?? null);
        } else {
            $this->operationService->reinstate($tenantId, $validated['reason'] ?? null);
        }

        $detail = $this->queryService->getTenantDetail($tenantId);

        return response()->json(['data' => $detail]);
    }

    public function latestHealth(string $tenantId): JsonResponse
    {
        $snapshot = $this->queryService->getLatestHealth($tenantId);

        if (is_null($snapshot)) {
            return response()->json(['data' => null, 'message' => 'No health snapshots yet.']);
        }

        return response()->json(['data' => $snapshot]);
    }

    public function pingHealth(string $tenantId): JsonResponse
    {
        $snapshot = $this->operationService->pingHealth($tenantId);

        return response()->json(['data' => $snapshot], 201);
    }
}
