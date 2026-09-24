<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EsignRequest;
use App\Services\EsignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;

/**
 * Public e-signature page endpoints (no auth — the token is the credential).
 *
 * The customer opens /sign/{token}, the page renders the real document PDF and
 * they sign it (draw / type / upload). Everything here is tenant-scoped by the
 * token via InitializeTenantForPublicAccess.
 */
class EsignController extends Controller
{
    public function __construct(private readonly EsignService $esign) {}

    public function show(string $token)
    {
        $request = $this->esign->findByToken($token);

        if (! $request) {
            return response()->json(['error' => 'Invalid or expired signing link.', 'code' => 'INVALID_TOKEN'], 404);
        }

        return response()->json([
            'request' => [
                'id' => $request->id,
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'status' => $request->status,
                'signer_name' => $request->signer_name,
                'signer_email' => $request->signer_email,
                'sent_at' => $request->sent_at?->toIso8601String(),
                'signed_at' => $request->signed_at?->toIso8601String(),
                'expires_at' => $request->expires_at?->toIso8601String(),
                'signature_method' => $request->signature_method,
            ],
            'document' => $this->documentMeta($request),
            'open' => $request->isOpen(),
            'expired' => $request->isExpired() || $request->status === EsignRequest::STATUS_EXPIRED,
            'signed' => $request->status === EsignRequest::STATUS_COMPLETED,
        ]);
    }

    /** The document the customer is about to sign, rendered by its own template. */
    public function pdf(string $token)
    {
        $request = $this->esign->findByToken($token);

        if (! $request) {
            return response()->json(['error' => 'Invalid or expired signing link.', 'code' => 'INVALID_TOKEN'], 404);
        }

        // Already signed: serve the signed copy, which carries the signature block.
        if ($request->status === EsignRequest::STATUS_COMPLETED && $request->signed_document_path) {
            $disk = Storage::disk('public');
            if ($disk->exists($request->signed_document_path)) {
                return response($disk->get($request->signed_document_path), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="signed-'.($request->document_number ?: 'document').'.pdf"',
                ]);
            }
        }

        try {
            $built = app(PdfGenerationServiceContract::class)->render(
                $request->document_type,
                (string) $request->document_id,
                array_filter([
                    'template_id' => $request->template_id,
                    'format' => 'pdf',
                ], fn ($v) => $v !== null),
            );
        } catch (\Throwable $e) {
            Log::error('EsignController: failed to render document for signing', [
                'esign_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'The document could not be prepared for signing.'], 422);
        }

        return response($built['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.($request->document_number ?: 'document').'.pdf"',
        ]);
    }

    public function sign(Request $request, string $token)
    {
        $esignRequest = $this->esign->findByToken($token);

        if (! $esignRequest) {
            return response()->json(['error' => 'Invalid or expired signing link.', 'code' => 'INVALID_TOKEN'], 404);
        }

        if ($esignRequest->status === EsignRequest::STATUS_COMPLETED) {
            return response()->json([
                'error' => 'This document has already been signed.',
                'code' => 'ALREADY_SIGNED',
            ], 409);
        }

        if (! $esignRequest->isOpen()) {
            return response()->json(['error' => 'This signing link has expired.', 'code' => 'EXPIRED'], 410);
        }

        $validated = $request->validate([
            'signature' => 'required|string',
            'method' => 'nullable|in:draw,type,upload',
            'signer_name' => 'nullable|string|max:255',
            'signer_email' => 'nullable|email|max:255',
            'date' => 'nullable|string|max:20',
            'typed_font' => 'nullable|string|max:60',
            'fields' => 'nullable|array|max:20',
            'fields.*.kind' => 'required|in:signature,date,name',
            'fields.*.page' => 'required|integer|min:1|max:1000',
            'fields.*.x' => 'required|numeric',
            'fields.*.y' => 'required|numeric',
            'fields.*.width' => 'required|numeric|min:1',
            'fields.*.height' => 'required|numeric|min:1',
        ]);

        try {
            $result = $this->esign->complete(
                $esignRequest,
                $validated,
                $request->ip(),
                substr((string) $request->userAgent(), 0, 1000),
            );

            return response()->json([
                'message' => 'Thank you — your signature has been recorded.',
                'data' => $result,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage(), 'code' => 'BAD_SIGNATURE'], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage(), 'code' => 'CLOSED'], 409);
        } catch (\Throwable $e) {
            Log::error('EsignController: signing failed', [
                'esign_request_id' => $esignRequest->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'The signature could not be recorded.', 'code' => 'SIGN_FAILED'], 422);
        }
    }

    /** The customer chose to sign on paper: accept the uploaded signed copy. */
    public function upload(Request $request, string $token)
    {
        $esignRequest = $this->esign->findByToken($token);

        if (! $esignRequest) {
            return response()->json(['error' => 'Invalid or expired signing link.', 'code' => 'INVALID_TOKEN'], 404);
        }

        if ($esignRequest->status === EsignRequest::STATUS_COMPLETED) {
            return response()->json([
                'error' => 'This document has already been signed.',
                'code' => 'ALREADY_SIGNED',
            ], 409);
        }

        if (! $esignRequest->isOpen()) {
            return response()->json(['error' => 'This signing link has expired.', 'code' => 'EXPIRED'], 410);
        }

        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'signer_name' => 'nullable|string|max:255',
            'signer_email' => 'nullable|email|max:255',
        ]);

        try {
            $result = $this->esign->uploadSignedCopy(
                $esignRequest,
                $validated['file'],
                $validated['signer_name'] ?? null,
                $validated['signer_email'] ?? null,
                $request->ip(),
                substr((string) $request->userAgent(), 0, 1000),
            );

            return response()->json([
                'message' => 'Thank you — your signed copy has been received.',
                'data' => $result,
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage(), 'code' => 'CLOSED'], 409);
        } catch (\Throwable $e) {
            Log::error('EsignController: uploaded signed copy failed', [
                'esign_request_id' => $esignRequest->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'The signed copy could not be uploaded.', 'code' => 'UPLOAD_FAILED'], 422);
        }
    }

    /** Minimal, non-sensitive summary of the signed document. */
    private function documentMeta(EsignRequest $request): array
    {
        $model = EsignService::DOCUMENT_TYPES[$request->document_type] ?? null;
        $record = $model ? $model::find($request->document_id) : null;

        return array_filter([
            'type' => $request->document_type,
            'number' => $request->document_number,
            'total' => $record->total ?? null,
            'currency' => $record->currency_code ?? null,
        ], fn ($v) => $v !== null);
    }
}
