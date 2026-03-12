<?php

namespace Modules\FieldService\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\FieldService\Models\WorkOrderType;

class ConfigController extends Controller
{
    public function indexTypes()
    {
        return response()->json(
            WorkOrderType::where('is_active', true)->orderBy('name')->get()
        );
    }

    public function storeType(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:7',
            'icon'        => 'nullable|string|max:100',
        ]);

        $type = WorkOrderType::create($validated);

        return response()->json($type, 201);
    }

    public function updateType(Request $request, WorkOrderType $type)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:7',
            'icon'        => 'nullable|string|max:100',
            'is_active'   => 'nullable|boolean',
        ]);

        $type->update($validated);

        return response()->json($type->fresh());
    }

    public function destroyType(WorkOrderType $type)
    {
        $type->update(['is_active' => false]);
        return response()->json(['message' => 'Work order type deactivated.']);
    }
}
