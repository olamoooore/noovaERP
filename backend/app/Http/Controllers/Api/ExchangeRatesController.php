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

        // MRD FR-11: filter by rate source (manual vs api sync rows).
        if ($request->filled('source')) {
            if ($request->source === 'manual') {
                $query->where('source', 'manual');
            } elseif ($request->source === 'auto') {
                $query->where('source', '<>', 'manual');
            } else {
                $query->where('source', $request->source);
            }
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

        $from = strtoupper($request->from_currency);
        $to = strtoupper($request->to_currency);
        $date = $request->effective_date;
        $source = $request->source ?: 'manual';

        // FR-1.4/FR-10.3: manual rows are unique per (pair, date) — re-saving
        // an override for the same day UPDATES the existing manual row
        // instead of piling up undeterministic duplicates. An api row for the
        // same day is untouched; the read side resolves manual-first.
        try {
            $existing = ExchangeRate::query()
                ->where('from_currency', $from)
                ->where('to_currency', $to)
                ->whereDate('effective_date', $date)
                ->when(
                    $source === 'manual',
                    fn ($q) => $q->where('source', 'manual'),
                    fn ($q) => $q->where('source', '<>', 'manual'),
                )
                ->orderBy('id', 'desc')
                ->first();

            if ($existing) {
                $before = $existing->only(['rate', 'effective_date', 'source']);
                $existing->update(['rate' => $request->rate, 'source' => $source]);
                $rate = $existing->fresh();
            } else {
                $before = null;
                $rate = ExchangeRate::create([
                    'from_currency' => $from,
                    'to_currency' => $to,
                    'rate' => $request->rate,
                    'effective_date' => $date,
                    'source' => $source,
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Partial unique indexes (migration 2026_10_01_000002) race-guard
            // duplicate pair+date rows under concurrency.
            if (str_contains($e->getMessage(), 'unique') || str_contains((string) ($e->errorInfo[1] ?? ''), 'duplicate key')) {
                return response()->json([
                    'message' => "A {$source} rate for {$from}→{$to} already exists on {$date} — edit it instead.",
                ], 422);
            }

            throw $e;
        }

        $this->auditRate($rate->id, $before, $rate->toArray());

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

        $before = $rate->only(['rate', 'effective_date', 'source']);

        try {
            $rate->update($request->only(['rate', 'effective_date', 'source']));
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'duplicate key')) {
                return response()->json([
                    'message' => 'Another rate already owns this currency pair, source class and date.',
                ], 422);
            }

            throw $e;
        }

        $this->auditRate((int) $rate->id, $before, $rate->fresh()->toArray());

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
        $before = $rate->only(['from_currency', 'to_currency', 'rate', 'effective_date', 'source']);
        $rate->delete();

        // FR-1.7: deleting a historical rate NEVER mutates documents that
        // snapshotted it — those rows own their exchange_rate column.
        $this->auditRate((int) $id, $before, null);

        return response()->json([
            'message' => 'Exchange rate deleted successfully',
        ]);
    }

    /**
     * FR-10.3: manual rate actions are audit-logged (actor, before/after).
     */
    private function auditRate(int $rateId, ?array $before, ?array $after): void
    {
        try {
            app(\App\Services\AuditLogger::class)->log(
                operation: $after === null ? 'exchange_rate.deleted' : ($before === null ? 'exchange_rate.created' : 'exchange_rate.updated'),
                referenceType: 'exchange_rate',
                referenceId: $rateId,
                beforeState: $before,
                afterState: $after,
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('exchange-rate audit failed', ['error' => $e->getMessage(), 'rate_id' => $rateId]);
        }
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

        // FR-1.4: syncing only writes source='api' rows and never overwrites
        // a manual rate recorded for the same pair+date.
        try {
            $results = $this->exchangeRateService->syncRatesFromAPI(
                strtoupper((string) $request->base_currency),
                array_map(fn ($c) => strtoupper((string) $c), $request->target_currencies)
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
