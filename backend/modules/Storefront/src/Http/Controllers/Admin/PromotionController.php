<?php

namespace Modules\Storefront\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Storefront\Models\StorefrontPromotion;
use Modules\Storefront\Models\StorefrontPromotionRedemption;

/**
 * Promotion CRUD for the tenant admin console (FR-ADM-01/FR-CO-09).
 * Codes are the merchant's lever; the discount MATH is platform code only —
 * this controller stores definitions, never amounts applied to orders.
 */
final class PromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StorefrontPromotion::query()->withCount('redemptions')->latest('id');

        if (($type = $request->string('type')->toString()) !== '' && in_array($type, StorefrontPromotion::TYPES, true)) {
            $query->where('type', $type);
        }
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $page = $query->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map($this->present(...)),
            'meta' => ['page' => $page->currentPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePromotion($request);

        $code = StorefrontPromotion::normalizeCode($validated['code']);
        if (StorefrontPromotion::query()->where('code', $code)->exists()) {
            return response()->json(['message' => 'That promo code already exists.', 'code' => 'promotion_exists'], 409);
        }

        $promotion = StorefrontPromotion::create(array_merge($validated, [
            'code' => $code,
            'is_active' => $validated['is_active'] ?? true,
        ]));

        return response()->json(['data' => $this->present($promotion)], 201);
    }

    public function update(Request $request, int $promotion): JsonResponse
    {
        $model = StorefrontPromotion::query()->find($promotion);
        if ($model === null) {
            return response()->json(['message' => 'Promotion not found.', 'code' => 'not_found'], 404);
        }

        $validated = $this->validatePromotion($request);
        $code = StorefrontPromotion::normalizeCode($validated['code']);

        $clash = StorefrontPromotion::query()->where('code', $code)->where('id', '!=', $model->id)->exists();
        if ($clash) {
            return response()->json(['message' => 'That promo code already exists.', 'code' => 'promotion_exists'], 409);
        }

        $model->update(array_merge($validated, [
            'code' => $code,
            'is_active' => $validated['is_active'] ?? $model->is_active,
        ]));

        return response()->json(['data' => $this->present($model->fresh())]);
    }

    public function redemptions(int $promotion): JsonResponse
    {
        $model = StorefrontPromotion::query()->find($promotion);
        if ($model === null) {
            return response()->json(['message' => 'Promotion not found.', 'code' => 'not_found'], 404);
        }

        $rows = StorefrontPromotionRedemption::query()
            ->where('promotion_id', $model->id)
            ->latest('id')
            ->limit(200)
            ->get(['id', 'order_id', 'account_id', 'amount', 'created_at']);

        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => $r->id,
            'order_id' => $r->order_id,
            'account_id' => $r->account_id,
            'amount' => (string) $r->amount,
            'redeemed_at' => optional($r->created_at)->toIso8601String(),
        ])->all()]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validatePromotion(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'type' => ['required', 'in:'.implode(',', StorefrontPromotion::TYPES)],
            'value' => ['required', 'numeric', 'min:0'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_account_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function present(StorefrontPromotion $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'type' => $p->type,
            'value' => (string) $p->value,
            'min_subtotal' => $p->min_subtotal !== null ? (string) $p->min_subtotal : null,
            'usage_limit' => $p->usage_limit,
            'per_account_limit' => $p->per_account_limit,
            'redemptions_count' => $p->redemptions_count ?? $p->redemptions()->count(),
            'starts_at' => optional($p->starts_at)->toIso8601String(),
            'ends_at' => optional($p->ends_at)->toIso8601String(),
            'is_active' => (bool) $p->is_active,
            'description' => $p->description,
        ];
    }
}
