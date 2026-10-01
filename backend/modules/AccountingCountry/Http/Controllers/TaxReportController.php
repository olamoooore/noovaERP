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

    /**
     * MRD R4.5: withheld-per-month summary (WHT payable view for the tracker).
     */
    public function withholding(Request $request): JsonResponse
    {
        $validated = $request->validate(['months' => 'nullable|integer|min:1|max:36']);

        return response()->json([
            'data' => $this->reporting->withholdingSummary((int) ($validated['months'] ?? 12)),
        ]);
    }

    /**
     * #270: book a WHT remittance (DR wht_payable / CR cash) for an authority
     * and period, guarded by the open withheld balance.
     */
    public function remit(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'authority' => 'required|string|in:MY,SG,my,sg',
            'period' => ['required', 'string', 'regex:/^\d{4}-(\d{2}|Q[1-4])$/'],
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'required|string|max:120',
            'payment_date' => 'nullable|date',
        ]);

        try {
            $entry = app(\App\Services\WhtRemittanceService::class)->remit(
                strtoupper($validated['authority']),
                $validated['period'],
                (float) $validated['amount'],
                $validated['reference'],
                $validated['payment_date'] ?? null,
                $request->user()?->id,
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'WHT remittance posted.',
            'data' => [
                'journal_entry_id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'amount' => (float) $validated['amount'],
                'open_total' => app(\App\Services\WhtRemittanceService::class)->openBalance(
                    strtoupper($validated['authority']), $validated['period']
                ),
            ],
        ], 201);
    }

    /**
     * #270: authority-uploadable WHT schedule CSV for the period.
     */
    public function withholdingExport(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $validated = $request->validate([
            'authority' => 'required|string|in:MY,SG,my,sg',
            'period' => ['required', 'string', 'regex:/^\d{4}-(\d{2}|Q[1-4])$/'],
        ]);

        $authority = strtoupper($validated['authority']);
        $schedule = $this->reporting->withholdingSchedule($authority, $validated['period']);

        $lines = ['tax_code,withheld,remitted,open'];
        foreach ($schedule as $row) {
            $lines[] = implode(',', [$row['tax_code'], number_format($row['withheld'], 2, '.', ''), number_format($row['remitted'], 2, '.', ''), number_format($row['open'], 2, '.', '')]);
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"wht_schedule_{$authority}_{$validated['period']}.csv\"",
        ]);
    }
}
