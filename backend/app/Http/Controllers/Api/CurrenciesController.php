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

        // FR-1.1: sellable = enabled for documents and price lists.
        if ($request->boolean('sellable')) {
            $query->where('is_active', true)->where('is_sellable', true);
        } elseif ($request->has('is_sellable')) {
            $query->where('is_sellable', $request->boolean('is_sellable'));
        }

        // Search by code or name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
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
            'is_sellable' => (bool) ($request->input('is_sellable', true)),
        ]);

        return response()->json([
            'message' => 'Currency created successfully',
            'currency' => $currency,
        ], 201);
    }

    /**
     * Update a currency
     */
    public function update(Request $request, $code)
    {
        $currency = Currency::findOrFail($code);
        $before = $currency->only(['is_active', 'is_sellable']);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'symbol' => 'nullable|string|max:10',
            'decimal_places' => 'nullable|integer|min:0|max:4',
            'is_active' => 'sometimes|boolean',
            'is_sellable' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $currency->update($request->only(['name', 'symbol', 'decimal_places', 'is_active', 'is_sellable']));

        // FR-10.3: currency enablement is an accounting-adjacent change.
        $this->audit('currency.updated', (string) $currency->code, $before, $currency->fresh()->toArray());

        return response()->json([
            'message' => 'Currency updated successfully',
            'currency' => $currency,
        ]);
    }

    /**
     * Delete (deactivate) a currency
     */
    public function destroy($code)
    {
        $currency = Currency::findOrFail($code);

        // The tenant base currency must stay usable — deactivating it would
        // strand every posting path (and the MRD keeps base as the fallback
        // authority, FR-1.2).
        $base = \App\Models\CompanySettings::query()->value('base_currency');
        if (is_string($base) && strtoupper($base) === strtoupper((string) $code)) {
            return response()->json([
                'message' => "Cannot deactivate the tenant base currency ('{$base}'). Change Company Settings → Base Currency first.",
            ], 422);
        }

        // Check if currency is in use
        // In a real application, you would check if there are transactions using this currency

        $currency->update(['is_active' => false]);

        $this->audit('currency.deactivated', (string) $code, ['is_active' => true], ['is_active' => false]);

        return response()->json([
            'message' => 'Currency deactivated successfully',
        ]);
    }

    /**
     * FR-1.1: bulk enable/disable of the SELLABLE flag.
     * PUT /accounting/currencies-sellable {codes:[...], is_sellable:bool}
     */
    public function updateSellable(Request $request)
    {
        $validated = $request->validate([
            'codes' => 'required|array|min:1|max:200',
            'codes.*' => 'required|string|size:3|exists:currencies,code',
            'is_sellable' => 'required|boolean',
        ]);

        $codes = array_map(fn ($c) => strtoupper((string) $c), $validated['codes']);
        $sellable = (bool) $validated['is_sellable'];

        $base = strtoupper((string) (\App\Models\CompanySettings::query()->value('base_currency') ?: ''));

        if (! $sellable && $base !== '' && in_array($base, $codes, true)) {
            return response()->json([
                'message' => "The tenant base currency ('{$base}') cannot be made non-sellable.",
            ], 422);
        }

        $updated = Currency::whereIn('code', $codes)->update(['is_sellable' => $sellable]);

        $this->audit('currency.sellability_changed', 'bulk', null, [
            'codes' => $codes,
            'is_sellable' => $sellable,
            'updated' => $updated,
        ]);

        return response()->json([
            'message' => $updated.' currency(ies) updated',
            'data' => Currency::whereIn('code', $codes)->get(),
        ]);
    }

    private function audit(string $action, string $subject, ?array $before, ?array $after): void
    {
        try {
            app(\App\Services\AuditLogger::class)->log(
                operation: $action,
                referenceType: 'currency',
                referenceId: 0,
                beforeState: $before,
                afterState: $after + ['code' => $subject],
            );
        } catch (\Throwable) {
            // audit must not break currency administration
        }
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
