<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index()
    {
        return Warehouse::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $warehouse = Warehouse::create($validated);

        return response()->json($warehouse, 201);
    }

    public function show($warehouse)
    {
        $warehouse = Warehouse::findOrFail($warehouse);
        return $warehouse;
    }

    public function update(Request $request, $warehouse)
    {
        $warehouse = Warehouse::findOrFail($warehouse);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $warehouse->update($validated);

        return $warehouse;
    }

    public function destroy($warehouse)
    {
        $warehouse = Warehouse::findOrFail($warehouse);
        $warehouse->delete();

        return response()->json(null, 204);
    }
}
