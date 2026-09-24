<?php

namespace Modules\Sales\Http\Controllers;

use App\Services\Chatter\ChatterService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Models\DeliveryOrder;

/**
 * Public customer portal for delivery orders.
 *
 * The customer opens a signed link, views/downloads the DO, signs it on paper,
 * then uploads the signed copy (or any supporting evidence) back. Uploaded
 * files are mirrored into the document's chatter feed for the vendor.
 */
class DeliveryOrderAcceptanceController extends Controller
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    private const ALLOWED_MIME_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];

    public function __construct(private ChatterService $chatterService) {}

    public function show(string $token)
    {
        $deliveryOrder = DeliveryOrder::with(['customer', 'lines.product'])->where('acceptance_token', $token)->first();

        if (! $deliveryOrder) {
            return response()->json(['error' => 'Invalid or expired link.', 'code' => 'INVALID_TOKEN'], 404);
        }

        $expired = $deliveryOrder->acceptance_expires_at && $deliveryOrder->acceptance_expires_at->isPast();
        $signed = $deliveryOrder->signed_at !== null || $deliveryOrder->signed_document_path !== null;

        // The customer is signing for this document, so the portal must show
        // what is actually on it: item name/SKU, model, lot/serial and expiry —
        // not just a bare description.
        $lines = $deliveryOrder->lines->map(fn ($line) => [
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
            'batch_number' => $line->batch_number,
            'serial_number' => $line->serial_number,
            'expiry_date' => $line->expiry_date?->toDateString(),
            'quantity' => (float) $line->quantity,
            'quantity_ordered' => (float) ($line->quantity_ordered ?? 0),
            'quantity_delivered' => (float) ($line->quantity_delivered ?? 0),
            'unit' => $line->unit,
            'unit_price' => (float) ($line->unit_price ?? 0),
            'line_total' => (float) ($line->line_total ?? 0),
        ])->values()->all();

        return response()->json([
            'delivery_order' => [
                'id' => $deliveryOrder->id,
                'delivery_order_number' => $deliveryOrder->delivery_order_number,
                'customer_name' => $deliveryOrder->customer?->name,
                'customer_email' => $deliveryOrder->customer?->email,
                'delivery_date' => $deliveryOrder->delivery_date?->toDateString(),
                'expected_date' => $deliveryOrder->expected_date?->toDateString(),
                'status' => $deliveryOrder->status,
                'notes' => $deliveryOrder->notes,
                'total' => (float) $deliveryOrder->total,
                'po_number' => $deliveryOrder->po_number,
                'carrier' => $deliveryOrder->carrier,
                'tracking_number' => $deliveryOrder->tracking_number,
                'attention_to' => $deliveryOrder->attention_to,
                'salesperson' => $deliveryOrder->salesperson,
                'shipping_address' => $deliveryOrder->shipping_address,
                'payment_terms' => $deliveryOrder->payment_terms,
                // Delivery orders carry no currency column; the tenant's
                // configured currency is what the customer should see.
                'currency_code' => \App\Models\Setting::getValue('currency')['code'] ?? 'USD',
                'lines' => $lines,
            ],
            'signer' => [
                'name' => $deliveryOrder->signed_by_name,
                'email' => $deliveryOrder->signed_by_email,
                'signed_at' => $deliveryOrder->signed_at?->toIso8601String(),
            ],
            'already_signed' => $signed,
            'expired' => $expired,
            'expires_at' => $deliveryOrder->acceptance_expires_at?->toIso8601String(),
        ]);
    }

    public function uploadEvidence(Request $request, string $token)
    {
        $request->validate([
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|max:10240',
            'file' => 'nullable|file|max:10240',
            'signer_name' => 'nullable|string|max:255',
            'signer_email' => 'nullable|email|max:255',
            // Optional digital signature (PNG data URL) drawn in the browser.
            'signature' => 'nullable|string',
        ]);

        $files = array_values(array_filter((array) $request->file('files', [])));
        if ($request->hasFile('file')) {
            $files[] = $request->file('file');
        }

        $signatureDataUrl = (string) $request->input('signature', '');
        $hasSignature = str_starts_with($signatureDataUrl, 'data:image/');

        if ($files === [] && ! $hasSignature) {
            return response()->json([
                'error' => 'Please upload a file or sign digitally.',
                'code' => 'NO_FILES',
            ], 422);
        }

        if ($signatureDataUrl !== '' && ! $hasSignature) {
            return response()->json(['error' => 'The signature could not be read.', 'code' => 'BAD_SIGNATURE'], 422);
        }

        $deliveryOrder = DeliveryOrder::with('customer')->where('acceptance_token', $token)->first();

        if (! $deliveryOrder) {
            return response()->json(['error' => 'Invalid or expired link.', 'code' => 'INVALID_TOKEN'], 404);
        }

        if ($deliveryOrder->acceptance_expires_at && $deliveryOrder->acceptance_expires_at->isPast()) {
            return response()->json(['error' => 'This link has expired.', 'code' => 'EXPIRED'], 410);
        }

        // One submission per link. Without this the customer could keep
        // re-uploading and each submission silently replaced the signed copy —
        // the invoice portal already refuses a second acceptance, so the
        // delivery-order portal must too.
        if ($deliveryOrder->signed_at !== null || $deliveryOrder->signed_document_path !== null) {
            return response()->json([
                'error' => 'This delivery order has already been signed and returned.',
                'code' => 'ALREADY_SIGNED',
            ], 409);
        }

        $signerName = $request->input('signer_name') ?? $deliveryOrder->signed_by_name;
        $signerEmail = $request->input('signer_email') ?? $deliveryOrder->signed_by_email;

        // Who the chatter should attribute this to — the customer, not "System".
        $signerLabel = trim(($signerName ?: 'Customer').($signerEmail ? " ({$signerEmail})" : ''));

        try {
            $stored = [];

            foreach ($files as $file) {
                if ($file->getSize() > self::MAX_FILE_SIZE) {
                    throw new \InvalidArgumentException('File exceeds the 10 MB limit.');
                }

                $mime = $file->getMimeType();
                if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                    throw new \InvalidArgumentException("File type {$mime} is not allowed.");
                }

                $path = $this->storeFile($deliveryOrder, $file);

                $this->chatterService->registerAttachment(
                    DeliveryOrder::class,
                    $deliveryOrder->id,
                    $file->getClientOriginalName(),
                    $mime,
                    (int) $file->getSize(),
                    'public',
                    $path,
                    null,
                    $signerLabel,
                );

                $stored[] = [
                    'id' => null,
                    'file_name' => $file->getClientOriginalName(),
                ];
            }

            // A drawn signature is the authoritative signed copy; otherwise the
            // most recent upload is treated as one.
            if ($hasSignature) {
                $signature = $this->storeSignature($deliveryOrder, $signatureDataUrl);

                $this->chatterService->registerAttachment(
                    DeliveryOrder::class,
                    $deliveryOrder->id,
                    'signature.png',
                    'image/png',
                    $signature['size'],
                    'public',
                    $signature['path'],
                    null,
                    $signerLabel,
                );

                $signedPath = $signature['path'];
                $stored[] = ['id' => null, 'file_name' => 'signature.png'];
            } else {
                $last = $files[count($files) - 1];
                $signedPath = $this->storeFile($deliveryOrder, $last);
            }

            $deliveryOrder->update([
                'signed_document_path' => $signedPath,
                'signed_document_uploaded_at' => now(),
                'signed_by_name' => $signerName,
                'signed_by_email' => $signerEmail,
                'signed_at' => now(),
            ]);

            // A named history entry, so the feed answers "who signed what"
            // rather than only listing anonymous file rows.
            $fileNames = array_column($stored, 'file_name');
            $this->chatterService->logEvent(
                DeliveryOrder::class,
                $deliveryOrder->id,
                $hasSignature ? 'signed' : 'attachment_uploaded',
                ($hasSignature ? 'Signed and returned' : 'Evidence uploaded')
                    ." by {$signerLabel} — ".implode(', ', $fileNames),
                [
                    'files' => implode(', ', $fileNames),
                    'signer_email' => $signerEmail,
                    // Lets the chatter attribute the entry to the customer
                    // instead of rendering "System".
                    'signer_name' => $signerLabel,
                ],
            );

            Log::info('Delivery order customer evidence uploaded', [
                'delivery_order_id' => $deliveryOrder->id,
                'files' => count($files),
                'signer' => $signerEmail,
            ]);

            $count = count($stored);

            return response()->json([
                'message' => $hasSignature && $files === []
                    ? 'Signature received.'
                    : ($count === 1 ? 'Document uploaded successfully.' : "{$count} files uploaded successfully."),
                'file_names' => array_column($stored, 'file_name'),
                'count' => $count,
                'signed' => $hasSignature,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Delivery order evidence upload failed', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage(), 'code' => 'UPLOAD_FAILED'], 422);
        }
    }

    /**
     * Persist a browser-drawn signature (PNG data URL).
     *
     * @return array{path: string, size: int}
     */
    private function storeSignature(DeliveryOrder $deliveryOrder, string $dataUrl): array
    {
        $payload = substr($dataUrl, strpos($dataUrl, ',') + 1);
        $binary = base64_decode($payload, true);

        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('Invalid signature data.');
        }

        $filename = sprintf('signature-%s.png', \Illuminate\Support\Str::random(8));
        $path = "delivery-orders/{$deliveryOrder->id}/evidence/{$filename}";

        Storage::disk('public')->put($path, $binary);

        return ['path' => $path, 'size' => strlen($binary)];
    }

    private function storeFile(DeliveryOrder $deliveryOrder, $file): string
    {
        $path = "delivery-orders/{$deliveryOrder->id}/evidence/".$file->getClientOriginalName();

        Storage::disk('public')->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        return $path;
    }
}
