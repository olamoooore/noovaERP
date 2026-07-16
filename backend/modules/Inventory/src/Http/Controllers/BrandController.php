<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Modules\Inventory\Models\Brand;

class BrandController extends Controller
{
    public function index(Request $request)
    {
        $query = Brand::withCount('products')->orderBy('name');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:brands,slug',
            'description' => 'nullable|string',
            'logo' => 'nullable|string',
            'website' => 'nullable|url|max:255',
            'is_active' => 'boolean',
        ]);

        $brand = Brand::create($validated);

        return response()->json($brand, 201);
    }

    public function show(int $brand_id)
    {
        $brand = $this->findBrandOrFail($brand_id);

        return response()->json($brand->load(['products' => fn ($q) => $q->limit(20)]));
    }

    public function update(Request $request, int $brand_id)
    {
        $brand = $this->findBrandOrFail($brand_id);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'slug' => 'nullable|string|max:255|unique:brands,slug,'.$brand->id,
            'description' => 'nullable|string',
            'logo' => 'nullable|string',
            'website' => 'nullable|url|max:255',
            'is_active' => 'boolean',
        ]);

        $brand->update($validated);

        return response()->json($brand);
    }

    public function destroy(int $brand_id)
    {
        $brand = $this->findBrandOrFail($brand_id);

        if ($brand->products()->exists()) {
            return response()->json([
                'message' => 'Cannot delete brand with existing products',
            ], 422);
        }

        $brand->delete();

        return response()->json(['message' => 'Brand deleted successfully']);
    }

    private function findBrandOrFail(int $brandId): Brand
    {
        $brand = Brand::query()->find($brandId);

        if (! $brand) {
            throw (new ModelNotFoundException)->setModel(Brand::class, [$brandId]);
        }

        return $brand;
    }
}
