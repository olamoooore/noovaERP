<?php

namespace Modules\OCRBookkeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\OCRBookkeeping\Jobs\ProcessOcrDocument;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\OcrProcessingService;

class OcrDocumentController extends Controller
{
    /**
     * Upload a single document
     * POST /api/ocr/documents/upload
     */
    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'max:10240', // 10MB max
                'mimes:jpg,jpeg,png,pdf',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $legalEntityId = $request->attributes->get('legal_entity_id') ?? 1;
        $entityBookId = $request->attributes->get('entity_book_id') ?? 1;

        $storageDisk = 'ocr-documents-local';
        $storedFilePath = null;
        $documentCreated = false;

        try {
            $file = $request->file('file');
            $fileName = $file->getClientOriginalName();

            // Duplicate upload detection
            $fileHash = hash_file('sha256', $file->getRealPath());

            $duplicate = OcrDocument::where('file_hash', $fileHash)
                ->whereNull('deleted_at')
                ->first();

            if ($duplicate) {
                $this->logOcrAction('duplicate_upload_blocked', $duplicate, [
                    'incoming_file_name' => $fileName,
                ]);

                return response()->json([
                    'message' => 'Duplicate OCR document upload detected.',
                    'data' => [
                        'existing_uuid' => $duplicate->uuid,
                        'file_name' => $duplicate->file_name,
                        'status' => $duplicate->status,
                    ],
                ], 409);
            }

            // Generate unique file path
            $fileExtension = $file->getClientOriginalExtension();
            $uniqueName = time().'_'.uniqid().'.'.$fileExtension;
            $filePath = date('Y/m/d').'/'.$uniqueName;

            // Store file (use local storage for now)
            $stored = Storage::disk($storageDisk)->put($filePath, file_get_contents($file->getRealPath()));

            if (! $stored) {
                return response()->json([
                    'message' => 'Failed to store file',
                ], 500);
            }

            $storedFilePath = $filePath;

            // Create document record
            $document = OcrDocument::create([
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_hash' => $fileHash,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'status' => 'PENDING',
                'legal_entity_id' => $legalEntityId,
                'entity_book_id' => $entityBookId,

                'created_by' => TenantUserHelper::getTenantUserId(),
            ]);

            $documentCreated = true;
            $storedFilePath = null;

            // Process OCR synchronously so it runs with tenant context
            try {
                ProcessOcrDocument::dispatchSync($document->id);
                $document->refresh();
            } catch (\Exception $e) {
                \Log::warning("OCR processing failed for document {$document->uuid}: ".$e->getMessage());
                // Document will be marked as EXTRACTION_FAILED by the job's failed() handler
                $document->refresh();
            }

            if ($document->hasFailed()) {
                $this->logOcrAction('upload_extraction_failed', $document, [
                    'error_message' => $document->error_message,
                ]);

                return $this->buildExtractionFailedResponse($document);
            }

            $this->logOcrAction('upload', $document, [
                'result' => 'success',
            ]);

            return response()->json([
                'message' => 'Document uploaded successfully',
                'data' => [
                    'uuid' => $document->uuid,
                    'file_name' => $document->file_name,
                    'status' => $document->status,
                    'error_message' => $document->error_message,
                    'created_at' => $document->created_at,
                ],
            ], 201);

        } catch (\Exception $e) {
            if ($storedFilePath && ! $documentCreated && Storage::disk($storageDisk)->exists($storedFilePath)) {
                Storage::disk($storageDisk)->delete($storedFilePath);
            }

            \Log::error('OCR document upload failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => TenantUserHelper::resolveTenantUserId(),
            ]);

            return response()->json([
                'message' => 'Upload failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload multiple documents in batch
     * POST /api/ocr/documents/batch-upload
     */
    public function batchUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'files' => 'required|array|min:1|max:20', // Max 20 files at once
            'files.*' => [
                'required',
                'file',
                'max:10240', // 10MB max per file
                'mimes:jpg,jpeg,png,pdf',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $legalEntityId = $request->attributes->get('legal_entity_id') ?? 1;
        $entityBookId = $request->attributes->get('entity_book_id') ?? 1;

        $uploadedDocuments = [];
        $failedUploads = [];
        $storageDisk = 'ocr-documents-local'; // Use local storage for now

        foreach ($request->file('files') as $index => $file) {
            try {
                $fileName = $file->getClientOriginalName();

                // Duplicate upload detection
                $fileHash = hash_file('sha256', $file->getRealPath());

                $duplicate = OcrDocument::where('file_hash', $fileHash)
                    ->whereNull('deleted_at')
                    ->first();

                if ($duplicate) {
                    $this->logOcrAction('batch_duplicate_upload_blocked', $duplicate, [
                        'incoming_file_name' => $fileName,
                    ]);

                    $failedUploads[] = [
                        'file_name' => $fileName,
                        'error' => 'Duplicate OCR document upload detected.',
                        'existing_uuid' => $duplicate->uuid,
                    ];

                    continue;
                }

                // Generate unique file path
                $fileExtension = $file->getClientOriginalExtension();
                $uniqueName = time().'_'.uniqid().'.'.$fileExtension;
                $filePath = date('Y/m/d').'/'.$uniqueName;

                // Store file
                $stored = Storage::disk($storageDisk)->put($filePath, file_get_contents($file->getRealPath()));

                if (! $stored) {
                    $failedUploads[] = [
                        'file_name' => $fileName,
                        'error' => 'Failed to store file',
                    ];

                    continue;
                }

                // Create document record
                $document = OcrDocument::create([
                    'file_path' => $filePath,
                    'file_name' => $fileName,
                    'file_hash' => $fileHash,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'status' => 'PENDING',
                    'legal_entity_id' => $legalEntityId,
                    'entity_book_id' => $entityBookId,
                    'created_by' => TenantUserHelper::getTenantUserId(),
                ]);

                // Process OCR synchronously
                try {
                    ProcessOcrDocument::dispatchSync($document->id);
                    $document->refresh();
                } catch (\Exception $jobError) {
                    \Log::warning("OCR processing failed for document {$document->uuid}: ".$jobError->getMessage());
                    $document->refresh();
                }

                if ($document->hasFailed()) {
                    $this->logOcrAction('batch_upload_extraction_failed', $document, [
                        'error_message' => $document->error_message ?: 'OCR extraction failed',
                    ]);

                    $failedUploads[] = [
                        'uuid' => $document->uuid,
                        'file_name' => $document->file_name,
                        'status' => $document->status,
                        'error' => $document->error_message ?: 'OCR extraction failed',
                    ];

                    continue;
                }

                $this->logOcrAction('batch_upload', $document, [
                    'result' => 'success',
                ]);

                $uploadedDocuments[] = [
                    'uuid' => $document->uuid,
                    'file_name' => $document->file_name,
                    'status' => $document->status,
                ];

            } catch (\Exception $e) {
                $failedUploads[] = [
                    'file_name' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Batch upload completed',
            'data' => [
                'uploaded' => $uploadedDocuments,
                'failed' => $failedUploads,
                'total_uploaded' => count($uploadedDocuments),
                'total_failed' => count($failedUploads),
            ],
        ], count($failedUploads) > 0 ? 207 : 201); // 207 Multi-Status if some failed
    }

    /**
     * List documents with filters and pagination
     * GET /api/ocr/documents
     */
    public function index(Request $request): JsonResponse
    {
        $query = OcrDocument::with(['vendor', 'createdBy', 'journalEntry'])
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc');

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('file_name', 'like', "%{$search}%")
                    ->orWhere('vendor_name', 'like', "%{$search}%")
                    ->orWhere('document_reference', 'like', "%{$search}%")
                    ->orWhere('total_amount', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Date range filter
        if ($request->has('start_date') && $request->start_date) {
            $query->whereDate('document_date', '>=', $request->start_date);
        }
        if ($request->has('end_date') && $request->end_date) {
            $query->whereDate('document_date', '<=', $request->end_date);
        }

        // Vendor filter
        if ($request->has('vendor_id') && $request->vendor_id) {
            $query->where('vendor_id', $request->vendor_id);
        }

        // Amount range filter
        if ($request->has('min_amount') && $request->min_amount) {
            $query->where('total_amount', '>=', $request->min_amount);
        }
        if ($request->has('max_amount') && $request->max_amount) {
            $query->where('total_amount', '<=', $request->max_amount);
        }

        $perPage = $request->input('per_page', 20);
        $documents = $query->paginate($perPage);

        return response()->json($documents);
    }

    /**
     * Get single document with details
     * GET /api/ocr/documents/{uuid}
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $document = OcrDocument::with(['vendor', 'createdBy', 'reviewedBy', 'postedBy', 'journalEntry'])
                ->where('uuid', $uuid)
                ->firstOrFail();

            return response()->json([
                'data' => $document,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Document not found',
            ], 404);
        } catch (\Exception $e) {
            \Log::error("OCR document show failed for uuid: {$uuid}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to load document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update document data (during review)
     * PUT /api/ocr/documents/{uuid}
     */
    public function update(string $uuid, Request $request): JsonResponse
    {
        $document = OcrDocument::where('uuid', $uuid)->firstOrFail();

        if (! $document->canBeEdited()) {
            return response()->json([
                'message' => 'Document cannot be edited in current status',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'vendor_id' => 'nullable|exists:vendors,id',
            'vendor_name' => 'nullable|string|max:255',
            'document_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'document_reference' => 'nullable|string|max:255',
            'currency_code' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'line_items' => 'nullable|array',
            'subtotal' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|array',
            'dimensions.department_id' => 'nullable|exists:departments,id',
            'dimensions.project_id' => 'nullable|exists:projects,id',
            'dimensions.location_id' => 'nullable|exists:locations,id',
            'updated_at' => 'nullable|string', // For optimistic locking
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Validate dimensions are active
        $dimensionService = app(\Modules\OCRBookkeeping\Services\DimensionValidationService::class);

        if ($request->has('dimensions')) {
            $dimensionValidation = $dimensionService->validate($request->dimensions);
            if (! $dimensionValidation['valid']) {
                return response()->json([
                    'message' => 'Dimension validation failed',
                    'errors' => $dimensionValidation['errors'],
                ], 422);
            }
        }

        // Validate line item dimensions
        if ($request->has('line_items')) {
            $lineItemValidation = $dimensionService->validateLineItemDimensions($request->line_items);
            if (! $lineItemValidation['valid']) {
                return response()->json([
                    'message' => 'Line item dimension validation failed',
                    'errors' => $lineItemValidation['errors'],
                ], 422);
            }
        }

        try {
            // Use optimistic locking if updated_at provided
            if ($request->has('updated_at')) {
                $document->updateWithLock($validator->validated(), $request->updated_at);
            } else {
                $document->update($validator->validated());
            }

            $document->update([
                'reviewed_by' => TenantUserHelper::getTenantUserId(),
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'message' => 'Document updated successfully',
                'data' => $document->fresh(),
            ]);

        } catch (\Modules\OCRBookkeeping\Exceptions\OcrConcurrentModificationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
                'context' => $e->getContext(),
            ], 409);
        }
    }

    /**
     * Delete document (soft delete)
     * DELETE /api/ocr/documents/{uuid}
     */
    public function destroy(string $uuid): JsonResponse
    {
        $document = OcrDocument::where('uuid', $uuid)->firstOrFail();

        try {
            // If document is posted, automatically unpost (reverse) it first
            if ($document->isPosted()) {
                \Log::info("Document {$uuid} is posted. Reversing journal entry before deletion.");
                $document->unpost();
            }

            // Soft-delete the document
            $document->delete();

            $this->logOcrAction('delete', $document, [
                'deleted_at' => now()->toDateTimeString(),
            ]);

            \Log::info('OCR document soft-deleted', ['uuid' => $uuid, 'deleted_at' => now()->toDateTimeString()]);

            return response()->json([
                'message' => 'Document deleted successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to delete document {$uuid}: ".$e->getMessage());

            return response()->json([
                'message' => 'Failed to delete document',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unpost a document and reverse its journal entry
     * POST /api/ocr/documents/{uuid}/unpost
     */
    public function unpost(string $uuid): JsonResponse
    {
        $document = OcrDocument::where('uuid', $uuid)->firstOrFail();

        if (! $document->isPosted()) {
            return response()->json([
                'message' => 'Only posted documents can be unposted',
            ], 422);
        }

        try {
            $document->unpost();

            $this->logOcrAction('unpost', $document->fresh());

            return response()->json([
                'message' => 'Document unposted successfully',
                'data' => $document->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to unpost document: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get vendor and account suggestions for a document
     * GET /api/ocr/documents/{uuid}/suggestions
     */
    public function suggestions(string $uuid): JsonResponse
    {
        $document = OcrDocument::where('uuid', $uuid)->firstOrFail();

        // Return empty suggestions for now (services not fully implemented)
        return response()->json([
            'data' => [
                'vendor_suggestions' => [],
                'account_suggestions' => [],
            ],
        ]);
    }

    /**
     * Get document file (image/PDF)
     * GET /api/ocr/documents/{uuid}/file
     */
    public function getFile(string $uuid): mixed
    {
        try {
            $document = OcrDocument::where('uuid', $uuid)->firstOrFail();

            $storageDisk = 'ocr-documents-local';

            if (! Storage::disk($storageDisk)->exists($document->file_path)) {
                \Log::error("File not found: {$document->file_path}");

                return response()->json([
                    'message' => 'File not found',
                    'path' => $document->file_path,
                ], 404);
            }

            $file = Storage::disk($storageDisk)->get($document->file_path);
            $mimeType = $document->mime_type ?? 'application/octet-stream';

            $this->logOcrAction('file_view', $document);

            return response($file, 200)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'inline; filename="'.$document->file_name.'"');

        } catch (\Exception $e) {
            \Log::error('Error serving file: '.$e->getMessage());

            return response()->json([
                'message' => 'Error serving file',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Post document to ledger
     * POST /api/ocr/documents/{uuid}/post
     */
    public function post(string $uuid): JsonResponse
    {
        try {
            $document = OcrDocument::where('uuid', $uuid)->firstOrFail();

            \Log::info('Attempting to post document', [
                'uuid' => $uuid,
                'status' => $document->status,
                'total_amount' => $document->total_amount,
                'document_date' => $document->document_date,
                'line_items' => $document->line_items,
            ]);

            if (! $document->canBePosted()) {
                \Log::warning('Document cannot be posted', [
                    'uuid' => $uuid,
                    'status' => $document->status,
                    'has_total' => $document->total_amount !== null,
                    'has_date' => $document->document_date !== null,
                ]);

                return response()->json([
                    'message' => 'Document is not ready to be posted',
                    'details' => [
                        'status' => $document->status,
                        'has_total_amount' => $document->total_amount !== null,
                        'has_document_date' => $document->document_date !== null,
                    ],
                ], 422);
            }

            $postingService = app(\Modules\OCRBookkeeping\Services\OcrPostingService::class);
            $journalEntry = $postingService->postDocument($document);

            $this->logOcrAction('post', $document, [
                'journal_entry_id' => $journalEntry->id,
                'entry_number' => $journalEntry->entry_number,
            ]);

            return response()->json([
                'message' => 'Document posted successfully',
                'data' => [
                    'document_uuid' => $document->uuid,
                    'journal_entry_id' => $journalEntry->id,
                    'entry_number' => $journalEntry->entry_number,
                    'entry_date' => $journalEntry->entry_date,
                    'total_debit' => $journalEntry->getTotalDebit(),
                    'total_credit' => $journalEntry->getTotalCredit(),
                    'is_balanced' => $journalEntry->is_balanced,
                ],
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Post endpoint error', [
                'uuid' => $uuid ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to post document',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Batch post multiple documents
     * POST /api/ocr/documents/batch-post
     */
    public function batchPost(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_uuids' => 'required|array|min:1|max:50',
            'document_uuids.*' => 'required|string|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $postingService = app(\Modules\OCRBookkeeping\Services\OcrPostingService::class);
            $result = $postingService->batchPost($request->document_uuids);

            $statusCode = $result['total_failed'] > 0 ? 207 : 201; // 207 Multi-Status if some failed

            return response()->json([
                'message' => 'Batch posting completed',
                'data' => $result,
            ], $statusCode);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Batch posting failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retry OCR extraction for a failed document
     * POST /api/ocr/documents/{uuid}/retry
     */
    public function retry(string $uuid): JsonResponse
    {
        $document = OcrDocument::where('uuid', $uuid)->firstOrFail();

        if ($document->status !== 'EXTRACTION_FAILED') {
            return response()->json([
                'message' => 'Only failed documents can be retried.',
                'status' => $document->status,
            ], 422);
        }

        if ($serviceUnavailableResponse = $this->ensureOcrServiceAvailable()) {
            return $serviceUnavailableResponse;
        }

        try {
            $document->resetForRetry();

            ProcessOcrDocument::dispatchSync($document->id);
            $document->refresh();

            $this->logOcrAction('retry', $document, [
                'result' => 'success',
            ]);

            return response()->json([
                'message' => 'Document reprocessed successfully',
                'data' => [
                    'uuid' => $document->uuid,
                    'status' => $document->status,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::warning("OCR retry failed for document {$uuid}: ".$e->getMessage());
            $document->refresh();

            $this->logOcrAction('retry_failed', $document, [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Retry failed',
                'error' => $e->getMessage(),
                'data' => [
                    'uuid' => $document->uuid,
                    'status' => $document->status,
                ],
            ], 500);
        }
    }

    /**
     * Promote OCR document to canonical vendor bill draft.
     * POST /api/ocr/documents/{uuid}/promote/bill
     */
    public function promoteToBill(string $uuid): JsonResponse
    {
        $document = OcrDocument::where('uuid', $uuid)->firstOrFail();

        try {
            $promotionService = app(\App\Services\OcrPromotionService::class);
            $result = $promotionService->promoteToBill($document->id);

            $this->logOcrAction('promote_to_bill', $document, [
                'result' => 'success',
            ]);

            return response()->json([
                'message' => 'Document promoted to bill draft.',
                'data' => $result,
            ], 201);
        } catch (\Exception $e) {
            $this->logOcrAction('promote_to_bill_failed', $document, [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Promotion failed: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Promote OCR document to canonical customer invoice draft.
     * POST /api/ocr/documents/{uuid}/promote/invoice
     */
    public function promoteToInvoice(string $uuid): JsonResponse
    {
        $document = OcrDocument::where('uuid', $uuid)->firstOrFail();

        try {
            $promotionService = app(\App\Services\OcrPromotionService::class);
            $result = $promotionService->promoteToInvoice($document->id);

            $this->logOcrAction('promote_to_invoice', $document, [
                'result' => 'success',
            ]);

            return response()->json([
                'message' => 'Document promoted to invoice draft.',
                'data' => $result,
            ], 201);
        } catch (\Exception $e) {
            $this->logOcrAction('promote_to_invoice_failed', $document, [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Promotion failed: '.$e->getMessage(),
            ], 422);
        }
    }

    private function logOcrAction(string $action, ?OcrDocument $document = null, array $extra = []): void
    {
        \Log::info('OCR action audit', array_merge([
            'action' => $action,
            'document_uuid' => $document?->uuid,
            'file_name' => $document?->file_name,
            'status' => $document?->status,
            'user_id' => TenantUserHelper::resolveTenantUserId(),
        ], $extra));
    }

    private function ensureOcrServiceAvailable(): ?JsonResponse
    {
        $ocrService = app(OcrProcessingService::class);

        if ($ocrService->isServiceAvailable()) {
            return null;
        }

        $serviceStatus = $ocrService->getServiceStatus();

        return response()->json([
            'message' => 'OCR service is unavailable. Start the OCR service and try again.',
            'error' => $serviceStatus['error'] ?? 'OCR service health check failed.',
            'service_status' => $serviceStatus['status'] ?? 'unreachable',
        ], 503);
    }

    private function buildExtractionFailedResponse(OcrDocument $document): JsonResponse
    {
        return response()->json([
            'message' => 'Document uploaded, but OCR extraction failed.',
            'error' => $document->error_message ?: 'OCR extraction failed.',
            'data' => [
                'uuid' => $document->uuid,
                'file_name' => $document->file_name,
                'status' => $document->status,
                'error_message' => $document->error_message,
                'created_at' => $document->created_at,
            ],
        ], 422);
    }
}
