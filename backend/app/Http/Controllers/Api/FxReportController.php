<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FxReportService;
use Illuminate\Http\Request;

/**
 * MRD pricelist-multicurrency FR-7.7 — FX gain/loss (realized) and foreign
 * currency exposure report. Read-only; snapshots drive every figure.
 */
class FxReportController extends Controller
{
    public function __construct(
        private readonly FxReportService $service,
    ) {}

    public function show(Request $request)
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'as_of' => 'nullable|date',
        ]);

        return response()->json([
            'data' => [
                'realized' => $this->service->realized($validated['from'] ?? null, $validated['to'] ?? null),
                'exposure' => $this->service->exposure($validated['as_of'] ?? null),
            ],
        ]);
    }
}
