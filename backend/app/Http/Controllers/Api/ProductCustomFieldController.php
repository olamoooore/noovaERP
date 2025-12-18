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

        $field = ProductCustomField::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'type' => $validated['type'],
            'is_required' => $validated['is_required'] ?? false,
            'default_value' => $validated['default_value'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json($field, 201);
    }

    public function update(Request $request, ProductCustomField $productCustomField)
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'type' => 'string|in:text,number,date,boolean',
            'is_required' => 'boolean',
            'default_value' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $productCustomField->update([
            'name' => $validated['name'] ?? $productCustomField->name,
            'slug' => isset($validated['name']) ? Str::slug($validated['name']) : $productCustomField->slug,
            'type' => $validated['type'] ?? $productCustomField->type,
            'is_required' => $validated['is_required'] ?? $productCustomField->is_required,
            'default_value' => $validated['default_value'] ?? $productCustomField->default_value,
            'is_active' => $validated['is_active'] ?? $productCustomField->is_active,
        ]);

        return response()->json($productCustomField);
    }

    public function destroy(ProductCustomField $productCustomField)
    {
        $productCustomField->delete();
        return response()->json(['message' => 'Custom field deleted successfully']);
    }
}
