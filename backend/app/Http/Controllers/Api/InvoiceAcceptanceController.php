<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvoiceAcceptanceController extends Controller
{
    public function __construct(
        private InvoiceEvidenceService $evidenceService
    ) {}

    public function show(string $token)
    {
        $evidence = $this->evidenceService->findByAcceptanceToken($token);

        if (! $evidence) {
            return response()->json([
                'error' => 'Invalid or expired acceptance link.',
                'code' => 'INVALID_TOKEN',
            ], 404);
        }

        $invoice = Invoice::with(['customer', 'lines.product'])->find($evidence->invoice_id);

        if (! $invoice) {
            return response()->json([
                'error' => 'Invoice not found.',
                'code' => 'INVOICE_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->customer?->name,
                'total' => $invoice->total,
                'currency_code' => $invoice->currency_code,
                'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
                'due_date' => $invoice->due_date?->format('Y-m-d'),
                'status' => $invoice->status,
            ],
            'signer' => [
                'name' => $evidence->signer_name,
                'email' => $evidence->signer_email,
                'signed_at' => $evidence->signed_at?->toIso8601String(),
            ],
            'already_accepted' => $evidence->signed_at !== null,
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $validated = $request->validate([
            'signer_name' => 'required|string|max:255',
            'signer_email' => 'required|email|max:255',
            'agreed' => 'accepted',
        ]);

        $evidence = $this->evidenceService->findByAcceptanceToken($token);

        if (! $evidence) {
            return response()->json([
                'error' => 'Invalid or expired acceptance link.',
                'code' => 'INVALID_TOKEN',
            ], 404);
        }

        $invoice = Invoice::find($evidence->invoice_id);

        if (! $invoice) {
            return response()->json([
                'error' => 'Invoice not found.',
                'code' => 'INVOICE_NOT_FOUND',
            ], 404);
        }

        if ($evidence->signed_at !== null) {
            return response()->json([
                'message' => 'Invoice has already been accepted.',
                'signer_name' => $evidence->signer_name,
                'signed_at' => $evidence->signed_at->toIso8601String(),
            ]);
        }

        try {
            $this->evidenceService->recordPortalAcceptance(
                $invoice,
                $validated['signer_name'],
                $validated['signer_email'],
                $request->ip() ?? 'unknown',
                $request->userAgent() ?? 'unknown',
            );

            return response()->json([
                'message' => 'Invoice accepted successfully.',
                'signer_name' => $validated['signer_name'],
                'signed_at' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('Invoice acceptance failed', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to record acceptance. Please try again.',
                'code' => 'ACCEPTANCE_FAILED',
            ], 500);
        }
    }
}
