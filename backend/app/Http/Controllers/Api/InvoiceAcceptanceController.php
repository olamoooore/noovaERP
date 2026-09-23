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
        $evidence = $this->evidenceService->findAnyByAcceptanceToken($token);

        if (! $evidence) {
            return response()->json([
                'error' => 'Invalid or expired acceptance link.',
                'code' => 'INVALID_TOKEN',
            ], 404);
        }

        $isExpired = $evidence->expires_at && $evidence->expires_at->isPast() && ! $evidence->signed_at;

        $invoice = Invoice::with(['customer', 'lines.product'])->find($evidence->invoice_id);

        if (! $invoice) {
            return response()->json([
                'error' => 'Invoice not found.',
                'code' => 'INVOICE_NOT_FOUND',
            ], 404);
        }

        $lines = $invoice->lines->map(function ($line) {
            return [
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'tax_rate' => (float) $line->tax_rate,
                'tax_amount' => (float) $line->tax_amount,
                'discount_amount' => (float) $line->discount_amount,
                'line_total' => (float) ($line->quantity * $line->unit_price - $line->discount_amount + $line->tax_amount),
            ];
        })->values()->all();

        $statusTrail = $this->buildStatusTrail($invoice, $evidence);

        return response()->json([
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_name' => $invoice->customer?->name,
                'customer_email' => $invoice->customer?->email,
                'subtotal' => (float) $invoice->subtotal,
                'tax_amount' => (float) $invoice->tax_amount,
                'discount_amount' => (float) $invoice->discount_amount,
                'total' => (float) $invoice->total,
                'paid_amount' => (float) $invoice->paid_amount,
                'balance' => (float) $invoice->balance,
                'currency_code' => $invoice->currency_code,
                'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
                'due_date' => $invoice->due_date?->format('Y-m-d'),
                'status' => $invoice->status,
                'notes' => $invoice->notes,
                'terms' => $invoice->terms,
                'lines' => $lines,
                'payment_link_url' => $invoice->payment_link_url,
            ],
            'signer' => [
                'name' => $evidence->signer_name,
                'email' => $evidence->signer_email,
                'signed_at' => $evidence->signed_at?->toIso8601String(),
                'signer_ip' => $evidence->signer_ip,
            ],
            'already_accepted' => $evidence->signed_at !== null,
            'expired' => $isExpired,
            'expires_at' => $evidence->expires_at?->toIso8601String(),
            'status_trail' => $statusTrail,
        ]);
    }

    private function buildStatusTrail(Invoice $invoice, $evidence): array
    {
        $trail = [
            [
                'key' => 'issued',
                'label' => 'Invoice issued',
                'completed' => true,
                'date' => optional($invoice->invoice_date)->toDateString() ?? optional($invoice->created_at)->toIso8601String(),
            ],
            [
                'key' => 'sent',
                'label' => 'Sent to customer',
                'completed' => in_array($invoice->status, ['sent', 'partial', 'paid', 'overdue'], true),
                'date' => optional($invoice->updated_at)->toIso8601String(),
            ],
            [
                'key' => 'accepted',
                'label' => 'Customer acceptance',
                'completed' => $evidence->signed_at !== null,
                'date' => optional($evidence->signed_at)->toIso8601String(),
            ],
            [
                'key' => 'paid',
                'label' => 'Payment received',
                'completed' => (float) $invoice->paid_amount >= (float) $invoice->total && (float) $invoice->total > 0,
                'date' => null,
            ],
        ];

        return $trail;
    }

    public function accept(Request $request, string $token)
    {
        $validated = $request->validate([
            'signer_name' => 'required|string|max:255',
            'signer_email' => 'required|email|max:255',
            'agreed' => 'accepted',
            'signature' => 'nullable|string|max:1048576',
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
                $validated['signature'] ?? null,
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
