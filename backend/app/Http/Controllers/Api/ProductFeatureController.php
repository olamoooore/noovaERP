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

        $slug = Str::slug($validated['name']);
        $baseSlug = $slug;
        $counter = 1;
        while (ProductFeature::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        $feature = ProductFeature::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'type' => $validated['type'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (! empty($validated['values'])) {
            foreach ($validated['values'] as $val) {
                $feature->values()->create($val);
            }
        }

        return response()->json($feature->load('values'), 201);
    }

    public function update(Request $request, int $id)
    {
        $productFeature = ProductFeature::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'type' => 'string|in:text,select,toggle',
            'is_active' => 'boolean',
            'values' => 'nullable|array',
            'values.*.id' => 'nullable|integer',
            'values.*.value' => 'required|string',
        ]);

        $updateData = [];
        if (isset($validated['name'])) {
            $updateData['name'] = $validated['name'];
            $newSlug = Str::slug($validated['name']);
            if ($newSlug !== $productFeature->slug && ! ProductFeature::where('slug', $newSlug)->where('id', '!=', $productFeature->id)->exists()) {
                $updateData['slug'] = $newSlug;
            }
        }
        if (isset($validated['type'])) {
            $updateData['type'] = $validated['type'];
        }
        if (isset($validated['is_active'])) {
            $updateData['is_active'] = $validated['is_active'];
        }
        if (! empty($updateData)) {
            $productFeature->update($updateData);
        }

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

    public function destroy(int $id)
    {
        $productFeature = ProductFeature::findOrFail($id);
        $productFeature->delete();

        return response()->json(['message' => 'Feature deleted successfully']);
    }
}
