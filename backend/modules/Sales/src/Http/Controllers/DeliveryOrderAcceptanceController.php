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
        $deliveryOrder = DeliveryOrder::with(['customer', 'lines'])->where('acceptance_token', $token)->first();

        if (! $deliveryOrder) {
            return response()->json(['error' => 'Invalid or expired link.', 'code' => 'INVALID_TOKEN'], 404);
        }

        $expired = $deliveryOrder->acceptance_expires_at && $deliveryOrder->acceptance_expires_at->isPast();
        $signed = $deliveryOrder->signed_at !== null || $deliveryOrder->signed_document_path !== null;

        $lines = $deliveryOrder->lines->map(fn ($line) => [
            'id' => $line->id,
            'description' => $line->description,
            'quantity' => (float) $line->quantity,
            'quantity_delivered' => (float) ($line->quantity_delivered ?? 0),
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
                'status' => $deliveryOrder->status,
                'notes' => $deliveryOrder->notes,
                'total' => (float) $deliveryOrder->total,
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
        ]);

        $files = array_values(array_filter((array) $request->file('files', [])));
        if ($request->hasFile('file')) {
            $files[] = $request->file('file');
        }

        if ($files === []) {
            return response()->json(['error' => 'Please choose at least one file.', 'code' => 'NO_FILES'], 422);
        }

        $deliveryOrder = DeliveryOrder::with('customer')->where('acceptance_token', $token)->first();

        if (! $deliveryOrder) {
            return response()->json(['error' => 'Invalid or expired link.', 'code' => 'INVALID_TOKEN'], 404);
        }

        if ($deliveryOrder->acceptance_expires_at && $deliveryOrder->acceptance_expires_at->isPast()) {
            return response()->json(['error' => 'This link has expired.', 'code' => 'EXPIRED'], 410);
        }

        $signerName = $request->input('signer_name') ?? $deliveryOrder->signed_by_name;
        $signerEmail = $request->input('signer_email') ?? $deliveryOrder->signed_by_email;

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
                );

                $stored[] = [
                    'id' => null,
                    'file_name' => $file->getClientOriginalName(),
                ];
            }

            // The most recent upload is treated as the signed copy.
            $last = $files[count($files) - 1];
            $signedPath = $this->storeFile($deliveryOrder, $last);

            $deliveryOrder->update([
                'signed_document_path' => $signedPath,
                'signed_document_uploaded_at' => now(),
                'signed_by_name' => $signerName,
                'signed_by_email' => $signerEmail,
                'signed_at' => now(),
            ]);

            Log::info('Delivery order customer evidence uploaded', [
                'delivery_order_id' => $deliveryOrder->id,
                'files' => count($files),
                'signer' => $signerEmail,
            ]);

            return response()->json([
                'message' => count($files) === 1
                    ? 'Document uploaded successfully.'
                    : count($files).' files uploaded successfully.',
                'file_names' => array_column($stored, 'file_name'),
                'count' => count($files),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Delivery order evidence upload failed', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage(), 'code' => 'UPLOAD_FAILED'], 422);
        }
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
