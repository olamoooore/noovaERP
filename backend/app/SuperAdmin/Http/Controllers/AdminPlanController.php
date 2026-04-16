<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SuperAdmin\Application\PlanCatalogService;
use App\SuperAdmin\Domain\ModuleCatalogEntry;
use App\SuperAdmin\Domain\PlanFamily;
use App\SuperAdmin\Domain\PlanVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPlanController extends Controller
{
    public function __construct(
        private readonly PlanCatalogService $planService,
    ) {}

    public function index(): JsonResponse
    {
        $families = $this->planService->listFamilies();

        return response()->json(['data' => $families]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'         => 'required|string|max:80|unique:plan_families,key|alpha_dash',
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'is_active'   => 'boolean',
            'sort_order'  => 'integer|min:0',
        ]);

        $family = $this->planService->createFamily($data);

        return response()->json(['data' => $family->load('versions')], 201);
    }

    public function show(PlanFamily $family): JsonResponse
    {
        $family->load(['versions.prices', 'versions.planModules.module']);

        return response()->json(['data' => $family]);
    }

    public function update(Request $request, PlanFamily $family): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'description' => 'nullable|string|max:500',
            'is_active'   => 'boolean',
            'sort_order'  => 'integer|min:0',
        ]);

        $family = $this->planService->updateFamily($family, $data);

        return response()->json(['data' => $family->load('versions')]);
    }

    public function destroy(PlanFamily $family): JsonResponse
    {
        // Block deletion if any active subscriptions reference this family's versions
        $activeCount = \App\SuperAdmin\Domain\Subscription::whereIn(
            'plan_version_id',
            $family->versions()->pluck('id'),
        )->whereIn('status', ['active', 'trialing', 'past_due'])->count();

        if ($activeCount > 0) {
            return response()->json([
                'message' => "Cannot delete: {$activeCount} active subscription(s) are on this plan.",
            ], 422);
        }

        $family->delete();

        return response()->json(['message' => 'Plan family deleted.']);
    }

    // --- Versions ---

    public function createVersion(Request $request, PlanFamily $family): JsonResponse
    {
        $data = $request->validate([
            'version_label'      => 'required|string|max:40',
            'description'        => 'nullable|string|max:500',
            'is_active'          => 'boolean',
            'is_publicly_listed' => 'boolean',
        ]);

        $version = $this->planService->createVersion($family, $data);

        return response()->json(['data' => $version], 201);
    }

    public function updateVersion(Request $request, PlanFamily $family, PlanVersion $version): JsonResponse
    {
        $data = $request->validate([
            'version_label'      => 'sometimes|string|max:40',
            'description'        => 'nullable|string|max:500',
            'is_active'          => 'boolean',
            'is_publicly_listed' => 'boolean',
        ]);

        $version = $this->planService->updateVersion($version, $data);

        return response()->json(['data' => $version]);
    }

    // --- Prices ---

    public function setPrices(Request $request, PlanFamily $family, PlanVersion $version): JsonResponse
    {
        $request->validate([
            'prices'                          => 'required|array|min:1',
            'prices.*.billing_interval'       => 'required|in:monthly,yearly',
            'prices.*.unit_amount'            => 'required|integer|min:0',
            'prices.*.currency'               => 'sometimes|string|size:3',
        ]);

        $this->planService->setPrices($version, $request->input('prices'));

        return response()->json(['data' => $version->load('prices')]);
    }

    // --- Module attachments ---

    public function attachModule(
        Request             $request,
        PlanFamily          $family,
        PlanVersion         $version,
        ModuleCatalogEntry  $module,
    ): JsonResponse {
        $this->planService->attachModule($version, $module);

        return response()->json(['data' => $version->load('planModules.module')]);
    }

    public function detachModule(
        PlanFamily         $family,
        PlanVersion        $version,
        ModuleCatalogEntry $module,
    ): JsonResponse {
        $this->planService->detachModule($version, $module);

        return response()->json(['message' => 'Module detached.']);
    }
}
