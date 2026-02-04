<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Currencies Controller
 * Manages supported currencies
 */
class CurrenciesController extends Controller
{
    /**
     * List all currencies
     */
    public function index(Request $request)
    {
        $query = Currency::query();

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        // Search by code or name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('code', 'LIKE', "%{$search}%")
                  ->orWhere('name', 'LIKE', "%{$search}%");
            });
        }

        $currencies = $query->orderBy('code')->get();

        return response()->json($currencies);
    }

    /**
     * Get a specific currency
     */
    public function show($code)
    {
        $currency = Currency::findOrFail($code);
        return response()->json($currency);
    }

    /**
     * Create a new currency
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:3|unique:currencies,code',
            'name' => 'required|string|max:100',
            'symbol' => 'nullable|string|max:10',
            'decimal_places' => 'nullable|integer|min:0|max:4',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $currency = Currency::create([
            'code' => strtoupper($request->code),
            'name' => $request->name,
            'symbol' => $request->symbol,
            'decimal_places' => $request->decimal_places ?? 2,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Currency created successfully',
            'currency' => $currency
        ], 201);
    }

    /**
     * Update a currency
     */
    public function update(Request $request, $code)
    {
        $currency = Currency::findOrFail($code);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'symbol' => 'nullable|string|max:10',
            'decimal_places' => 'nullable|integer|min:0|max:4',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $currency->update($request->only(['name', 'symbol', 'decimal_places', 'is_active']));

        return response()->json([
            'message' => 'Currency updated successfully',
            'currency' => $currency
        ]);
    }

    /**
     * Delete (deactivate) a currency
     */
    public function destroy($code)
    {
        $currency = Currency::findOrFail($code);
        
        // Check if currency is in use
        // In a real application, you would check if there are transactions using this currency
        
        $currency->update(['is_active' => false]);

        return response()->json([
            'message' => 'Currency deactivated successfully'
        ]);
    }

    /**
     * Get active currencies
     */
    public function active()
    {
        $currencies = Currency::where('is_active', true)
            ->orderBy('code')
            ->get();

        return response()->json($currencies);
    }
}
