<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Services\PriceListService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Price Lists admin API (MRD pricelist-multicurrency-mrd FR-2 / FR-11).
 *
 * Routes carry the literal `pricelists.view` / `pricelists.manage`
 * RequirePermission middleware (FR-10.1) — this controller does not fork a
 * parallel RBAC mechanism; cost-detail masking (FR-3.6) is a DATA-SHAPE
 * concern and lives here on top of the same seeded permission.
 */
class PriceListController extends Controller
{
    public function __construct(
        private readonly PriceListService $service,
    ) {}

    public function index(Request $request)
    {
        $query = PriceList::query()
            ->withCount('items')
            ->with('currency')
            ->ordered();

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        if ($request->filled('currency_code')) {
            $query->where('currency_code', strtoupper((string) $request->currency_code));
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->search);
            $query->where(function ($q) use ($term) {
                $q->where('name', 'ilike', "%{$term}%")
                    ->orWhere('code', 'ilike', "%{$term}%");
            });
        }

        return response()->json(
            $query->paginate(min(200, max(1, (int) $request->per_page ?? 25)))
                ->through(fn (PriceList $list) => $this->summary($list))
        );
    }

    public function show($id)
    {
        $list = PriceList::with(['items.product:id,name,sku', 'items.category:id,name', 'applications'])->findOrFail($id);

        return response()->json(['data' => $this->detail($list)]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $list = $this->service->create($validated, $this->actorId());

        return response()->json([
            'message' => 'Price list created',
            'data' => $this->detail($list->load(['items', 'applications'])),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $list = PriceList::findOrFail($id);
        $validated = $this->validatePayload($request, updating: true);

        $updated = $this->service->update($list, $validated, $this->actorId());

        return response()->json([
            'message' => 'Price list updated',
            'data' => $this->detail($updated->load(['items', 'applications'])),
        ]);
    }

    /**
     * PUT /price-lists/{id}/items — ordered full replacement (FR-11).
     */
    public function replaceItems(Request $request, $id)
    {
        $list = PriceList::findOrFail($id);

        $validated = $request->validate([
            'items' => 'present|array',
        ]);

        $this->service->syncItems($list, $request->input('items', []), $this->actorId());

        return response()->json([
            'message' => 'Price list items replaced',
            'data' => $this->detail($list->fresh(['items', 'applications'])),
        ]);
    }

    /**
     * PUT /price-lists/{id}/applications — full replacement (FR-2.2).
     */
    public function replaceApplications(Request $request, $id)
    {
        PriceList::findOrFail($id);

        $request->validate([
            'applications' => 'present|array',
        ]);

        $this->service->syncApplications(PriceList::findOrFail($id), $request->input('applications', []));

        return response()->json([
            'message' => 'Price list applications replaced',
            'data' => $this->detail(PriceList::with(['items', 'applications'])->findOrFail($id)),
        ]);
    }

    public function clone($id)
    {
        $list = PriceList::findOrFail($id);

        $copy = $this->service->clone($list, $this->actorId());

        return response()->json([
            'message' => 'Price list cloned',
            'data' => $this->detail($copy->load(['items', 'applications'])),
        ], 201);
    }

    /**
     * Deactivate (FR-2.5) rather than delete when history must survive.
     */
    public function deactivate($id)
    {
        $list = PriceList::findOrFail($id);
        $before = $list->only(['is_active']);

        $list->update(['is_active' => false]);

        app(\App\Services\AuditLogger::class)->log(
            operation: 'pricelist.deactivated',
            referenceType: 'price_list',
            referenceId: (int) $list->id,
            actorId: $this->actorId(),
            beforeState: $before,
            afterState: ['is_active' => false],
        );

        return response()->json([
            'message' => 'Price list deactivated — historical documents remain reproducible',
            'data' => $this->summary($list),
        ]);
    }

    public function destroy($id)
    {
        $list = PriceList::findOrFail($id);

        $this->service->delete($list, $this->actorId());

        return response()->json(['message' => 'Price list deleted']);
    }

    // ════════════════════════════════════════════════════════════════════

    private function validatePayload(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => ($updating ? 'sometimes|required' : 'required').'|string|max:150',
            'code' => 'nullable|string|max:50',
            'currency_code' => ($updating ? 'sometimes|required' : 'required').'|string|size:3',
            'description' => 'nullable|string',
            'sequence' => 'nullable|integer|min:0',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'nullable|boolean',
            'rounding_enabled' => 'nullable|boolean',
            'rounding_method' => 'nullable|in:up,down,nearest',
            'rounding_precision' => 'nullable|numeric|gt:0',
            'discount_display' => 'nullable|in:show_discount,show_discounted_price',
            'items' => 'sometimes|array',
            'applications' => 'sometimes|array',
        ]);
    }

    private function summary(PriceList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'code' => $list->code,
            'currency_code' => $list->currency_code,
            'sequence' => $list->sequence,
            'valid_from' => $list->valid_from?->toDateString(),
            'valid_until' => $list->valid_until?->toDateString(),
            'is_active' => (bool) $list->is_active,
            'rounding_enabled' => (bool) $list->rounding_enabled,
            'rounding_method' => $list->rounding_method,
            'rounding_precision' => (float) $list->rounding_precision,
            'discount_display' => $list->discount_display,
            'items_count' => $list->items_count ?? $list->items()->count(),
            'applications' => $list->relationLoaded('applications')
                ? $list->applications->map(fn ($a) => [
                    'applies_to_type' => $a->applies_to_type,
                    'applies_to_id' => $a->applies_to_id,
                ])->all()
                : null,
        ];
    }

    private function detail(PriceList $list): array
    {
        $maySeeCost = $this->actorCan('pricelists.cost');

        $items = $list->items->map(function ($item) use ($maySeeCost) {
            $row = [
                'id' => $item->id,
                'sequence' => $item->sequence,
                'apply_on' => $item->apply_on,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'category_id' => $item->category_id,
                'category_name' => $item->category?->name,
                'min_quantity' => (float) $item->min_quantity,
                'max_quantity' => $item->max_quantity !== null ? (float) $item->max_quantity : null,
                'date_start' => $item->date_start?->toDateString(),
                'date_end' => $item->date_end?->toDateString(),
                'compute_type' => $item->compute_type,
                'fixed_price' => $item->fixed_price !== null ? (float) $item->fixed_price : null,
                'percent' => $item->percent !== null ? (float) $item->percent : null,
                'markup_percent' => $item->markup_percent !== null ? (float) $item->markup_percent : null,
            ];

            // FR-3.6: cost-based rule internals stay hidden from roles
            // without the cost capability; they see the computed price only
            // (via /pricing/resolve).
            if ($item->isCostBased() && ! $maySeeCost) {
                $row['compute_type'] = 'restricted';
                $row['base'] = null;
                $row['markup_percent'] = null;
            } else {
                $row['base'] = $item->base;
            }

            $row['other_price_list_id'] = $item->other_price_list_id;

            return $row;
        })->all();

        return array_merge($this->summary($list), [
            'description' => $list->description,
            'items' => $items,
            'applications' => $list->applications->map(fn ($a) => [
                'id' => $a->id,
                'applies_to_type' => $a->applies_to_type,
                'applies_to_id' => $a->applies_to_id,
            ])->all(),
        ]);
    }

    private function actorId(): ?int
    {
        return Auth::id() !== null ? (int) Auth::id() : null;
    }

    private function actorCan(string $permission): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        if ($user->is_super_admin ?? false) {
            return true;
        }

        try {
            return app(\App\Services\TenantRoleService::class)
                ->hasPermission((int) $user->id, (string) tenant('id'), $permission);
        } catch (\Throwable) {
            return false;
        }
    }
}
