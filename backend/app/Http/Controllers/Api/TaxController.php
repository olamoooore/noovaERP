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
            'type' => 'nullable|in:gst,vat,sales,service,withholding,other',
            'rate' => 'required|numeric|min:0|max:100',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_inclusive' => 'boolean',
        ]);

        if (!isset($validated['type'])) {
            $validated['type'] = 'vat';
        }

        if (!empty($validated['is_default'])) {
            Tax::where('is_default', true)->update(['is_default' => false]);
        }

        $tax = Tax::create($validated);
        return response()->json($tax, 201);
    }

    public function update(Request $request, $tax)
    {
        $tax = Tax::findOrFail($tax);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|nullable|string|max:50',
            'type' => 'sometimes|in:gst,vat,sales,service,withholding,other',
            'rate' => 'sometimes|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'is_inclusive' => 'sometimes|boolean',
        ]);

        if (!empty($validated['is_default'])) {
            Tax::where('id', '!=', $tax->id)->where('is_default', true)->update(['is_default' => false]);
        }

        $tax->update($validated);
        return response()->json($tax->fresh());
    }

    public function destroy($tax)
    {
        $tax = Tax::findOrFail($tax);
        $tax->delete();
        return response()->json(['message' => 'Tax deleted successfully']);
    }
}
