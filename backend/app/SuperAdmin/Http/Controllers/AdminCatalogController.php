<?php

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Domain\CatalogDependency;
use App\SuperAdmin\Domain\CatalogIncompatibility;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\CatalogVisibilityRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCatalogController
{
    public function __construct(private readonly CatalogService $catalog) {}

    // =========================================================================
    // Item Types
    // =========================================================================

    public function listTypes(): JsonResponse
    {
        return response()->json([
            'data' => $this->catalog->listTypes(),
        ]);
    }

    public function createType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'         => 'required|string|alpha_dash|unique:catalog_item_types,key',
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $type = $this->catalog->createType($data);

        return response()->json(['data' => $type], 201);
    }

    // =========================================================================
    // Catalog Items — CRUD
    // =========================================================================

    /**
     * GET /catalog
     * Returns full tree: top-level items with nested children.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->catalog->listTree(),
        ]);
    }

    /**
     * GET /catalog/flat
     * Flat list for dropdowns / parent selectors.
     */
    public function flat(): JsonResponse
    {
        return response()->json([
            'data' => $this->catalog->listFlat(),
        ]);
    }

    /**
     * POST /catalog
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'            => 'required|string|max:120|unique:catalog_items,key',
            'name'           => 'required|string|max:150',
            'description'    => 'nullable|string',
            'type_id'        => 'required|integer|exists:catalog_item_types,id',
            'parent_id'      => 'nullable|integer|exists:catalog_items,id',
            'is_active'      => 'nullable|boolean',
            'sort_order'     => 'nullable|integer|min:0',
            'metadata'       => 'nullable|array',
            'effective_from' => 'nullable|date',
            'effective_to'   => 'nullable|date|after:effective_from',
        ]);

        try {
            $item = $this->catalog->createItem($data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $item], 201);
    }

    /**
     * GET /catalog/{item}
     */
    public function show(CatalogItem $item): JsonResponse
    {
        $item->load([
            'type',
            'parent',
            'children.type',
            'dependencies.dependsOn.type',
            'incompatibilities.incompatibleItem.type',
            'visibilityRules',
        ]);

        return response()->json(['data' => $item]);
    }

    /**
     * PUT /catalog/{item}
     */
    public function update(Request $request, CatalogItem $item): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'sometimes|string|max:150',
            'description'    => 'nullable|string',
            'type_id'        => 'sometimes|integer|exists:catalog_item_types,id',
            'parent_id'      => 'nullable|integer|exists:catalog_items,id',
            'is_active'      => 'sometimes|boolean',
            'sort_order'     => 'sometimes|integer|min:0',
            'metadata'       => 'nullable|array',
            'effective_from' => 'sometimes|date',
            'effective_to'   => 'nullable|date',
        ]);

        $updated = $this->catalog->updateItem($item, $data);

        return response()->json(['data' => $updated]);
    }

    /**
     * DELETE /catalog/{item}
     * Soft deactivation only — sets effective_to + is_active = false.
     */
    public function deactivate(CatalogItem $item): JsonResponse
    {
        $deactivated = $this->catalog->deactivateItem($item);

        return response()->json(['data' => $deactivated]);
    }

    // =========================================================================
    // Dependencies
    // =========================================================================

    public function addDependency(Request $request, CatalogItem $item): JsonResponse
    {
        $data = $request->validate([
            'depends_on_item_id' => 'required|integer|exists:catalog_items,id|different:item',
            'type'               => 'nullable|in:required,optional',
        ]);

        $dependsOn = CatalogItem::findOrFail($data['depends_on_item_id']);

        try {
            $dep = $this->catalog->addDependency($item, $dependsOn, $data['type'] ?? 'required');
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $dep], 201);
    }

    public function removeDependency(CatalogDependency $dependency): JsonResponse
    {
        $this->catalog->removeDependency($dependency);

        return response()->json(null, 204);
    }

    // =========================================================================
    // Incompatibilities
    // =========================================================================

    public function addIncompatibility(Request $request, CatalogItem $item): JsonResponse
    {
        $data = $request->validate([
            'incompatible_item_id' => 'required|integer|exists:catalog_items,id|different:item',
            'reason'               => 'nullable|string',
        ]);

        $incompatibleWith = CatalogItem::findOrFail($data['incompatible_item_id']);

        $incompat = $this->catalog->addIncompatibility($item, $incompatibleWith, $data['reason'] ?? null);

        return response()->json(['data' => $incompat], 201);
    }

    public function removeIncompatibility(CatalogIncompatibility $incompatibility): JsonResponse
    {
        $this->catalog->removeIncompatibility($incompatibility);

        return response()->json(null, 204);
    }

    // =========================================================================
    // Visibility Rules
    // =========================================================================

    public function addVisibilityRule(Request $request, CatalogItem $item): JsonResponse
    {
        $data = $request->validate([
            'rule_type' => 'required|string|max:80',
            'condition' => 'required|array',
        ]);

        $rule = $this->catalog->addVisibilityRule($item, $data['rule_type'], $data['condition']);

        return response()->json(['data' => $rule], 201);
    }

    public function removeVisibilityRule(CatalogVisibilityRule $rule): JsonResponse
    {
        $this->catalog->removeVisibilityRule($rule);

        return response()->json(null, 204);
    }

    // =========================================================================
    // Legacy Sync
    // =========================================================================

    public function syncFromLegacy(): JsonResponse
    {
        $result = $this->catalog->syncFromLegacyModuleCatalog();

        return response()->json(['data' => $result]);
    }
}
