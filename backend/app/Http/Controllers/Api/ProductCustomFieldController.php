<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCustomField;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductCustomFieldController extends Controller
{
    public function index()
    {
        return response()->json(ProductCustomField::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:text,number,date,boolean',
            'is_required' => 'boolean',
            'default_value' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $slug = Str::slug($validated['name']);
        $baseSlug = $slug;
        $counter = 1;
        while (ProductCustomField::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        $field = ProductCustomField::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'type' => $validated['type'],
            'is_required' => $validated['is_required'] ?? false,
            'default_value' => $validated['default_value'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json($field, 201);
    }

    public function update(Request $request, int $id)
    {
        $productCustomField = ProductCustomField::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'type' => 'string|in:text,number,date,boolean',
            'is_required' => 'boolean',
            'default_value' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $updateData = [
            'name' => $validated['name'] ?? $productCustomField->name,
            'type' => $validated['type'] ?? $productCustomField->type,
            'is_required' => $validated['is_required'] ?? $productCustomField->is_required,
            'default_value' => array_key_exists('default_value', $validated) ? $validated['default_value'] : $productCustomField->default_value,
            'is_active' => $validated['is_active'] ?? $productCustomField->is_active,
        ];

        if (isset($validated['name'])) {
            $newSlug = Str::slug($validated['name']);
            if ($newSlug !== $productCustomField->slug && !ProductCustomField::where('slug', $newSlug)->where('id', '!=', $productCustomField->id)->exists()) {
                $updateData['slug'] = $newSlug;
            }
        }

        $productCustomField->update($updateData);

        return response()->json($productCustomField->fresh());
    }

    public function destroy(int $id)
    {
        $productCustomField = ProductCustomField::findOrFail($id);
        $productCustomField->delete();
        return response()->json(['message' => 'Custom field deleted successfully']);
    }
}
