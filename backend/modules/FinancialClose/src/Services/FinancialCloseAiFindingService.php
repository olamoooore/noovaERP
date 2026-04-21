<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\AiFinding;
use Modules\FinancialClose\Models\AiFeedback;
use Modules\FinancialClose\Models\AuditLog;
use Modules\FinancialClose\Services\AiOutputValidationService;

class FinancialCloseAiFindingService
{
    protected AiOutputValidationService $validator;

    public function __construct(AiOutputValidationService $validator)
    {
        $this->validator = $validator;
    }

    /**
     * Get all findings for a review
     */
    public function listFindings(string $tenantId, string $reviewId, array $filters = []): array
    {
        $query = AiFinding::where('tenant_id', $tenantId)
            ->where('review_id', $reviewId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['risk_status'])) {
            $query->where('risk_status', $filters['risk_status']);
        }

        return $query->orderBy('created_at', 'desc')->get()->toArray();
    }

    /**
     * Generate and persist a new AI finding
     * All AI outputs must use structured contracts
     */
    public function createFinding(string $tenantId, string $reviewId, string $entityId, string $accountCode, array $aiOutput): ?AiFinding
    {
        try {
            $validated = $this->validator->validate($aiOutput, [
                'finding_type',
                'description',
                'confidence',
                'risk_status',
            ]);
        } catch (\InvalidArgumentException $e) {
            Log::warning('FinancialClose: malformed AI output rejected', [
                'review_id' => $reviewId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$this->validator->meetsConfidenceThreshold((float) $validated['confidence'])) {
            Log::info('FinancialClose: AI finding below confidence threshold, not persisted', [
                'review_id' => $reviewId,
                'confidence' => $validated['confidence'],
            ]);
            return null;
        }

        return AiFinding::create([
            'tenant_id' => $tenantId,
            'review_id' => $reviewId,
            'entity_id' => $entityId,
            'account_code' => $accountCode,
            'finding_type' => $this->validator->sanitize($validated['finding_type']),
            'status' => 'open',
            'risk_status' => $validated['risk_status'],
            'confidence' => $validated['confidence'],
            'description' => $this->validator->sanitize($validated['description']),
            'structured_output' => $validated,
        ]);
    }

    /**
     * Accept a finding
     */
    public function acceptFinding(string $tenantId, string $findingId, string $userId): bool
    {
        $finding = AiFinding::where('tenant_id', $tenantId)
            ->where('id', $findingId)
            ->first();

        if (!$finding || $finding->status !== 'open') {
            return false;
        }

        $finding->update([
            'status' => 'accepted',
            'accepted_by' => $userId,
            'accepted_at' => now(),
        ]);

        $this->writeFeedback($tenantId, $findingId, $userId, 'accepted');
        $this->writeAuditLog($tenantId, $userId, 'finding_accepted', $findingId);

        return true;
    }

    /**
     * Edit a finding
     */
    public function editFinding(string $tenantId, string $findingId, string $userId, array $editedOutput): bool
    {
        $finding = AiFinding::where('tenant_id', $tenantId)
            ->where('id', $findingId)
            ->first();

        if (!$finding || $finding->status !== 'open') {
            return false;
        }

        $finding->update(['status' => 'edited']);

        $this->writeFeedback($tenantId, $findingId, $userId, 'edited', null, $editedOutput);
        $this->writeAuditLog($tenantId, $userId, 'finding_edited', $findingId);

        return true;
    }

    /**
     * Dismiss a finding
     */
    public function dismissFinding(string $tenantId, string $findingId, string $userId, ?string $comment = null): bool
    {
        $finding = AiFinding::where('tenant_id', $tenantId)
            ->where('id', $findingId)
            ->first();

        if (!$finding || $finding->status !== 'open') {
            return false;
        }

        $finding->update([
            'status' => 'dismissed',
            'dismissed_by' => $userId,
            'dismissed_at' => now(),
        ]);

        $this->writeFeedback($tenantId, $findingId, $userId, 'dismissed', $comment);
        $this->writeAuditLog($tenantId, $userId, 'finding_dismissed', $findingId);

        return true;
    }

    /**
     * Escalate a finding
     */
    public function escalateFinding(string $tenantId, string $findingId, string $userId, ?string $comment = null): bool
    {
        $finding = AiFinding::where('tenant_id', $tenantId)
            ->where('id', $findingId)
            ->first();

        if (!$finding || $finding->status !== 'open') {
            return false;
        }

        $finding->update(['status' => 'escalated']);

        $this->writeFeedback($tenantId, $findingId, $userId, 'escalated', $comment);
        $this->writeAuditLog($tenantId, $userId, 'finding_escalated', $findingId);

        return true;
    }

    /**
     * Write AI feedback record
     */
    protected function writeFeedback(string $tenantId, string $findingId, string $userId, string $action, ?string $comment = null, ?array $editedOutput = null): void
    {
        AiFeedback::create([
            'tenant_id' => $tenantId,
            'finding_id' => $findingId,
            'user_id' => $userId,
            'action' => $action,
            'comment' => $comment,
            'edited_output' => $editedOutput,
        ]);
    }

    /**
     * Write audit log entry
     */
    protected function writeAuditLog(string $tenantId, string $actorId, string $action, string $entityId): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => 'ai_finding',
            'entity_id' => $entityId,
        ]);
    }

    /**
     * Filter AI explanation based on user permissions
     * Explanations must be permission-aware
     */
    public function getFilteredExplanation(string $tenantId, string $findingId, string $userId): ?array
    {
        $finding = AiFinding::where('tenant_id', $tenantId)
            ->where('id', $findingId)
            ->first();

        if (!$finding) {
            return null;
        }

        $output = $finding->structured_output ?? [];

        // Remove sensitive fields for non-privileged users
        $sensitiveFields = ['raw_model_output', 'internal_score', 'debug_info'];
        foreach ($sensitiveFields as $field) {
            unset($output[$field]);
        }

        return [
            'finding_id' => $finding->id,
            'description' => $finding->description,
            'risk_status' => $finding->risk_status,
            'confidence' => $finding->confidence,
            'structured_output' => $output,
        ];
    }
}