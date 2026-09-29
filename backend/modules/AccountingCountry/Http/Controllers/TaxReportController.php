<?php

namespace Modules\AccountingCountry\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccountingCountry\Services\TaxReportingService;

/**
 * MRD TAX-REQ-08 — tax journal, trail↔GL reconciliation and filing tracker.
 * Read-only accounting surfaces; the return generators remain in
 * StatutoryReturnController.
 */
class TaxReportController extends Controller
{
    public function __construct(private readonly TaxReportingService $reporting) {}

    public function journal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'to' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/', 'after_or_equal:from'],
            'entity_id' => 'nullable|integer|exists:legal_entities,id',
        ]);

        return response()->json([
            'data' => $this->reporting->journal(
                $validated['from'],
                $validated['to'],
                isset($validated['entity_id']) ? (int) $validated['entity_id'] : null,
            ),
        ]);
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2]|Q[1-4])$/'],
            'entity_id' => 'nullable|integer|exists:legal_entities,id',
        ]);

        return response()->json([
            'data' => $this->reporting->reconciliation(
                $validated['period'],
                isset($validated['entity_id']) ? (int) $validated['entity_id'] : null,
            ),
        ]);
    }

    public function filings(): JsonResponse
    {
        return response()->json(['data' => $this->reporting->filings()]);
    }
}
