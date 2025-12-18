<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductAttribute;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductAttributeController extends Controller
{
    public function index()
    {
        return response()->json(ProductAttribute::with('values')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:text,select,color,radio',
            'is_active' => 'boolean',
            'values' => 'nullable|array',
            'values.*.value' => 'required|string',
            'values.*.color_code' => 'nullable|string',
        ]);

        $attribute = ProductAttribute::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'type' => $validated['type'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (!empty($validated['values'])) {
            foreach ($validated['values'] as $val) {
                $attribute->values()->create($val);
            }
        }

        return response()->json($attribute->load('values'), 201);
    }

    public function update(Request $request, ProductAttribute $productAttribute)
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'type' => 'string|in:text,select,color,radio',
            'is_active' => 'boolean',
            'values' => 'nullable|array',
            'values.*.id' => 'nullable|exists:product_attribute_values,id',
            'values.*.value' => 'required|string',
            'values.*.color_code' => 'nullable|string',
        ]);

        $productAttribute->update([
            'name' => $validated['name'] ?? $productAttribute->name,
            'slug' => isset($validated['name']) ? Str::slug($validated['name']) : $productAttribute->slug,
            'type' => $validated['type'] ?? $productAttribute->type,
            'is_active' => $validated['is_active'] ?? $productAttribute->is_active,
        ]);

        if (isset($validated['values'])) {
            // Simple sync strategy: delete all and recreate, or update existing.
            // For simplicity in this iteration, we'll update existing and create new, 
            // but handling deletions requires more logic (e.g. passing IDs to keep).
            // Let's assume the frontend sends all current values.
            
            $currentIds = collect($validated['values'])->pluck('id')->filter()->toArray();
            $productAttribute->values()->whereNotIn('id', $currentIds)->delete();

            foreach ($validated['values'] as $val) {
                if (isset($val['id'])) {
                    $productAttribute->values()->where('id', $val['id'])->update([
                        'value' => $val['value'],
                        'color_code' => $val['color_code'] ?? null,
                    ]);
                } else {
                    $productAttribute->values()->create([
                        'value' => $val['value'],
                        'color_code' => $val['color_code'] ?? null,
                    ]);
                }
            }
        }

        return response()->json($productAttribute->load('values'));
    }

    public function destroy(ProductAttribute $productAttribute)
    {
        $productAttribute->delete();
        return response()->json(['message' => 'Attribute deleted successfully']);
    }
}
