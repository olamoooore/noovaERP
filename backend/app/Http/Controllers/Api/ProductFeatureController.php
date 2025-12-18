<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductFeature;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductFeatureController extends Controller
{
    public function index()
    {
        return response()->json(ProductFeature::with('values')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:text,select,toggle',
            'is_active' => 'boolean',
            'values' => 'nullable|array',
            'values.*.value' => 'required|string',
        ]);

        $feature = ProductFeature::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'type' => $validated['type'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (!empty($validated['values'])) {
            foreach ($validated['values'] as $val) {
                $feature->values()->create($val);
            }
        }

        return response()->json($feature->load('values'), 201);
    }

    public function update(Request $request, ProductFeature $productFeature)
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'type' => 'string|in:text,select,toggle',
            'is_active' => 'boolean',
            'values' => 'nullable|array',
            'values.*.id' => 'nullable|exists:product_feature_options,id',
            'values.*.value' => 'required|string',
        ]);

        $productFeature->update([
            'name' => $validated['name'] ?? $productFeature->name,
            'slug' => isset($validated['name']) ? Str::slug($validated['name']) : $productFeature->slug,
            'type' => $validated['type'] ?? $productFeature->type,
            'is_active' => $validated['is_active'] ?? $productFeature->is_active,
        ]);

        if (isset($validated['values'])) {
            $currentIds = collect($validated['values'])->pluck('id')->filter()->toArray();
            $productFeature->values()->whereNotIn('id', $currentIds)->delete();

            foreach ($validated['values'] as $val) {
                if (isset($val['id'])) {
                    $productFeature->values()->where('id', $val['id'])->update([
                        'value' => $val['value'],
                    ]);
                } else {
                    $productFeature->values()->create([
                        'value' => $val['value'],
                    ]);
                }
            }
        }

        return response()->json($productFeature->load('values'));
    }

    public function destroy(ProductFeature $productFeature)
    {
        $productFeature->delete();
        return response()->json(['message' => 'Feature deleted successfully']);
    }
}
