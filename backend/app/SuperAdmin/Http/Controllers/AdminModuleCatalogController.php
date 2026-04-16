<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\ModuleCatalogEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminModuleCatalogController extends Controller
{
    public function __construct(
        private readonly ModuleCatalogSyncService $syncService,
    ) {}

    public function index(): JsonResponse
    {
        $modules = ModuleCatalogEntry::withCount('planModules')
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $modules]);
    }

    public function show(ModuleCatalogEntry $module): JsonResponse
    {
        $module->load(['dependencies.dependsOn', 'planModules.version.family']);

        return response()->json(['data' => $module]);
    }

    public function sync(): JsonResponse
    {
        $result = $this->syncService->sync();

        return response()->json([
            'message' => "Synced {$result['synced']} modules from manifests.",
            'data'    => $result,
        ]);
    }

    public function update(Request $request, ModuleCatalogEntry $module): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'description' => 'nullable|string|max:500',
            'is_active'   => 'boolean',
            'sort_order'  => 'integer|min:0',
            // module_key is intentionally excluded — it is the identity and must not change
        ]);

        $module->update($data);

        return response()->json(['data' => $module->fresh()]);
    }
}
