<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function index()
    {
        return response()->json(
            ProductCategory::query()
                ->with('children')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:product_categories,id',
            'income_account_id' => 'nullable|exists:chart_of_accounts,id',
            'is_active' => 'boolean',
        ]);

        $category = ProductCategory::create($validated);

        return response()->json($category, 201);
    }

    public function update(Request $request, int $id)
    {
        $productCategory = ProductCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'parent_id' => 'nullable|exists:product_categories,id',
            'income_account_id' => 'nullable|exists:chart_of_accounts,id',
            'is_active' => 'boolean',
        ]);

        // Issue #133: a category may not become its own ancestor — reject
        // self-parenting and re-parenting under one of its own descendants.
        if (array_key_exists('parent_id', $validated) && $validated['parent_id'] !== null) {
            $cycleError = $this->parentCycleError($productCategory->id, (int) $validated['parent_id']);
            if ($cycleError !== null) {
                return response()->json(['message' => $cycleError], 422);
            }
        }

        $productCategory->update($validated);

        return response()->json($productCategory->fresh());
    }

    public function destroy(int $id)
    {
        $productCategory = ProductCategory::findOrFail($id);

        // Deleting a parent silently orphans its sub-tree, and deleting a
        // category still in use leaves products pointing at a deleted row.
        $children = ProductCategory::where('parent_id', $productCategory->id)->count();
        $products = Product::where('category_id', $productCategory->id)->count();

        if ($children > 0 || $products > 0) {
            return response()->json([
                'message' => "Cannot delete this category: {$children} sub-category(ies) and {$products} product(s) still reference it. Reassign them first.",
            ], 422);
        }

        $productCategory->delete();

        return response()->json(['message' => 'Category deleted successfully']);
    }

    /**
     * Issue #133: returns null when the proposed parent is safe, otherwise the
     * reason the move would create a cycle. Walks the proposed parent's
     * ancestor chain, guarding against a pre-existing cycle in the data.
     */
    private function parentCycleError(int $categoryId, int $proposedParentId): ?string
    {
        if ($proposedParentId === $categoryId) {
            return 'A category cannot be its own parent.';
        }

        $visited = [];
        $cursor = ProductCategory::find($proposedParentId);

        while ($cursor !== null) {
            if ($cursor->id === $categoryId) {
                return 'The selected parent is a descendant of this category — that would create a cycle.';
            }
            if (isset($visited[$cursor->id])) {
                return 'The existing category hierarchy contains a cycle; fix the parent chain before updating.';
            }
            $visited[$cursor->id] = true;
            $cursor = $cursor->parent_id ? ProductCategory::find($cursor->parent_id) : null;
        }

        return null;
    }
}
