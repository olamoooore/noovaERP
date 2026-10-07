<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceEvidence;
use App\Services\InvoiceEvidenceService;
use App\Services\SalesSettings;
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

        $revoked = $evidence->revoked_at !== null;

        $isExpired = $evidence->expires_at && $evidence->expires_at->isPast() && ! $evidence->signed_at;

        $invoice = Invoice::with(['customer', 'lines.product'])->find($evidence->invoice_id);

        if (! $invoice) {
            return response()->json([
                'error' => 'Invoice not found.',
                'code' => 'INVOICE_NOT_FOUND',
            ], 404);
        }

        // The customer is paying this document, so the portal must itemise it:
        // product name/SKU, model, unit and lot/serial — not just descriptions.
        $lines = $invoice->lines->map(function ($line) {
            return [
                'id' => $line->id,
                'line_type' => $line->line_type ?? 'product',
                'product_name' => $line->product?->name,
                'sku' => $line->product?->sku,
                'image' => $line->product?->image,
                'description' => $line->description,
                'model_number' => $line->model_number,
                'product_notes' => $line->product_notes,
                'patient_name' => $line->patient_name,
                'lot_number' => $line->lot_number,
                'serial_number' => $line->serial_number,
                'expiry_date' => $line->expiry_date?->toDateString(),
                'unit' => $line->unit,
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
                'customer_reference' => $invoice->customer_reference,
                'po_number' => $invoice->po_number ?? null,
                'attention_to' => $invoice->attention_to,
                'billing_address' => $invoice->billing_address_snapshot,
                'lines' => $lines,
                'payment_link_url' => $invoice->payment_link_url,
            ],
            'signer' => [
                'name' => $evidence->signer_name,
                'email' => $evidence->signer_email,
                'signed_at' => $evidence->signed_at?->toIso8601String(),
                'signer_ip' => $evidence->signer_ip,
            ],
            'already_accepted' => $evidence->signed_at !== null && ! $revoked,
            'expired' => $isExpired,
            'revoked' => $revoked,
            'revoked_at' => $evidence->revoked_at?->toIso8601String(),
            'withdrawn' => $revoked && $invoice->status === 'draft',
            'expires_at' => $evidence->expires_at?->toIso8601String(),
            'status_trail' => $statusTrail,
            'company' => \App\Support\TenantBranding::forCurrentTenant(),
        ]);
    }

    private function buildStatusTrail(Invoice $invoice, $evidence): array
    {
        // "Evidence submitted" counts any customer/vendor-provided document
        // (payment proof, purchase order, delivery order, other) — the
        // acceptance signature is covered by its own step.
        $submittedEvidence = $invoice->evidence()
            ->whereNull('deleted_at')
            ->whereNull('revoked_at')
            ->whereNotNull('file_name')
            ->where('type', '!=', InvoiceEvidence::TYPE_CUSTOMER_ACCEPTANCE);

        $confirmedEvidence = $invoice->evidence()
            ->whereNotNull('confirmed_at')
            ->whereNull('deleted_at')
            ->whereNull('revoked_at');

        $trail = [
            [
                'key' => 'issued',
                'label' => 'Issued',
                'completed' => true,
                'date' => optional($invoice->invoice_date)->toDateString() ?? optional($invoice->created_at)->toIso8601String(),
            ],
            [
                'key' => 'sent',
                'label' => 'Sent',
                // The acceptance link itself is the delivery — reaching this
                // endpoint with a valid token means the invoice was sent.
                'completed' => true,
                'date' => optional($evidence->created_at)->toIso8601String(),
            ],
            [
                'key' => 'accepted',
                'label' => 'Accepted',
                'completed' => $evidence->signed_at !== null,
                'date' => optional($evidence->signed_at)->toIso8601String(),
            ],
            [
                'key' => 'evidence',
                'label' => 'Evidence submitted',
                'completed' => (clone $submittedEvidence)->exists(),
                'date' => optional((clone $submittedEvidence)->latest()->first()?->created_at)->toIso8601String(),
            ],
            [
                'key' => 'confirmed',
                'label' => 'Confirmed',
                'completed' => (clone $confirmedEvidence)->exists(),
                'date' => optional((clone $confirmedEvidence)->latest('confirmed_at')->first()?->confirmed_at)->toIso8601String(),
            ],
        ];

        return $trail;
    }

    public function accept(Request $request, string $token)
    {
        $evidence = $this->evidenceService->findByAcceptanceToken($token);

        if (! $evidence) {
            return response()->json([
                'error' => 'Invalid or expired acceptance link.',
                'code' => 'INVALID_TOKEN',
            ], 404);
        }

        // #539: the tenant's portal settings are enforced server-side
        // (InitializeTenantForPublicAccess bound the owning tenant). Acceptance can be
        // switched off, and a drawn signature is required unless the tenant
        // turned sales.portal.require_signature off.
        $salesSettings = app(SalesSettings::class);

        if (! $salesSettings->acceptanceEnabled()) {
            return response()->json([
                'error' => 'Online acceptance is not available for this invoice.',
                'code' => 'ACCEPTANCE_DISABLED',
            ], 403);
        }

        $validated = $request->validate([
            'signer_name' => 'required|string|max:255',
            'signer_email' => 'required|email|max:255',
            'agreed' => 'accepted',
            'signature' => [
                $salesSettings->requireSignature() ? 'required' : 'nullable',
                'string',
                'max:1048576',
                ...($salesSettings->requireSignature() ? ['starts_with:data:image/'] : []),
            ],
        ]);

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
                $evidence,
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

    /**
     * Customer uploads payment proof (bank-transfer receipt, etc.) through the
     * public acceptance portal.
     */
    public function uploadEvidence(Request $request, string $token)
    {
        $request->validate([
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|max:10240',
            'file' => 'nullable|file|max:10240',
            'notes' => 'nullable|string|max:1000',
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
        ]);

        // Accept either the new multi-file `files[]` field or the legacy single
        // `file` field (kept for backwards compatibility).
        $files = array_values(array_filter((array) $request->file('files', [])));
        if ($request->hasFile('file')) {
            $files[] = $request->file('file');
        }

        if ($files === []) {
            return response()->json([
                'error' => 'Please choose at least one file to upload.',
                'code' => 'NO_FILES',
            ], 422);
        }

        $evidence = $this->evidenceService->findAnyByAcceptanceToken($token);

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

        try {
            $proofs = [];

            foreach ($files as $file) {
                $proofs[] = $this->evidenceService->recordCustomerEvidence(
                    $invoice,
                    $file,
                    $request->input('notes'),
                    $request->input('customer_name') ?? $evidence->signer_name,
                    $request->input('customer_email') ?? $evidence->signer_email,
                );
            }

            $count = count($proofs);

            return response()->json([
                'message' => $count === 1
                    ? 'Evidence uploaded successfully.'
                    : "{$count} evidence files uploaded successfully.",
                // Back-compat single-file fields (the first uploaded file).
                'evidence_id' => $proofs[0]->id,
                'file_name' => $proofs[0]->file_name,
                'uploaded_at' => optional($proofs[0]->created_at)->toIso8601String(),
                // New multi-file fields.
                'evidence_ids' => array_map(fn ($p) => $p->id, $proofs),
                'file_names' => array_map(fn ($p) => $p->file_name, $proofs),
                'count' => $count,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Payment proof upload failed', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to upload payment proof. Please try again.',
                'code' => 'UPLOAD_FAILED',
            ], 500);
        }
    }
}
