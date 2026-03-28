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

        $slug = Str::slug($validated['name']);
        $baseSlug = $slug;
        $counter = 1;
        while (ProductAttribute::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        $attribute = ProductAttribute::create([
            'name' => $validated['name'],
            'slug' => $slug,
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

    public function update(Request $request, int $id)
    {
        $productAttribute = ProductAttribute::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'type' => 'string|in:text,select,color,radio',
            'is_active' => 'boolean',
            'values' => 'nullable|array',
            'values.*.id' => 'nullable|integer',
            'values.*.value' => 'required|string',
            'values.*.color_code' => 'nullable|string',
        ]);

        $updateData = [];
        if (isset($validated['name'])) {
            $updateData['name'] = $validated['name'];
            $newSlug = Str::slug($validated['name']);
            if ($newSlug !== $productAttribute->slug && !ProductAttribute::where('slug', $newSlug)->where('id', '!=', $productAttribute->id)->exists()) {
                $updateData['slug'] = $newSlug;
            }
        }
        if (isset($validated['type'])) $updateData['type'] = $validated['type'];
        if (isset($validated['is_active'])) $updateData['is_active'] = $validated['is_active'];
        if (!empty($updateData)) $productAttribute->update($updateData);

        if (isset($validated['values'])) {
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

    public function destroy(int $id)
    {
        $productAttribute = ProductAttribute::findOrFail($id);
        $productAttribute->delete();
        return response()->json(['message' => 'Attribute deleted successfully']);
    }
}
