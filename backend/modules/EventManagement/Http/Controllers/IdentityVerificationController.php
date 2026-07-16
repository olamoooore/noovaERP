<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Modules\EventManagement\Models\IdentityVerification;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\FraudDetectionService;

class IdentityVerificationController extends Controller
{
    protected FraudDetectionService $fraudDetectionService;

    protected AuditService $auditService;

    public function __construct(
        FraudDetectionService $fraudDetectionService,
        AuditService $auditService
    ) {
        $this->fraudDetectionService = $fraudDetectionService;
        $this->auditService = $auditService;
    }

    /**
     * Get verification status for a customer
     */
    public function getVerificationStatus(int $customerId): JsonResponse
    {
        $tenantId = request()->header('X-Tenant-ID', 'default');

        $verification = IdentityVerification::forTenant($tenantId)
            ->where('customer_id', $customerId)
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $verification) {
            return response()->json([
                'customer_id' => $customerId,
                'verification_level' => 'none',
                'verification_status' => 'none',
            ]);
        }

        return response()->json([
            'customer_id' => $verification->customer_id,
            'verification_level' => $verification->verification_level,
            'verification_status' => $verification->verification_status,
            'submitted_at' => $verification->created_at?->toISOString(),
            'verified_at' => $verification->verified_at?->toISOString(),
            'expires_at' => $verification->expires_at?->toISOString(),
            'rejection_reason' => $verification->rejection_reason,
            'documents_submitted' => $verification->getDocumentTypes(),
            'verification_metadata' => $verification->verification_metadata,
        ]);
    }

    /**
     * Check if verification is required for a specific context
     */
    public function checkVerificationRequirement(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer',
            'event_id' => 'nullable|integer',
            'booking_amount' => 'nullable|numeric|min:0',
            'risk_score' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenantId = $request->header('X-Tenant-ID', 'default');
        $customerId = $request->input('customer_id');
        $riskScore = $request->input('risk_score', 0);

        // Get current verification status
        $currentVerification = IdentityVerification::forTenant($tenantId)
            ->where('customer_id', $customerId)
            ->where('verification_status', 'verified')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at', 'desc')
            ->first();

        $currentLevel = $currentVerification ? $currentVerification->verification_level : 'none';

        // Determine if verification is required
        $requiresVerification = $this->fraudDetectionService->requiresIdentityVerification(
            $tenantId,
            $customerId,
            $riskScore
        );

        if (! $requiresVerification) {
            return response()->json([
                'required' => false,
                'current_level' => $currentLevel,
                'required_level' => 'none',
            ]);
        }

        // Determine required level based on risk score
        $requiredLevel = $riskScore >= 80 ? 'full' : 'basic';

        // Check if current verification meets requirements
        $meetsRequirements = $currentVerification &&
            ($currentVerification->verification_level === $requiredLevel ||
                ($requiredLevel === 'basic' && $currentVerification->verification_level === 'full'));

        if ($meetsRequirements) {
            return response()->json([
                'required' => false,
                'current_level' => $currentLevel,
                'required_level' => $requiredLevel,
            ]);
        }

        // Get risk factors for context
        $riskFactors = $this->getRiskFactors($tenantId, $customerId, $request->all());

        return response()->json([
            'required' => true,
            'current_level' => $currentLevel,
            'required_level' => $requiredLevel,
            'reason' => $this->getVerificationReason($riskScore, $riskFactors),
            'risk_factors' => $riskFactors,
        ]);
    }

    /**
     * Submit verification documents and information
     */
    public function submitVerification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer',
            'verification_level' => 'required|in:basic,full',
            'personal_info' => 'required|json',
            'documents.government_id' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'documents.selfie' => 'required_if:verification_level,full|file|mimes:jpg,jpeg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenantId = $request->header('X-Tenant-ID', 'default');
        $customerId = $request->input('customer_id');
        $verificationLevel = $request->input('verification_level');
        $personalInfo = json_decode($request->input('personal_info'), true);

        try {
            // Store uploaded documents
            $documentPaths = [];
            $documentMetadata = [];

            if ($request->hasFile('documents.government_id')) {
                $file = $request->file('documents.government_id');
                $path = $this->storeVerificationDocument($tenantId, $customerId, $file, 'government_id');
                $documentPaths['government_id'] = $path;
                $documentMetadata['government_id'] = [
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ];
            }

            if ($verificationLevel === 'full' && $request->hasFile('documents.selfie')) {
                $file = $request->file('documents.selfie');
                $path = $this->storeVerificationDocument($tenantId, $customerId, $file, 'selfie');
                $documentPaths['selfie'] = $path;
                $documentMetadata['selfie'] = [
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ];
            }

            // Create verification record
            $verification = IdentityVerification::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'verification_level' => $verificationLevel,
                'verification_status' => 'pending',
                'personal_info' => $personalInfo,
                'document_paths' => $documentPaths,
                'document_metadata' => $documentMetadata,
                'submitted_at' => now(),
                'verification_metadata' => [
                    'submission_ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'submission_method' => 'customer_portal',
                ],
            ]);

            // Log the submission
            $this->auditService->logActivity(
                'identity_verification_submitted',
                'identity_verification',
                $verification->id,
                [
                    'customer_id' => $customerId,
                    'verification_level' => $verificationLevel,
                    'documents_submitted' => array_keys($documentPaths),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Verification submitted successfully',
                'verification_id' => $verification->id,
                'status' => 'pending',
                'estimated_processing_time' => '1-2 business days',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit verification',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resubmit verification after rejection or expiry
     */
    public function resubmitVerification(int $customerId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'verification_level' => 'required|in:basic,full',
            'personal_info' => 'required|json',
            'documents.government_id' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'documents.selfie' => 'required_if:verification_level,full|file|mimes:jpg,jpeg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenantId = $request->header('X-Tenant-ID', 'default');

        // Mark previous verification as superseded
        IdentityVerification::forTenant($tenantId)
            ->where('customer_id', $customerId)
            ->whereIn('verification_status', ['pending', 'rejected', 'expired'])
            ->update(['verification_status' => 'superseded']);

        // Submit new verification
        $request->merge(['customer_id' => $customerId]);

        return $this->submitVerification($request);
    }

    /**
     * Get verification history for a customer
     */
    public function getVerificationHistory(int $customerId): JsonResponse
    {
        $tenantId = request()->header('X-Tenant-ID', 'default');

        $verifications = IdentityVerification::forTenant($tenantId)
            ->where('customer_id', $customerId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($verification) {
                return [
                    'id' => $verification->id,
                    'verification_level' => $verification->verification_level,
                    'verification_status' => $verification->verification_status,
                    'submitted_at' => $verification->created_at?->toISOString(),
                    'verified_at' => $verification->verified_at?->toISOString(),
                    'expires_at' => $verification->expires_at?->toISOString(),
                    'rejection_reason' => $verification->rejection_reason,
                    'documents_submitted' => $verification->getDocumentTypes(),
                ];
            });

        return response()->json($verifications);
    }

    /**
     * Upload additional documents for existing verification
     */
    public function uploadAdditionalDocuments(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer',
            'documents' => 'required|array',
            'documents.*' => 'file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenantId = $request->header('X-Tenant-ID', 'default');
        $customerId = $request->input('customer_id');

        // Find pending verification
        $verification = IdentityVerification::forTenant($tenantId)
            ->where('customer_id', $customerId)
            ->where('verification_status', 'pending')
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $verification) {
            return response()->json([
                'success' => false,
                'message' => 'No pending verification found',
            ], 404);
        }

        try {
            $documentPaths = $verification->document_paths ?? [];
            $documentMetadata = $verification->document_metadata ?? [];

            foreach ($request->file('documents') as $type => $file) {
                $path = $this->storeVerificationDocument($tenantId, $customerId, $file, $type);
                $documentPaths[$type] = $path;
                $documentMetadata[$type] = [
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ];
            }

            $verification->update([
                'document_paths' => $documentPaths,
                'document_metadata' => $documentMetadata,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Additional documents uploaded successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload documents',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel pending verification
     */
    public function cancelVerification(int $customerId): JsonResponse
    {
        $tenantId = request()->header('X-Tenant-ID', 'default');

        $verification = IdentityVerification::forTenant($tenantId)
            ->where('customer_id', $customerId)
            ->where('verification_status', 'pending')
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $verification) {
            return response()->json([
                'success' => false,
                'message' => 'No pending verification found',
            ], 404);
        }

        $verification->update(['verification_status' => 'cancelled']);

        $this->auditService->logActivity(
            'identity_verification_cancelled',
            'identity_verification',
            $verification->id,
            ['customer_id' => $customerId]
        );

        return response()->json([
            'success' => true,
            'message' => 'Verification cancelled successfully',
        ]);
    }

    /**
     * Get verification requirements
     */
    public function getVerificationRequirements(): JsonResponse
    {
        return response()->json([
            'basic' => [
                'documents_required' => ['government_id'],
                'processing_time' => '1-2 business days',
                'validity_period' => '1 year',
            ],
            'full' => [
                'documents_required' => ['government_id', 'selfie'],
                'processing_time' => '1-2 business days',
                'validity_period' => '2 years',
            ],
        ]);
    }

    /**
     * Get upload requirements and limits
     */
    public function getUploadRequirements(): JsonResponse
    {
        return response()->json([
            'max_file_size' => 10 * 1024 * 1024, // 10MB
            'allowed_formats' => ['jpg', 'jpeg', 'png', 'pdf'],
            'max_files_per_type' => 1,
            'document_types' => [
                'government_id' => [
                    'required' => true,
                    'description' => 'Government-issued photo ID (passport, driver\'s license, national ID)',
                    'examples' => ['Passport', 'Driver\'s License', 'National ID Card'],
                ],
                'selfie' => [
                    'required' => false,
                    'description' => 'Selfie holding your ID document next to your face',
                    'examples' => ['Clear selfie with ID visible'],
                ],
            ],
        ]);
    }

    /**
     * Validate document before upload
     */
    public function validateDocument(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'document_type' => 'required|in:government_id,selfie',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'valid' => false,
                'errors' => $validator->errors()->all(),
            ]);
        }

        $file = $request->file('document');
        $documentType = $request->input('document_type');

        $errors = [];
        $warnings = [];

        // File size validation
        if ($file->getSize() > 10 * 1024 * 1024) {
            $errors[] = 'File size exceeds 10MB limit';
        }

        // File type validation
        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
        if (! in_array($file->getMimeType(), $allowedMimes)) {
            $errors[] = 'Invalid file format. Please use JPG, PNG, or PDF';
        }

        // Document type specific validation
        if ($documentType === 'selfie' && $file->getMimeType() === 'application/pdf') {
            $errors[] = 'Selfie must be an image file (JPG or PNG)';
        }

        // File size warnings
        if ($file->getSize() < 100 * 1024) {
            $warnings[] = 'File size is very small. Please ensure the image is clear and readable';
        }

        return response()->json([
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ]);
    }

    /**
     * Get customer verification statistics
     */
    public function getCustomerVerificationStats(int $customerId): JsonResponse
    {
        $tenantId = request()->header('X-Tenant-ID', 'default');

        $verifications = IdentityVerification::forTenant($tenantId)
            ->where('customer_id', $customerId)
            ->get();

        $stats = [
            'total_submissions' => $verifications->count(),
            'successful_verifications' => $verifications->where('verification_status', 'verified')->count(),
            'rejected_verifications' => $verifications->where('verification_status', 'rejected')->count(),
            'current_status' => 'none',
            'last_verification_date' => null,
            'next_expiry_date' => null,
        ];

        $currentVerification = $verifications
            ->where('verification_status', 'verified')
            ->where('expires_at', '>', now())
            ->sortByDesc('created_at')
            ->first();

        if ($currentVerification) {
            $stats['current_status'] = $currentVerification->verification_level;
            $stats['last_verification_date'] = $currentVerification->verified_at?->toISOString();
            $stats['next_expiry_date'] = $currentVerification->expires_at?->toISOString();
        }

        return response()->json($stats);
    }

    /**
     * Store verification document securely
     */
    private function storeVerificationDocument(string $tenantId, int $customerId, $file, string $documentType): string
    {
        $filename = sprintf(
            '%s_%s_%s_%s.%s',
            $tenantId,
            $customerId,
            $documentType,
            now()->format('Y-m-d_H-i-s'),
            $file->getClientOriginalExtension()
        );

        $path = "verification-documents/{$tenantId}/{$customerId}/{$filename}";

        Storage::disk('private')->put($path, file_get_contents($file));

        return $path;
    }

    /**
     * Get risk factors for verification requirement
     */
    private function getRiskFactors(string $tenantId, int $customerId, array $context): array
    {
        $riskFactors = [];

        // Check velocity
        $velocity = $this->fraudDetectionService->monitorCustomerVelocity($tenantId, $customerId, 60);
        if ($velocity['is_suspicious']) {
            $riskFactors[] = 'velocity_exceeded';
        }

        // Check patterns
        $patterns = $this->fraudDetectionService->detectSuspiciousPatterns($tenantId, array_merge($context, [
            'customer_id' => $customerId,
        ]));

        if (! empty($patterns)) {
            $riskFactors[] = 'suspicious_pattern';
        }

        return $riskFactors;
    }

    /**
     * Get verification reason based on risk score and factors
     */
    private function getVerificationReason(float $riskScore, array $riskFactors): string
    {
        if ($riskScore >= 80) {
            return 'High-risk transaction requires full identity verification';
        }

        if (in_array('velocity_exceeded', $riskFactors)) {
            return 'Multiple booking attempts detected - verification required for security';
        }

        if (in_array('suspicious_pattern', $riskFactors)) {
            return 'Unusual booking behavior detected - verification required';
        }

        return 'Identity verification required for this booking';
    }
}
