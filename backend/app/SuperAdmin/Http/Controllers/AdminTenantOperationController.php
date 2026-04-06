<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\TenantOperationService;
use App\SuperAdmin\Domain\TenantOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminTenantOperationController extends Controller
{
    public function __construct(
        private readonly TenantOperationService $operationService,
    ) {}

    public function index(string $tenantId): JsonResponse
    {
        $operations = TenantOperation::where('tenant_id', $tenantId)
            ->with('adminUser:id,name,email')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => $operations->items(),
            'meta' => [
                'total'     => $operations->total(),
                'page'      => $operations->currentPage(),
                'per_page'  => $operations->perPage(),
                'last_page' => $operations->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'operation' => ['required', 'string', 'in:suspend,reinstate,ping_health,migrate_plan,run_seeder'],
            'reason'    => ['nullable', 'string', 'max:500'],
            'payload'   => ['nullable', 'array'],
        ]);

        $operation = $this->operationService->runOperation(
            tenantId: $tenantId,
            operation: $validated['operation'],
            payload: $validated['payload'] ?? [],
            reason: $validated['reason'] ?? null,
        );

        return response()->json(['data' => $operation->load('adminUser:id,name,email')], 201);
    }
}
