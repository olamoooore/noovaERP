<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductUnit;
use Illuminate\Http\Request;

class ProductUnitController extends Controller
{
    public function index()
    {
        return response()->json(ProductUnit::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:50',
            'allow_decimal' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $unit = ProductUnit::create($validated);
        return response()->json($unit, 201);
    }

    public function update(Request $request, int $id)
    {
        $productUnit = ProductUnit::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'short_name' => 'nullable|string|max:50',
            'allow_decimal' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $productUnit->update($validated);
        return response()->json($productUnit);
    }

    public function destroy(int $id)
    {
        $productUnit = ProductUnit::findOrFail($id);
        $productUnit->delete();
        return response()->json(['message' => 'Unit deleted successfully']);
    }
}
