<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tax;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    public function index()
    {
        return response()->json(
            Tax::query()->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'type' => 'required|in:gst,vat,sales,service,withholding,other',
            'rate' => 'required|numeric|min:0|max:100',
            'is_active' => 'boolean',
        ]);

        $tax = Tax::create($validated);
        return response()->json($tax, 201);
    }

    public function update(Request $request, Tax $tax)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|nullable|string|max:50',
            'type' => 'sometimes|in:gst,vat,sales,service,withholding,other',
            'rate' => 'sometimes|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        $tax->update($validated);
        return response()->json($tax->fresh());
    }

    public function destroy(Tax $tax)
    {
        $tax->delete();
        return response()->json(['message' => 'Tax deleted successfully']);
    }
}
