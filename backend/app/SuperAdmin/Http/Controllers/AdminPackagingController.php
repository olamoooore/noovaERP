<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\PackagingService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\PlanFamily;
use App\SuperAdmin\Domain\PlanItemDefault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPackagingController
{
    public function __construct(private readonly PackagingService $packaging) {}

    /**
     * GET /packaging/matrix
     * Full tier × item grid — tiers as columns, catalog items as rows.
     */
    public function matrix(): JsonResponse
    {
        return response()->json([
            'data' => $this->packaging->getMatrix(),
        ]);
    }

    /**
     * GET /packaging/tiers/{family}
     * All configured items for a single tier.
     */
    public function tierDefaults(PlanFamily $family): JsonResponse
    {
        return response()->json([
            'data' => $this->packaging->getDefaultsForTier($family),
        ]);
    }

    /**
     * GET /packaging/items/{item}
     * All tier configurations for a single catalog item.
     */
    public function itemDefaults(CatalogItem $item): JsonResponse
    {
        return response()->json([
            'data' => $this->packaging->getDefaultsForItem($item),
        ]);
    }

    /**
     * PUT /packaging/matrix/{family}/{item}
     * Set (upsert) a single tier × item cell.
     */
    public function setDefault(Request $request, PlanFamily $family, CatalogItem $item): JsonResponse
    {
        $data = $request->validate([
            'availability' => 'required|in:'.implode(',', PlanItemDefault::AVAILABILITIES),
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $default = $this->packaging->setDefault(
                $family,
                $item,
                $data['availability'],
                $data['notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $default]);
    }

    /**
     * DELETE /packaging/matrix/{family}/{item}
     * Clear a cell — effective availability falls back to 'hidden'.
     */
    public function clearDefault(PlanFamily $family, CatalogItem $item): JsonResponse
    {
        $this->packaging->clearDefault($family, $item);

        return response()->json(null, 204);
    }

    /**
     * POST /packaging/tiers/{family}/bulk
     * Set an entire tier row in one request.
     * Body: { "defaults": { "catalog_item_id": "availability", ... } }
     */
    public function bulkSetTier(Request $request, PlanFamily $family): JsonResponse
    {
        $data = $request->validate([
            'defaults' => 'required|array',
            'defaults.*' => 'required|in:'.implode(',', PlanItemDefault::AVAILABILITIES),
        ]);

        try {
            $count = $this->packaging->bulkSetTier($family, $data['defaults']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['updated' => $count]]);
    }
}
