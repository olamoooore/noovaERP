<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\AuditLog;
use Modules\FinancialClose\Models\EvidenceFreezeSnapshot;
use Modules\FinancialClose\Models\EvidenceVersionHistory;
use Modules\FinancialClose\Models\Signoff;
use Modules\FinancialClose\Models\SignoffAttestation;
use Modules\FinancialClose\Models\SignoffReopenEvent;
use Modules\FinancialClose\Models\SupportingEvidence;

class SignoffDefensibilityService
{
    /**
     * Record a sign-off attestation
     */
    public function recordAttestation(string $tenantId, string $signoffId, string $userId, string $attestationText): ?SignoffAttestation
    {
        $signoff = Signoff::where('tenant_id', $tenantId)
            ->where('id', $signoffId)
            ->first();

        if (! $signoff) {
            return null;
        }

        return SignoffAttestation::create([
            'tenant_id' => $tenantId,
            'signoff_id' => $signoffId,
            'attested_by' => $userId,
            'status' => 'completed',
            'attestation_text' => $attestationText,
            'attested_at' => now(),
        ]);
    }

    /**
     * Reopen a sign-off with authorization
     */
    public function reopenSignoff(string $tenantId, string $signoffId, string $reopenedBy, string $authorizedBy, string $reason): bool
    {
        $signoff = Signoff::where('tenant_id', $tenantId)
            ->where('id', $signoffId)
            ->first();

        if (! $signoff) {
            return false;
        }

        // Reopen authorization required
        if ($reopenedBy === $authorizedBy) {
            Log::warning('FinancialClose: signoff reopen must be authorized by a different user', [
                'signoff_id' => $signoffId,
                'reopened_by' => $reopenedBy,
            ]);

            return false;
        }

        $signoff->update(['status' => 'returned']);

        SignoffReopenEvent::create([
            'tenant_id' => $tenantId,
            'signoff_id' => $signoffId,
            'reopened_by' => $reopenedBy,
            'authorized_by' => $authorizedBy,
            'reason' => $reason,
            'reopened_at' => now(),
        ]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $reopenedBy,
            'action' => 'signoff_reopened',
            'entity_type' => 'signoff',
            'entity_id' => $signoffId,
        ]);

        return true;
    }

    /**
     * Freeze evidence at the point of sign-off
     * Evidence freeze preserves history
     */
    public function freezeEvidence(string $tenantId, string $signoffId, string $frozenBy): int
    {
        $signoff = Signoff::where('tenant_id', $tenantId)
            ->where('id', $signoffId)
            ->first();

        if (! $signoff) {
            return 0;
        }

        $evidence = SupportingEvidence::where('tenant_id', $tenantId)
            ->where('review_id', $signoff->review_id)
            ->get();

        $frozen = 0;

        foreach ($evidence as $item) {
            EvidenceFreezeSnapshot::create([
                'tenant_id' => $tenantId,
                'signoff_id' => $signoffId,
                'evidence_id' => $item->id,
                'frozen_by' => $frozenBy,
                'frozen_at' => now(),
                'snapshot' => $item->toArray(),
            ]);
            $frozen++;
        }

        return $frozen;
    }

    /**
     * Record a new version of evidence
     */
    public function recordEvidenceVersion(string $tenantId, string $evidenceId, string $filePath, string $uploadedBy): ?EvidenceVersionHistory
    {
        $latestVersion = EvidenceVersionHistory::where('tenant_id', $tenantId)
            ->where('evidence_id', $evidenceId)
            ->orderBy('version_number', 'desc')
            ->first();

        $versionNumber = $latestVersion ? $latestVersion->version_number + 1 : 1;

        return EvidenceVersionHistory::create([
            'tenant_id' => $tenantId,
            'evidence_id' => $evidenceId,
            'version_number' => $versionNumber,
            'file_path' => $filePath,
            'uploaded_by' => $uploadedBy,
        ]);
    }
}
