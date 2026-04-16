<?php

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\PlanFamily;
use App\SuperAdmin\Domain\PlanItemDefault;
use Illuminate\Support\Facades\DB;

class PackagingService
{
    public function __construct(private readonly AdminAuditLogger $audit) {}

    // =========================================================================
    // Matrix Read
    // =========================================================================

    /**
     * Full tier × item matrix for the admin grid UI.
     *
     * Returns:
     * {
     *   tiers: PlanFamily[],
     *   items: CatalogItem[],          // active, effective, ordered
     *   matrix: {                      // keyed by "familyId:itemId"
     *     "1:4": { availability: "included", notes: null },
     *     "1:7": { availability: "add_on",   notes: "charged per seat" },
     *     ...
     *   }
     * }
     */
    public function getMatrix(): array
    {
        $tiers = PlanFamily::orderBy('sort_order')->orderBy('name')->get();

        $items = CatalogItem::with('type')
            ->active()
            ->effective()
            ->ordered()
            ->get();

        $defaults = PlanItemDefault::all()
            ->keyBy(fn (PlanItemDefault $d) => "{$d->plan_family_id}:{$d->catalog_item_id}");

        $matrix = [];
        foreach ($defaults as $key => $default) {
            $matrix[$key] = [
                'id'           => $default->id,
                'availability' => $default->availability,
                'notes'        => $default->notes,
            ];
        }

        return [
            'tiers'  => $tiers,
            'items'  => $items,
            'matrix' => $matrix,
        ];
    }

    /**
     * All configured items for a single tier — useful for tenant config preview.
     */
    public function getDefaultsForTier(PlanFamily $tier): array
    {
        return PlanItemDefault::with('catalogItem.type')
            ->forTier($tier->id)
            ->get()
            ->toArray();
    }

    /**
     * All tier configurations for a single catalog item.
     */
    public function getDefaultsForItem(CatalogItem $item): array
    {
        return PlanItemDefault::with('planFamily')
            ->forItem($item->id)
            ->get()
            ->toArray();
    }

    // =========================================================================
    // Matrix Write
    // =========================================================================

    /**
     * Set (upsert) the availability mode for a tier × item cell.
     * This is the primary write operation — called when an admin clicks a cell.
     */
    public function setDefault(
        PlanFamily   $tier,
        CatalogItem  $item,
        string       $availability,
        ?string      $notes = null,
    ): PlanItemDefault {
        $this->validateAvailability($availability);

        $existing = PlanItemDefault::where('plan_family_id', $tier->id)
            ->where('catalog_item_id', $item->id)
            ->first();

        $before = $existing?->toAuditSnapshot() ?? [];

        $default = DB::transaction(function () use ($tier, $item, $availability, $notes) {
            return PlanItemDefault::updateOrCreate(
                [
                    'plan_family_id' => $tier->id,
                    'catalog_item_id' => $item->id,
                ],
                [
                    'availability' => $availability,
                    'notes'        => $notes,
                ],
            );
        });

        $this->audit->log(
            'packaging.default_set',
            $default,
            $before,
            $default->toAuditSnapshot(),
        );

        return $default->load(['planFamily', 'catalogItem']);
    }

    /**
     * Clear the availability setting for a tier × item cell.
     * Effective availability falls back to 'hidden' (safe default).
     */
    public function clearDefault(PlanFamily $tier, CatalogItem $item): void
    {
        $default = PlanItemDefault::where('plan_family_id', $tier->id)
            ->where('catalog_item_id', $item->id)
            ->first();

        if (! $default) {
            return;
        }

        $snapshot = $default->toAuditSnapshot();
        $default->delete();

        $this->audit->log('packaging.default_cleared', null, $snapshot, []);
    }

    /**
     * Bulk set an entire tier's matrix row in one operation.
     * Accepts: [catalog_item_id => availability, ...]
     * Missing items are left untouched.
     */
    public function bulkSetTier(PlanFamily $tier, array $itemAvailabilities): int
    {
        $count = 0;

        DB::transaction(function () use ($tier, $itemAvailabilities, &$count) {
            foreach ($itemAvailabilities as $catalogItemId => $availability) {
                $this->validateAvailability($availability);

                PlanItemDefault::updateOrCreate(
                    [
                        'plan_family_id'  => $tier->id,
                        'catalog_item_id' => (int) $catalogItemId,
                    ],
                    ['availability' => $availability],
                );

                $count++;
            }
        });

        $this->audit->log(
            'packaging.bulk_set',
            null,
            [],
            ['tier_key' => $tier->key, 'count' => $count],
        );

        return $count;
    }

    // =========================================================================
    // Private
    // =========================================================================

    private function validateAvailability(string $value): void
    {
        if (! in_array($value, PlanItemDefault::AVAILABILITIES, true)) {
            throw new \InvalidArgumentException(
                "Invalid availability \"{$value}\". Must be one of: " .
                implode(', ', PlanItemDefault::AVAILABILITIES)
            );
        }
    }
}
