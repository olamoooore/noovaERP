<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Exchange Rates Controller
 * Manages currency exchange rates
 */
class ExchangeRatesController extends Controller
{
    protected $exchangeRateService;

    public function __construct(ExchangeRateService $exchangeRateService)
    {
        $this->exchangeRateService = $exchangeRateService;
    }

    /**
     * List all exchange rates
     */
    public function index(Request $request)
    {
        // NOTE: do NOT eager-load fromCurrency/toCurrency here — their snake_case
        // serialisation keys (from_currency/to_currency) collide with the code
        // columns and shadow them with currency OBJECTS, which crashes any UI
        // that renders the code. The relations are unused by this API.
        $query = ExchangeRate::query();

        // Filter by currency pair
        if ($request->has('from_currency')) {
            $query->where('from_currency', $request->from_currency);
        }
        if ($request->has('to_currency')) {
            $query->where('to_currency', $request->to_currency);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('effective_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('effective_date', '<=', $request->end_date);
        }

        $rates = $query->orderBy('effective_date', 'desc')
            ->orderBy('from_currency')
            ->paginate($request->per_page ?? 50);

        return response()->json($rates);
    }

    /**
     * Get a specific exchange rate
     */
    public function show($id)
    {
        $rate = ExchangeRate::findOrFail($id);

        return response()->json($rate);
    }

    /**
     * Create a new exchange rate
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from_currency' => 'required|string|size:3|exists:currencies,code',
            'to_currency' => 'required|string|size:3|exists:currencies,code|different:from_currency',
            'rate' => 'required|numeric|min:0.000001',
            'effective_date' => 'required|date',
            'source' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rate = ExchangeRate::create([
            'from_currency' => strtoupper($request->from_currency),
            'to_currency' => strtoupper($request->to_currency),
            'rate' => $request->rate,
            'effective_date' => $request->effective_date,
            'source' => $request->source ?? 'manual',
        ]);

        return response()->json([
            'message' => 'Exchange rate created successfully',
            'rate' => $rate,
        ], 201);
    }

    /**
     * Update an exchange rate
     */
    public function update(Request $request, $id)
    {
        $rate = ExchangeRate::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'rate' => 'sometimes|numeric|min:0.000001',
            'effective_date' => 'sometimes|date',
            'source' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rate->update($request->only(['rate', 'effective_date', 'source']));

        return response()->json([
            'message' => 'Exchange rate updated successfully',
            'rate' => $rate->fresh(),
        ]);
    }

    /**
     * Delete an exchange rate
     */
    public function destroy($id)
    {
        $rate = ExchangeRate::findOrFail($id);
        $rate->delete();

        return response()->json([
            'message' => 'Exchange rate deleted successfully',
        ]);
    }

    /**
     * Get latest exchange rate for a currency pair
     */
    public function latest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from_currency' => 'required|string|size:3',
            'to_currency' => 'required|string|size:3',
            'as_of_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rate = $this->exchangeRateService->getLatestRate(
            $request->from_currency,
            $request->to_currency,
            $request->as_of_date
        );

        if (! $rate) {
            return response()->json([
                'message' => 'No exchange rate found for this currency pair',
            ], 404);
        }

        return response()->json($rate);
    }

    /**
     * Convert amount between currencies
     */
    public function convert(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'from_currency' => 'required|string|size:3',
            'to_currency' => 'required|string|size:3',
            'as_of_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $result = $this->exchangeRateService->convert(
                $request->amount,
                $request->from_currency,
                $request->to_currency,
                $request->as_of_date
            );

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get exchange rate history for a currency pair
     */
    public function history(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from_currency' => 'required|string|size:3',
            'to_currency' => 'required|string|size:3',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = ExchangeRate::where('from_currency', $request->from_currency)
            ->where('to_currency', $request->to_currency);

        if ($request->has('start_date')) {
            $query->where('effective_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('effective_date', '<=', $request->end_date);
        }

        $history = $query->orderBy('effective_date', 'desc')->get();

        return response()->json($history);
    }

    /**
     * Sync exchange rates from external API (optional)
     */
    public function sync(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'base_currency' => 'required|string|size:3',
            'target_currencies' => 'required|array',
            'target_currencies.*' => 'string|size:3',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $results = $this->exchangeRateService->syncRatesFromAPI(
                $request->base_currency,
                $request->target_currencies
            );

            return response()->json([
                'message' => 'Exchange rates synced successfully',
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to sync rates: '.$e->getMessage(),
            ], 500);
        }
    }
}
