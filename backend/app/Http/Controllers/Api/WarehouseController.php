<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $query = Warehouse::query();

        if ($request->boolean('active_only', false)) {
            $query->where('is_active', true);
        }

        $warehouses = $query->orderBy('name')->get();
        
        // Ensure all expected fields exist (for backwards compatibility)
        return $warehouses->map(function ($warehouse) {
            return [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'address' => $warehouse->address,
                'phone' => $warehouse->phone ?? null,
                'email' => $warehouse->email ?? null,
                'manager_name' => $warehouse->manager_name ?? null,
                'is_active' => $warehouse->is_active ?? true,
                'is_default' => $warehouse->is_default ?? false,
                'created_at' => $warehouse->created_at,
                'updated_at' => $warehouse->updated_at,
            ];
        });
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'manager_name' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        $warehouse = Warehouse::create($validated);

        // If this is set as default, update others
        if ($warehouse->is_default) {
            Warehouse::where('id', '!=', $warehouse->id)->update(['is_default' => false]);
        }

        return response()->json($warehouse, 201);
    }

    public function show(Warehouse $warehouse)
    {
        return response()->json([
            'warehouse' => $warehouse,
            'stats' => [
                'total_stock_count' => $warehouse->getTotalStockCount(),
                'total_stock_value' => $warehouse->getTotalStockValue(),
            ],
        ]);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'manager_name' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        $warehouse->update($validated);

        // If this is set as default, update others
        if (isset($validated['is_default']) && $validated['is_default']) {
            Warehouse::where('id', '!=', $warehouse->id)->update(['is_default' => false]);
        }

        return $warehouse;
    }

    public function destroy(Warehouse $warehouse)
    {
        // Check if warehouse has stock
        if ($warehouse->stockLevels()->where('quantity', '>', 0)->exists()) {
            return response()->json([
                'message' => 'Cannot delete warehouse with existing stock. Transfer stock first.'
            ], 422);
        }

        $warehouse->delete();

        return response()->json(null, 204);
    }

    public function setDefault(Warehouse $warehouse)
    {
        $warehouse->setAsDefault();
        return response()->json($warehouse);
    }
}
