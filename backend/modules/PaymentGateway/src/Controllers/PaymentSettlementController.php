<?php

namespace Modules\PaymentGateway\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\PaymentGateway\Models\PaymentSettlement;
use Modules\PaymentGateway\Services\SettlementReconciliationService;

/**
 * Gateway settlement reconciliation entry (FR-PAY-06 / P-04a, P3 ops).
 * The merchant uploads the provider's settlement statement (CSV:
 * transaction_id,gross,fee,net,currency,settled_at); every row is either
 * reconciled through the canonical GL or classified into the report —
 * nothing is silently dropped and nothing is invented.
 */
class PaymentSettlementController extends Controller
{
    public function __construct(
        private readonly SettlementReconciliationService $reconciliation,
    ) {}

    public function ingest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'batch_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $path = $validated['file']->getRealPath();

        try {
            $rows = $this->reconciliation->parseCsv((string) $path);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'settlement_file_unreadable'], 422);
        }

        if ($rows === []) {
            return response()->json([
                'message' => 'The settlement file has no data rows.',
                'code' => 'settlement_file_empty',
            ], 422);
        }

        $report = $this->reconciliation->ingestRows($rows, $validated['batch_reference'] ?? null);

        return response()->json([
            'data' => [
                'summary' => collect($report)->map(fn ($items) => count($items))->all(),
                'report' => $report,
            ],
        ]);
    }

    public function index(): JsonResponse
    {
        $settlements = PaymentSettlement::query()
            ->with('transaction:id,transaction_id,amount,currency,status')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (PaymentSettlement $s) => [
                'id' => $s->id,
                'transaction_id' => $s->gateway_txn_id,
                'settlement_reference' => $s->settlement_reference,
                'gross' => (string) $s->gross_amount,
                'fee' => (string) $s->fee_amount,
                'net' => (string) $s->net_amount,
                'currency' => $s->currency,
                'settled_at' => optional($s->settled_at)->toDateString(),
                'journal_entry_id' => $s->journal_entry_id,
            ]);

        return response()->json(['data' => $settlements]);
    }
}
