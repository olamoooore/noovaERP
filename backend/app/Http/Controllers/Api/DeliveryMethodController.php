<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryMethod;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Issue #409: delivery-method master (fixed price / percentage of the order
 * amount, optional free-above threshold, billed through a service product).
 */
class DeliveryMethodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DeliveryMethod::query()->with('product:id,name,sku')->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $method = DeliveryMethod::create($this->validated($request));

        return response()->json(['data' => $method->load('product:id,name,sku')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $method = DeliveryMethod::findOrFail($id);
        $method->update($this->validated($request, $method));

        return response()->json(['data' => $method->fresh('product:id,name,sku')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $method = DeliveryMethod::findOrFail($id);
        // Documents keep their shipping lines; their delivery_method_id link
        // is cleared by the FK (nullOnDelete).
        $method->delete();

        return response()->json(['message' => 'Delivery method deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?DeliveryMethod $method = null): array
    {
        $partial = $method !== null ? 'sometimes|' : '';

        $validated = $request->validate([
            'name' => $partial.'required|string|max:255',
            'code' => ['nullable', 'string', 'max:50', Rule::unique('delivery_methods', 'code')->ignore($method?->id)],
            'pricing_type' => [$method !== null ? 'sometimes' : 'required', Rule::in(DeliveryMethod::PRICING_TYPES)],
            'fixed_price' => 'nullable|numeric|min:0',
            'percentage' => 'nullable|numeric|min:0|max:100',
            'free_over_amount' => 'nullable|numeric|min:0',
            'product_id' => $partial.'required|integer|exists:products,id',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        // The charge is an ordinary sales line: it must be a non-stock service
        // product so it never moves stock or books COGS.
        if (isset($validated['product_id'])) {
            $product = Product::find($validated['product_id']);
            if ($product === null || $product->type !== Product::TYPE_SERVICE || (bool) $product->track_inventory) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'product_id' => 'The delivery product must be a service product that does not track inventory.',
                ]);
            }
        }

        foreach (['fixed_price', 'percentage'] as $amount) {
            if (array_key_exists($amount, $validated) && $validated[$amount] === null) {
                $validated[$amount] = 0;
            }
        }

        return $validated;
    }
}
