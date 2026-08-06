<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BonusScheme;
use App\Models\BonusSchemeProduct;
use App\Models\CustomerBonusScheme;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BonusSchemeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BonusScheme::with(['products.qualifyingProduct', 'products.focProduct', 'createdBy', 'customerAssignments.customer'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('scheme_code', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%");
            });
        }

        $schemes = $query->paginate($request->get('per_page', 25));

        return response()->json($schemes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'sometimes|in:draft,active,inactive,expired',
            'products' => 'nullable|array',
            'products.*.qualifying_product_id' => 'required_with:products|exists:products,id',
            'products.*.scheme_type' => 'required_with:products|in:fixed_discount,percentage_discount,buy_x_get_y',
            'products.*.discount_amount' => 'nullable|numeric|min:0',
            'products.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'products.*.minimum_purchase_qty' => 'nullable|numeric|min:0',
            'products.*.foc_product_id' => 'nullable|exists:products,id',
            'products.*.foc_qty' => 'nullable|numeric|min:0',
            'products.*.priority' => 'nullable|integer|min:0',
        ]);

        try {
            $scheme = DB::transaction(function () use ($validated, $request) {
                $scheme = BonusScheme::create([
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'] ?? null,
                    'status' => $validated['status'] ?? 'draft',
                    'created_by' => $request->user()->id,
                ]);

                if (!empty($validated['products'])) {
                    foreach ($validated['products'] as $product) {
                        BonusSchemeProduct::create([
                            'bonus_scheme_id' => $scheme->id,
                            'qualifying_product_id' => $product['qualifying_product_id'],
                            'scheme_type' => $product['scheme_type'],
                            'discount_amount' => $product['discount_amount'] ?? null,
                            'discount_percentage' => $product['discount_percentage'] ?? null,
                            'minimum_purchase_qty' => $product['minimum_purchase_qty'] ?? null,
                            'foc_product_id' => $product['foc_product_id'] ?? null,
                            'foc_qty' => $product['foc_qty'] ?? null,
                            'priority' => $product['priority'] ?? 0,
                        ]);
                    }
                }

                return $scheme;
            });

            return response()->json([
                'message' => 'Bonus scheme created successfully',
                'data' => $scheme->load(['products.qualifyingProduct', 'products.focProduct']),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create bonus scheme',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        $scheme = BonusScheme::with([
            'products.qualifyingProduct',
            'products.focProduct',
            'customerAssignments.customer',
            'createdBy',
        ])->find($id);

        if (!$scheme) {
            return response()->json(['message' => 'Bonus scheme not found'], 404);
        }

        return response()->json($scheme);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $scheme = BonusScheme::find($id);

        if (!$scheme) {
            return response()->json(['message' => 'Bonus scheme not found'], 404);
        }

        if (in_array($scheme->status, ['active', 'expired'])) {
            return response()->json(['message' => 'Cannot edit an active or expired scheme'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'sometimes|in:draft,active,inactive,expired',
            'products' => 'nullable|array',
            'products.*.id' => 'nullable|integer',
            'products.*.qualifying_product_id' => 'required_with:products|exists:products,id',
            'products.*.scheme_type' => 'required_with:products|in:fixed_discount,percentage_discount,buy_x_get_y',
            'products.*.discount_amount' => 'nullable|numeric|min:0',
            'products.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'products.*.minimum_purchase_qty' => 'nullable|numeric|min:0',
            'products.*.foc_product_id' => 'nullable|exists:products,id',
            'products.*.foc_qty' => 'nullable|numeric|min:0',
            'products.*.priority' => 'nullable|integer|min:0',
        ]);

        try {
            DB::transaction(function () use ($scheme, $validated) {
                $scheme->update(collect($validated)->only(['name', 'description', 'start_date', 'end_date', 'status'])->toArray());

                if (isset($validated['products'])) {
                    $existingIds = collect($validated['products'])->pluck('id')->filter()->toArray();
                    $scheme->products()->whereNotIn('id', $existingIds)->delete();

                    foreach ($validated['products'] as $productData) {
                        if (!empty($productData['id'])) {
                            $product = BonusSchemeProduct::find($productData['id']);
                            if ($product) {
                                $product->update(collect($productData)->except(['id'])->toArray());
                            }
                        } else {
                            BonusSchemeProduct::create(array_merge(
                                $productData,
                                ['bonus_scheme_id' => $scheme->id]
                            ));
                        }
                    }
                }
            });

            return response()->json([
                'message' => 'Bonus scheme updated successfully',
                'data' => $scheme->fresh()->load(['products.qualifyingProduct', 'products.focProduct']),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update bonus scheme',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        $scheme = BonusScheme::find($id);

        if (!$scheme) {
            return response()->json(['message' => 'Bonus scheme not found'], 404);
        }

        if ($scheme->customerAssignments()->exists()) {
            return response()->json(['message' => 'Cannot delete a scheme with customer assignments'], 403);
        }

        try {
            $scheme->delete();

            return response()->json(['message' => 'Bonus scheme deleted successfully']);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to delete bonus scheme',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function activate($id): JsonResponse
    {
        $scheme = BonusScheme::find($id);

        if (!$scheme) {
            return response()->json(['message' => 'Bonus scheme not found'], 404);
        }

        if ($scheme->status !== 'draft') {
            return response()->json(['message' => 'Only draft schemes can be activated'], 403);
        }

        try {
            $scheme->update(['status' => 'active']);

            return response()->json([
                'message' => 'Bonus scheme activated successfully',
                'data' => $scheme->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to activate bonus scheme',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deactivate($id): JsonResponse
    {
        $scheme = BonusScheme::find($id);

        if (!$scheme) {
            return response()->json(['message' => 'Bonus scheme not found'], 404);
        }

        if ($scheme->status !== 'active') {
            return response()->json(['message' => 'Only active schemes can be deactivated'], 403);
        }

        try {
            $scheme->update(['status' => 'inactive']);

            return response()->json([
                'message' => 'Bonus scheme deactivated successfully',
                'data' => $scheme->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to deactivate bonus scheme',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
